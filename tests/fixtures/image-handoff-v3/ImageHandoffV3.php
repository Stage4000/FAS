<?php
declare(strict_types=1);
/** INACTIVE: no application caller, automatic migration, DB opening, network or encoder. */
require_once __DIR__.'/ImageSnapshotV2.php';
final class ImageContentRejected extends RuntimeException {}
final class ImageHandoffV3
{
    private static function rows(PDO $db,string $sql,array $parameters=[]):array {
        $q=$db->prepare($sql);$q->execute($parameters);return $q->fetchAll(PDO::FETCH_NUM);
    }
    private static function gate(PDO $db):void {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite' || $db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION || $db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES) || $db->inTransaction()) throw new RuntimeException('Unowned or incompatible connection');
        if (self::rows($db,'PRAGMA ignore_check_constraints')!==[[0]]) throw new RuntimeException('CHECK enforcement required');
        if (self::rows($db,"SELECT 1 FROM temp.sqlite_master WHERE type='trigger' OR name='products' OR name LIKE 'image_%' OR name LIKE 'pragma_%' LIMIT 1")) throw new RuntimeException('TEMP schema collision');
        if (self::rows($db,"SELECT 1 FROM main.sqlite_master WHERE name LIKE 'pragma_%' LIMIT 1")) throw new RuntimeException('Reserved schema collision');
    }
    private static function manifest():array {
        // Fixed, reviewed companion generated from exact v2 reference; not user-controlled SQL.
        $raw=file_get_contents(__DIR__.'/schema-manifest.json');
        if ($raw===false || hash('sha256',$raw)!==self::MANIFEST_SHA256) throw new RuntimeException('Manifest differs from reviewed version');
        return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }
    private static function catalog(PDO $db,array $m):void {
        $actual=self::rows($db,"SELECT type,name,sql FROM main.sqlite_master WHERE tbl_name='products' AND type IN ('table','index') ORDER BY type,name");
        if ($actual!==$m['catalog']) throw new RuntimeException('Catalog definition/index drift');
    }
    private static function installed(PDO $db,array $m):void {
        self::catalog($db,$m);
        $actual=[];foreach(self::rows($db,"SELECT name,sql FROM main.sqlite_master WHERE name LIKE 'image_%' AND sql IS NOT NULL") as [$name,$sql]) $actual[$name]=$sql;
        ksort($actual,SORT_STRING);$expected=$m['storage'];ksort($expected,SORT_STRING);
        if ($actual!==$expected) throw new RuntimeException('Handoff storage/trigger drift');
        $triggers=array_column(self::rows($db,"SELECT name FROM main.sqlite_master WHERE type='trigger' ORDER BY name"),0);
        $expectedTriggers=array_values(array_filter(array_keys($expected),fn($name)=>str_starts_with($name,'image_product_dirty_')));sort($expectedTriggers,SORT_STRING);
        if($triggers!==$expectedTriggers) throw new RuntimeException('Unknown global trigger');
        if(self::rows($db,'SELECT singleton,version,contract FROM main.image_handoff_version')!==[[1,3,'quarantine-v3']]) throw new RuntimeException('Contract version mismatch');
    }
    private static function rollback(PDO $db,Throwable $original):never {
        // Preserve uncertainty if commit failed after SQLite committed. Never auto-retry.
        try { if($db->inTransaction() && !$db->rollBack()) throw new RuntimeException('Rollback returned false'); } catch(Throwable $cleanup) { throw new RuntimeException('Rollback unconfirmed; reconcile before retry',0,$original); }
        throw $original;
    }
    public static function migrate(PDO $db,mixed $baselineLimit):array {
        self::gate($db);if(!is_int($baselineLimit)||$baselineLimit<0||$baselineLimit>100000) throw new InvalidArgumentException('Invalid baseline bound');
        $m=self::manifest();if(!$db->beginTransaction()) throw new RuntimeException('Transaction start unconfirmed');
        try {
            self::catalog($db,$m);
            if(self::rows($db,"SELECT 1 FROM main.sqlite_master WHERE name LIKE 'image_%' OR type='trigger' LIMIT 1")) throw new RuntimeException('Fresh install only');
            $count=self::rows($db,'SELECT count(*) FROM main.products')[0][0];if(!is_int($count)||$count>$baselineLimit) throw new RuntimeException('Baseline bound exceeded');
            if(self::rows($db,"SELECT 1 FROM main.products WHERE id<1 LIMIT 1")) throw new RuntimeException('Invalid baseline identity');
            foreach($m['ddl'] as $sql) $db->exec($sql);
            $db->exec("INSERT INTO main.image_handoff_version VALUES(1,3,'quarantine-v3')");$db->exec('INSERT INTO main.image_handoff_cursor VALUES(1,0,0)');self::installed($db,$m);
            $db->exec('INSERT INTO main.image_product_dirty(product_id,revision,deleted) SELECT id,1,0 FROM main.products');
            if(self::rows($db,'SELECT count(*) FROM main.image_product_dirty')[0][0]!==$count) throw new RuntimeException('Baseline mismatch');
            if(!$db->commit()) throw new RuntimeException('Commit unconfirmed; reconcile before retry');return ['seeded'=>$count];
        } catch(Throwable $error) { self::rollback($db,$error); }
    }
    private static function snapshot(PDO $db,mixed $id,mixed $revision,mixed $deleted):array {
        if(!is_int($id)||$id<1||!is_int($revision)||$revision<1||!in_array($deleted,[0,1],true)) throw new RuntimeException('Invalid marker');
        $bounds=self::rows($db,'SELECT typeof(image_url),length(CAST(image_url AS BLOB)),typeof(images),length(CAST(images AS BLOB)) FROM main.products WHERE id=?',[$id]);
        if($deleted && $bounds)throw new RuntimeException('Live row contradicts tombstone');
        if($bounds) {
            [$a,$an,$b,$bn]=$bounds[0];if(!in_array($a,['text','null'],true)||!in_array($b,['text','null'],true)||($an??0)>2048||($bn??0)>131072) throw new ImageContentRejected('field_bounds');
        }
        $row=self::rows($db,'SELECT image_url,images FROM main.products WHERE id=?',[$id]);
        if($deleted) { if($row) throw new RuntimeException('Live row contradicts tombstone');return ImageSnapshotV2::encode($id,$revision,true); }
        if(count($row)!==1) throw new RuntimeException('Missing live product');try {return ImageSnapshotV2::encode($id,$revision,false,$row[0][0],$row[0][1]);} catch(JsonException $e) {if($e->getCode()===JSON_ERROR_UTF8)throw new ImageContentRejected('invalid_utf8',0,$e);throw $e;}
    }
    private static function cursor(PDO $db,int $id):void {
        $q=$db->prepare('UPDATE main.image_handoff_cursor SET last_id=? WHERE singleton=1');$q->execute([$id]);if($q->rowCount()!==1)throw new RuntimeException('Cursor write failed');
    }
    private static function quarantine(PDO $db,array $m,int $id,int $revision):string {
        self::gate($db);if(!$db->beginTransaction())throw new RuntimeException('Transaction start unconfirmed');
        try {
            self::installed($db,$m);
            $row=self::rows($db,'SELECT revision,deleted,acknowledged_revision FROM main.image_product_dirty WHERE product_id=?',[$id]);
            if(!$row || $row[0][0]!==$revision || $row[0][2]>=$revision){if(!$db->commit())throw new RuntimeException('Commit unconfirmed');return 'changed';}
            // A handoff at this exact now-invalid revision signals integrity drift, not poison.
            if(self::rows($db,'SELECT 1 FROM main.image_snapshot_handoff WHERE product_id=? AND revision=?',[$id,$revision]))throw new RuntimeException('Invalid content has persisted handoff');
            $reason=null;
            try {self::snapshot($db,$id,$revision,$row[0][1]);} catch(ImageContentRejected $e) {$reason=$e->getMessage();}
            if($reason===null){if(!$db->commit())throw new RuntimeException('Commit unconfirmed');return 'changed';}
            $q=$db->prepare('INSERT INTO main.image_handoff_quarantine VALUES(?,?,?) ON CONFLICT(product_id,revision) DO NOTHING');$q->execute([$id,$revision,$reason]);
            if(self::rows($db,'SELECT reason FROM main.image_handoff_quarantine WHERE product_id=? AND revision=?',[$id,$revision])!==[[$reason]])throw new RuntimeException('Conflicting quarantine');
            if(!$db->commit())throw new RuntimeException('Commit unconfirmed; reconcile before retry');return 'quarantined';
        } catch(Throwable $e) {self::rollback($db,$e);}
    }
    public static function consume(PDO $db,mixed $limit):array {
        self::gate($db);if(!is_int($limit)||$limit<1||$limit>100)throw new InvalidArgumentException('Invalid scan bound');
        $m=self::manifest();$result=['handed_off'=>0,'quarantined'=>0,'skipped'=>0,'changed'=>0,'scanned'=>0];
        // Per-record transactions: prior successful records remain durable if a later record fails.
        // Cursor scans ALL markers, including acknowledged/quarantined, bounding examined rows.
        for($i=0;$i<$limit;$i++) {
            self::gate($db);if(!$db->beginTransaction())throw new RuntimeException('Transaction start unconfirmed');
            try {
                self::installed($db,$m);
                $c=self::rows($db,'SELECT singleton,last_id,upper_id FROM main.image_handoff_cursor');
                if(count($c)!==1 || $c[0][0]!==1 || !is_int($c[0][1]) || $c[0][1]<0 || !is_int($c[0][2]) || $c[0][2]<$c[0][1])throw new RuntimeException('Invalid cursor');
                $rows=self::rows($db,'SELECT product_id,revision,deleted,acknowledged_revision FROM main.image_product_dirty WHERE product_id>? AND product_id<=? ORDER BY product_id LIMIT 1',[$c[0][1],$c[0][2]]);
                if(!$rows){
                    $upper=self::rows($db,'SELECT COALESCE(max(product_id),0) FROM main.image_product_dirty')[0][0];
                    $q=$db->prepare('UPDATE main.image_handoff_cursor SET last_id=0,upper_id=? WHERE singleton=1');$q->execute([$upper]);if($q->rowCount()!==1)throw new RuntimeException('Cursor epoch failed');
                    $rows=self::rows($db,'SELECT product_id,revision,deleted,acknowledged_revision FROM main.image_product_dirty WHERE product_id>0 AND product_id<=? ORDER BY product_id LIMIT 1',[$upper]);
                }
                if(!$rows){if(!$db->commit())throw new RuntimeException('Commit unconfirmed');break;}
                [$id,$revision,$deleted,$ack]=$rows[0];$result['scanned']++;
                $held=self::rows($db,'SELECT reason FROM main.image_handoff_quarantine WHERE product_id=? AND revision=?',[$id,$revision]);
                if($ack>=$revision || $held){self::cursor($db,$id);if(!$db->commit())throw new RuntimeException('Commit unconfirmed');$result['skipped']++;continue;}
                $snapshot=self::snapshot($db,$id,$revision,$deleted);$payload=$snapshot['snapshot_json'];$digest=$snapshot['snapshot_sha256'];
                $bounds=self::rows($db,'SELECT length(CAST(snapshot_json AS BLOB)),typeof(snapshot_json),length(snapshot_sha256),typeof(snapshot_sha256) FROM main.image_snapshot_handoff WHERE product_id=? AND revision=?',[$id,$revision]);
                if($bounds && ($bounds[0][0]>1048576||$bounds[0][1]!=='text'||$bounds[0][2]!==64||$bounds[0][3]!=='text'))throw new RuntimeException('Stored snapshot exceeds bounds');
                $prior=self::rows($db,'SELECT snapshot_json,snapshot_sha256 FROM main.image_snapshot_handoff WHERE product_id=? AND revision=?',[$id,$revision]);
                if(!$prior){$q=$db->prepare('INSERT INTO main.image_snapshot_handoff VALUES(?,?,?,?)');$q->execute([$id,$revision,$payload,$digest]);}
                elseif($prior!==[[$payload,$digest]])throw new RuntimeException('Conflicting handoff');
                $q=$db->prepare('UPDATE main.image_product_dirty SET acknowledged_revision=? WHERE product_id=? AND revision=? AND acknowledged_revision<?');$q->execute([$revision,$id,$revision,$revision]);if($q->rowCount()!==1)throw new RuntimeException('Conditional ACK failed');
                self::cursor($db,$id);if(!$db->commit())throw new RuntimeException('Commit unconfirmed; reconcile before retry');$result['handed_off']++;
            } catch(ImageContentRejected $e) {
                // Never infer successful cleanup: only quarantine after verified rollback.
                try {if(!$db->inTransaction() || !$db->rollBack())throw new RuntimeException('Content rollback failed');} catch(Throwable $cleanup) {throw new RuntimeException('Content rollback unconfirmed',0,$e);}
                $outcome=self::quarantine($db,$m,$id,$revision);$result[$outcome]++;
            } catch(Throwable $e) {self::rollback($db,$e);}
        }
        return $result;
    }
    private const MANIFEST_SHA256 = '6ef59595b7de01a31b0570c1e7d9754a45f29a6d1e96e91893d93a8f98a36fb6';
}
