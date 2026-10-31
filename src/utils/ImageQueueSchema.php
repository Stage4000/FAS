<?php
declare(strict_types=1);
namespace FAS\Utils;

require_once __DIR__.'/SqliteSchemaDefinition.php';

use PDO;
use RuntimeException;
use Throwable;

/** Explicit SQLite maintenance only. Ordinary catalog writes may verify, never install. */
final class ImageQueueSchema
{
    private const QUEUE_DDL = <<<'SQL'
CREATE TABLE image_optimization_jobs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 event_key TEXT NOT NULL,
 work_key TEXT NOT NULL,
 product_id INTEGER NOT NULL,
 intent_json TEXT NOT NULL,
 state TEXT NOT NULL CHECK(state IN ('pending','leased','retry','done','superseded','quarantined')),
 attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 5),
 available_at INTEGER NOT NULL,
 lease_token TEXT,
 lease_until INTEGER,
 failure TEXT,
 created_at INTEGER NOT NULL,
 UNIQUE(event_key,work_key)
)
SQL;
    private const META_DDL = 'CREATE TABLE image_optimization_queue_meta(id INTEGER PRIMARY KEY CHECK(id=1),schema_version INTEGER NOT NULL CHECK(schema_version=1),installed_at INTEGER NOT NULL CHECK(installed_at>=0))';

    public static function install(PDO $db):void
    {
        self::connection($db);self::rejectV2($db);
        if(PHP_SAPI!=='cli'||$db->inTransaction()){throw new RuntimeException('Image queue installation requires its own CLI transaction.');}
        $queue=self::definition($db,'image_optimization_jobs');$meta=self::definition($db,'image_optimization_queue_meta');
        if($queue===null && $meta!==null){throw new RuntimeException('Image queue schema is incomplete.');}
        if($queue!==null){self::validateQueue($db);}
        if($meta!==null){self::validateMeta($db,false);}
        $db->beginTransaction();try{
            if($queue===null){$sql=file_get_contents(dirname(__DIR__,2).'/database/migrations/image-optimization-queue.sql');if($sql===false){throw new RuntimeException('Queue migration unavailable.');}$db->exec($sql);}
            self::validateQueue($db);
            if($meta===null){$db->exec('CREATE TABLE image_optimization_queue_meta(id INTEGER PRIMARY KEY CHECK(id=1),schema_version INTEGER NOT NULL CHECK(schema_version=1),installed_at INTEGER NOT NULL CHECK(installed_at>=0))');}
            self::validateMeta($db,false);
            if((int)$db->query('SELECT COUNT(*) FROM image_optimization_queue_meta')->fetchColumn()===0){$q=$db->prepare('INSERT INTO image_optimization_queue_meta(id,schema_version,installed_at) VALUES(1,1,?)');$q->execute([time()]);}
            self::requireReady($db);if(!$db->commit()){throw new RuntimeException('Queue installation commit unconfirmed; inspect before retry.');}
        }catch(Throwable $error){try{if($db->inTransaction()&&!$db->rollBack()){throw new RuntimeException('Rollback unconfirmed.');}}catch(Throwable $rollback){throw new RuntimeException('Queue installation outcome uncertain; do not retry automatically.',0,$rollback);}throw $error;}
    }
    public static function requireReady(PDO $db):void
    {
        self::rejectV2($db);self::requireFoundation($db);
    }
    /** Exact v1 foundation only; not permission to use the v1 runtime after upgrading. */
    public static function requireFoundation(PDO $db):void
    {
        self::connection($db);self::validateQueue($db);self::validateMeta($db,true);
    }
    private static function rejectV2(PDO $db):void
    {
        self::connection($db);
        $q=$db->query("SELECT 1 FROM sqlite_master WHERE name IN ('image_optimization_activations','image_activation_open_job','image_activation_open_target','image_optimization_promotion_meta') LIMIT 1");
        if($q->fetchColumn()!==false)throw new RuntimeException('V2 artifacts require the journal-aware runtime; v1 mode refused.');
    }
    private static function connection(PDO $db):void
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'||$db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION){throw new RuntimeException('Image queue requires SQLite exception mode.');}
    }
    private static function definition(PDO $db,string $name):?string
    {
        $q=$db->prepare('SELECT type,sql FROM sqlite_master WHERE name=?');$q->execute([$name]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row){return null;}if($row['type']!=='table'||!is_string($row['sql'])){throw new RuntimeException('Image queue object is not a table.');}return $row['sql'];
    }
    private static function columns(PDO $db,string $table,array $expected):void
    {
        $rows=$db->query('PRAGMA table_xinfo('.$table.')')->fetchAll(PDO::FETCH_ASSOC);$actual=[];
        foreach($rows as $row){if((int)$row['hidden']!==0){throw new RuntimeException('Unexpected generated queue column.');}$actual[$row['name']]=[strtoupper($row['type']),(int)$row['notnull'],(int)$row['pk'],$row['dflt_value']];}
        if($actual!==$expected){throw new RuntimeException('Image queue columns are incompatible.');}
    }
    private static function validateQueue(PDO $db):void
    {
        $sql=self::definition($db,'image_optimization_jobs');if($sql===null){throw new RuntimeException('Image queue migration is required.');}
        self::columns($db,'image_optimization_jobs',[
            'id'=>['INTEGER',0,1,null],'event_key'=>['TEXT',1,0,null],'work_key'=>['TEXT',1,0,null],'product_id'=>['INTEGER',1,0,null],'intent_json'=>['TEXT',1,0,null],'state'=>['TEXT',1,0,null],'attempts'=>['INTEGER',1,0,'0'],'available_at'=>['INTEGER',1,0,null],'lease_token'=>['TEXT',0,0,null],'lease_until'=>['INTEGER',0,0,null],'failure'=>['TEXT',0,0,null],'created_at'=>['INTEGER',1,0,null]
        ]);
        SqliteSchemaDefinition::assertMatches($sql, self::QUEUE_DDL);
        $unique=false;$due=false;
        foreach($db->query('PRAGMA index_list(image_optimization_jobs)')->fetchAll(PDO::FETCH_ASSOC) as $index){
            $q=$db->prepare('SELECT name,desc,coll,key FROM pragma_index_xinfo(?)');$q->execute([$index['name']]);$keys=[];$simple=true;
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $column){if((int)$column['key']!==1){continue;}$keys[]=$column['name'];if((int)$column['desc']!==0 || strtoupper((string)$column['coll'])!=='BINARY'){$simple=false;}}
            if(!$simple || (int)$index['partial']!==0){continue;}
            if((int)$index['unique']===1 && $keys===['event_key','work_key']){$unique=true;}
            if($index['name']==='image_jobs_due' && (int)$index['unique']===0 && $keys===['state','available_at','lease_until','id']){$due=true;}
        }
        if(!$unique||!$due){throw new RuntimeException('Image queue indexes are incompatible.');}
    }
    private static function validateMeta(PDO $db,bool $requireMarker):void
    {
        $sql=self::definition($db,'image_optimization_queue_meta');if($sql===null){throw new RuntimeException('Image queue installation marker is required.');}
        self::columns($db,'image_optimization_queue_meta',['id'=>['INTEGER',0,1,null],'schema_version'=>['INTEGER',1,0,null],'installed_at'=>['INTEGER',1,0,null]]);
        SqliteSchemaDefinition::assertMatches($sql, self::META_DDL);
        $rows=$db->query('SELECT * FROM image_optimization_queue_meta')->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows&&!$requireMarker){return;}
        if(count($rows)!==1 || (string)$rows[0]['id']!=='1'||(string)$rows[0]['schema_version']!=='1'||filter_var($rows[0]['installed_at'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])===false){throw new RuntimeException('Image queue completion marker is invalid.');}
    }
}
