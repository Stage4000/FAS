<?php
declare(strict_types=1);
namespace FAS\Utils;

use PDO;
use RuntimeException;

require_once __DIR__.'/SqliteSchemaDefinition.php';

/** Read-only V2 journal shape guard. Not a migration or proof of durable SQLite configuration. */
final class ActivationJournalSchema
{
    public static function requireReady(PDO $db):void
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'||$db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION){throw new RuntimeException('Activation journal requires SQLite exception mode.');}
        $q=$db->prepare('SELECT type,sql FROM sqlite_master WHERE name=?');$q->execute(['image_optimization_activations']);$table=$q->fetch(PDO::FETCH_ASSOC);
        if(!$table||$table['type']!=='table'||!is_string($table['sql'])){throw new RuntimeException('Activation journal migration is required.');}
        $expected=[
            'activation_id'=>['TEXT',1,1,null],'job_id'=>['INTEGER',1,0,null],'lease_token'=>['TEXT',1,0,null],'catalog_binding'=>['TEXT',1,0,null],'target_name'=>['TEXT',1,0,null],'before_bytes'=>['BLOB',0,0,null],'before_sha256'=>['TEXT',0,0,null],'after_bytes'=>['BLOB',1,0,null],'after_sha256'=>['TEXT',1,0,null],'variants_json'=>['TEXT',1,0,null],'state'=>['TEXT',1,0,null],'created_at'=>['INTEGER',1,0,null],'resolved_at'=>['INTEGER',0,0,null]
        ];$actual=[];
        foreach($db->query('PRAGMA table_xinfo(image_optimization_activations)')->fetchAll(PDO::FETCH_ASSOC) as $column){if((int)$column['hidden']!==0){throw new RuntimeException('Unexpected generated activation column.');}$actual[$column['name']]=[strtoupper($column['type']),(int)$column['notnull'],(int)$column['pk'],$column['dflt_value']];}
        if($actual!==$expected){throw new RuntimeException('Activation journal columns are incompatible.');}
        $trusted="CREATE TABLE image_optimization_activations (
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
)";
        SqliteSchemaDefinition::assertMatches($table['sql'],$trusted);
        $required=['image_activation_open_job'=>'job_id','image_activation_open_target'=>'target_name'];$found=[];
        foreach($db->query('PRAGMA index_list(image_optimization_activations)')->fetchAll(PDO::FETCH_ASSOC) as $index){
            $name=$index['name'];if(!array_key_exists($name,$required)){continue;}
            if((int)$index['unique']!==1||(int)$index['partial']!==1){throw new RuntimeException('Activation exclusion index must be partial and unique.');}
            $q=$db->prepare('SELECT name,desc,coll,key FROM pragma_index_xinfo(?)');$q->execute([$name]);$keys=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $column){if((int)$column['key']!==1){continue;}if((int)$column['desc']!==0||strtoupper((string)$column['coll'])!=='BINARY'||!is_string($column['name'])){throw new RuntimeException('Activation exclusion index ordering is incompatible.');}$keys[]=$column['name'];}
            if($keys!==[$required[$name]]){throw new RuntimeException('Activation exclusion index fields are incompatible.');}
            $q=$db->prepare("SELECT sql FROM sqlite_master WHERE type='index' AND name=? AND tbl_name='image_optimization_activations'");$q->execute([$name]);$definition=$q->fetchColumn();
            if(!is_string($definition)){throw new RuntimeException('Activation exclusion index definition unavailable.');}
            $trustedIndex="CREATE UNIQUE INDEX ".$name." ON image_optimization_activations(".$required[$name].") WHERE state IN ('prepared','conflict')";
            SqliteSchemaDefinition::assertMatches($definition,$trustedIndex);
            $found[$name]=true;
        }
        if(count($found)!==2){throw new RuntimeException('Both activation exclusion indexes are required.');}
    }
}
