<?php
declare(strict_types=1);
/** INACTIVE explicit offline candidate. No DB opening, automatic migration or application caller. */
final class ImageHandoffMigration {
 private const PINS=[2=>'018e0ca5bb6310b8f2848460b57330b029c3a27010e7bed95d081fe520aa5020',3=>'6ef59595b7de01a31b0570c1e7d9754a45f29a6d1e96e91893d93a8f98a36fb6'];
 private static function rows(PDO $d,string $sql,array $p=[]):array{$q=$d->prepare($sql);$q->execute($p);return $q->fetchAll(PDO::FETCH_NUM);}
 private static function manifest(int $v):array{$raw=file_get_contents(__DIR__.'/schema-v'.$v.'.json');if($raw===false||hash('sha256',$raw)!==self::PINS[$v])throw new RuntimeException('Manifest drift');return json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
 private static function gate(PDO $d,int $limit):void{
  if($limit<0||$limit>100000)throw new InvalidArgumentException('Invalid row bound');
  if($d->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'||$d->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION||$d->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)||$d->inTransaction())throw new RuntimeException('Unowned connection');
  if(self::rows($d,'PRAGMA ignore_check_constraints')!==[[0]])throw new RuntimeException('CHECK enforcement required');
  if(self::rows($d,"SELECT 1 FROM temp.sqlite_master WHERE type='trigger' OR name='products' OR name LIKE 'image_%' OR name LIKE 'pragma_%' LIMIT 1")||self::rows($d,"SELECT 1 FROM main.sqlite_master WHERE name LIKE 'pragma_%' LIMIT 1"))throw new RuntimeException('Schema collision');
 }
 private static function installed(PDO $d,array $m,int $v):void{
  if(self::rows($d,"SELECT type,name,sql FROM main.sqlite_master WHERE tbl_name='products' AND type IN ('table','index') ORDER BY type,name")!==$m['catalog'])throw new RuntimeException('Catalog drift');
  if(self::rows($d,"SELECT 1 FROM main.sqlite_master WHERE type='index' AND tbl_name LIKE 'image_%' AND sql IS NOT NULL AND name NOT LIKE 'image_%' LIMIT 1"))throw new RuntimeException('Unknown handoff index');
  $a=[];foreach(self::rows($d,"SELECT name,sql FROM main.sqlite_master WHERE name LIKE 'image_%' AND sql IS NOT NULL")as[$name,$sql])$a[$name]=$sql;ksort($a,SORT_STRING);$e=$m['storage'];ksort($e,SORT_STRING);if($a!==$e)throw new RuntimeException('Storage drift');
  $triggers=array_keys(array_filter($e,fn($sql,$name)=>str_starts_with($name,'image_product_dirty_'),ARRAY_FILTER_USE_BOTH));sort($triggers,SORT_STRING);
  if(array_column(self::rows($d,"SELECT name FROM main.sqlite_master WHERE type='trigger' ORDER BY name"),0)!==$triggers)throw new RuntimeException('Unknown trigger');
  if(self::rows($d,'SELECT singleton,version,contract FROM main.image_handoff_version')!==[[1,$v,$v===2?'no-replace-v2':'quarantine-v3']])throw new RuntimeException('Version mismatch');
 }
 private static function foreignDependencies(PDO $d):void{
  $tables=self::rows($d,"SELECT name FROM main.sqlite_master WHERE type='table' LIMIT 1001");if(count($tables)>1000)throw new RuntimeException('Schema inventory bound exceeded');
  foreach($tables as [$name]){foreach(self::rows($d,'PRAGMA main.foreign_key_list("'.str_replace('"','""',$name).'")') as $fk){if(in_array(strtolower($fk[2]),['image_handoff_version','image_handoff_quarantine','image_handoff_cursor'],true))throw new RuntimeException('External migration foreign-key dependency');}}
 }
 private static function fingerprint(PDO $d,int $limit):string{
  foreach(['image_product_dirty','image_snapshot_handoff']as$table){$n=self::rows($d,'SELECT count(*) FROM main.'.$table)[0][0];if(!is_int($n)||$n>$limit)throw new RuntimeException('Row bound exceeded');}
  $bytes=self::rows($d,'SELECT COALESCE(sum(length(CAST(snapshot_json AS BLOB))+length(CAST(snapshot_sha256 AS BLOB))),0) FROM main.image_snapshot_handoff')[0][0];if(!is_int($bytes)||$bytes>67108864)throw new RuntimeException('Snapshot byte bound exceeded');
  if(self::rows($d,"SELECT 1 FROM main.image_snapshot_handoff WHERE typeof(snapshot_json)<>'text' OR typeof(snapshot_sha256)<>'text' OR length(CAST(snapshot_json AS BLOB))>1048576 OR length(snapshot_sha256)<>64 LIMIT 1"))throw new RuntimeException('Invalid stored snapshot');
  $h=hash_init('sha256');
  foreach(['SELECT product_id,revision,deleted,acknowledged_revision FROM main.image_product_dirty ORDER BY product_id','SELECT product_id,revision,snapshot_json,snapshot_sha256 FROM main.image_snapshot_handoff ORDER BY product_id,revision']as$sql){hash_update($h,$sql);$q=$d->query($sql);while(($row=$q->fetch(PDO::FETCH_NUM))!==false){if(str_contains($sql,'snapshot_json')&&!hash_equals(hash('sha256',$row[2]),$row[3]))throw new RuntimeException('Stored snapshot hash mismatch');$raw=serialize($row);hash_update($h,strlen($raw).':'.$raw);}$q->closeCursor();}
  return hash_final($h);
 }
 private static function rollback(PDO $d,Throwable $e):never{try{if($d->inTransaction()&&!$d->rollBack())throw new RuntimeException('Rollback false');}catch(Throwable $cleanup){throw new RuntimeException('Rollback unconfirmed; reconcile before retry',0,$e);}throw $e;}
 public static function upgrade(PDO $d,int $rowLimit):array{
  self::gate($d,$rowLimit);$old=self::manifest(2);$new=self::manifest(3);if(!$d->beginTransaction())throw new RuntimeException('Transaction start unconfirmed');
  try{self::installed($d,$old,2);self::foreignDependencies($d);$before=self::fingerprint($d,$rowLimit);
   $d->exec('DROP TABLE main.image_handoff_version');$d->exec($new['storage']['image_handoff_version']);$d->exec("INSERT INTO main.image_handoff_version VALUES(1,3,'quarantine-v3')");
   foreach(['image_handoff_quarantine','image_handoff_cursor']as$name)$d->exec($new['storage'][$name]);$d->exec('INSERT INTO main.image_handoff_cursor VALUES(1,0,0)');
   self::installed($d,$new,3);if(self::fingerprint($d,$rowLimit)!==$before)throw new RuntimeException('Preservation mismatch');
   if(!$d->commit())throw new RuntimeException('Commit unconfirmed; reconcile before retry');return ['from'=>2,'to'=>3,'queue_sha256'=>$before];
  }catch(Throwable $e){self::rollback($d,$e);}
 }
 /** Narrow pre-activation reversal only. Not rollback after v3 processing or queue changes. Unrelated product-field writes are preserved, not forbidden. */
 public static function reverseUnused(PDO $d,int $rowLimit,string $upgradeQueueSha256):array{
  self::gate($d,$rowLimit);if(!preg_match('/^[a-f0-9]{64}$/D',$upgradeQueueSha256))throw new InvalidArgumentException('Invalid preservation receipt');$old=self::manifest(2);$new=self::manifest(3);if(!$d->beginTransaction())throw new RuntimeException('Transaction start unconfirmed');
  try{self::installed($d,$new,3);self::foreignDependencies($d);
   if(self::rows($d,'SELECT 1 FROM main.image_handoff_quarantine LIMIT 1')||self::rows($d,'SELECT singleton,last_id,upper_id FROM main.image_handoff_cursor')!==[[1,0,0]])throw new RuntimeException('v3 activity prevents reversal');
   if(self::fingerprint($d,$rowLimit)!==$upgradeQueueSha256)throw new RuntimeException('Queue changed since upgrade');
   $d->exec('DROP TABLE main.image_handoff_quarantine');$d->exec('DROP TABLE main.image_handoff_cursor');$d->exec('DROP TABLE main.image_handoff_version');$d->exec($old['storage']['image_handoff_version']);$d->exec("INSERT INTO main.image_handoff_version VALUES(1,2,'no-replace-v2')");
   self::installed($d,$old,2);if(self::fingerprint($d,$rowLimit)!==$upgradeQueueSha256)throw new RuntimeException('Reversal preservation mismatch');
   if(!$d->commit())throw new RuntimeException('Commit unconfirmed; reconcile before retry');return ['from'=>3,'to'=>2,'queue_sha256'=>$upgradeQueueSha256];
  }catch(Throwable $e){self::rollback($d,$e);}
 }
}
