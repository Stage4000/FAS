<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/ImageQueueSchema.php';
require_once __DIR__.'/ActivationJournalSchema.php';

/** Explicit additive v1-to-v2 maintenance. Does not start workers or alter queued jobs. */
final class ImageQueuePromotionSchema
{
    private const META = 'CREATE TABLE image_optimization_promotion_meta(id INTEGER PRIMARY KEY CHECK(id=1),schema_version INTEGER NOT NULL CHECK(schema_version=2),base_queue_version INTEGER NOT NULL CHECK(base_queue_version=1),upgraded_at INTEGER NOT NULL CHECK(upgraded_at>=0))';
    private const JOURNAL = <<<'SQL'
CREATE TABLE image_optimization_activations (
 activation_id TEXT PRIMARY KEY NOT NULL,
 job_id INTEGER NOT NULL,
 lease_token TEXT NOT NULL,
 catalog_binding TEXT NOT NULL,
 target_name TEXT NOT NULL,
 before_bytes BLOB,
 before_sha256 TEXT,
 after_bytes BLOB NOT NULL,
 after_sha256 TEXT NOT NULL,
 variants_json TEXT NOT NULL,
 state TEXT NOT NULL CHECK(state IN ('prepared','completed','aborted','conflict')),
 created_at INTEGER NOT NULL,
 resolved_at INTEGER,
 CHECK(length(after_bytes) BETWEEN 1 AND 16384),
 CHECK(before_bytes IS NULL OR length(before_bytes) BETWEEN 1 AND 16384)
);
CREATE UNIQUE INDEX image_activation_open_job ON image_optimization_activations(job_id) WHERE state IN ('prepared','conflict');
CREATE UNIQUE INDEX image_activation_open_target ON image_optimization_activations(target_name) WHERE state IN ('prepared','conflict');
SQL;
    public static function upgrade(\PDO $db):void
    {
        if(PHP_SAPI!=='cli'||$db->inTransaction())throw new \RuntimeException('V2 upgrade requires its own CLI transaction.');
        ImageQueueSchema::requireFoundation($db);
        if(self::hasArtifacts($db)){self::requireReady($db);return;} // Completed no-op, or fail closed on partial/unknown state.
        if((int)$db->query('PRAGMA synchronous')->fetchColumn()<2)throw new \RuntimeException('Durable SQLite synchronization required for upgrade.');
        $db->beginTransaction();
        try{
            // Reserve the SQLite writer; preserve original installed_at and every queue row.
            $db->exec('UPDATE image_optimization_queue_meta SET installed_at=installed_at WHERE id=1');
            ImageQueueSchema::requireFoundation($db);
            if(self::hasArtifacts($db))throw new \RuntimeException('V2 artifacts appeared during maintenance; inspect and retry only after verification.');
            $db->exec(self::JOURNAL);
            ActivationJournalSchema::requireReady($db);
            $db->exec(self::META);
            $q=$db->prepare('INSERT INTO image_optimization_promotion_meta(id,schema_version,base_queue_version,upgraded_at) VALUES(1,2,1,?)');$q->execute([time()]);
            self::requireReady($db);
            if(!$db->commit())throw new \RuntimeException('Upgrade commit uncertain; inspect using a fresh connection before retry.');
        }catch(\Throwable $e){
            try{if($db->inTransaction()&&!$db->rollBack())throw new \RuntimeException('Rollback unconfirmed.');}
            catch(\Throwable $rollback){throw new \RuntimeException('Upgrade outcome uncertain; do not retry automatically.',0,$rollback);}
            throw $e;
        }
    }
    public static function requireReady(\PDO $db):void
    {
        ImageQueueSchema::requireFoundation($db);
        ActivationJournalSchema::requireReady($db);
        $q=$db->query("SELECT type,sql FROM sqlite_master WHERE name='image_optimization_promotion_meta'");$row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row||$row['type']!=='table'||!is_string($row['sql']))throw new \RuntimeException('V2 completion marker is required.');
        SqliteSchemaDefinition::assertMatches($row['sql'],self::META);
        $expected=['id'=>['INTEGER',0,1,null],'schema_version'=>['INTEGER',1,0,null],'base_queue_version'=>['INTEGER',1,0,null],'upgraded_at'=>['INTEGER',1,0,null]];$actual=[];
        foreach($db->query('PRAGMA table_xinfo(image_optimization_promotion_meta)')->fetchAll(\PDO::FETCH_ASSOC)as$c){if((int)$c['hidden']!==0)throw new \RuntimeException('Unexpected generated marker column.');$actual[$c['name']]=[strtoupper($c['type']),(int)$c['notnull'],(int)$c['pk'],$c['dflt_value']];}
        if($actual!==$expected)throw new \RuntimeException('V2 marker columns incompatible.');
        $rows=$db->query('SELECT * FROM image_optimization_promotion_meta')->fetchAll(\PDO::FETCH_ASSOC);
        if(count($rows)!==1||$rows[0]['id']!==1||$rows[0]['schema_version']!==2||$rows[0]['base_queue_version']!==1||!is_int($rows[0]['upgraded_at'])||$rows[0]['upgraded_at']<0)throw new \RuntimeException('V2 completion marker invalid.');
    }
    private static function hasArtifacts(\PDO $db):bool
    {
        return $db->query("SELECT 1 FROM sqlite_master WHERE name IN ('image_optimization_activations','image_activation_open_job','image_activation_open_target','image_optimization_promotion_meta') LIMIT 1")->fetchColumn()!==false;
    }
}
