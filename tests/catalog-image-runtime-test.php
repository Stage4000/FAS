<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/CatalogImageRuntime.php';
use FAS\Utils\{CatalogImageRuntime,ImageQueueSchema,ImageQueuePromotionSchema};
$checks=0;
function runtimeCheck($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$previous=getenv('FAS_ADMIN_IMAGE_OUTBOX');
try {
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach([null,'','0'] as $value) {
        putenv($value===null?'FAS_ADMIN_IMAGE_OUTBOX':'FAS_ADMIN_IMAGE_OUTBOX='.$value);
        runtimeCheck(CatalogImageRuntime::outbox($db)===null,'Disabled works without schema');
        runtimeCheck((int)$db->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn()===0,'Disabled makes no database writes');
    }
    $_GET['FAS_ADMIN_IMAGE_OUTBOX']='1';$_POST['FAS_ADMIN_IMAGE_OUTBOX']='1';$_COOKIE['FAS_ADMIN_IMAGE_OUTBOX']='1';
    runtimeCheck(CatalogImageRuntime::outbox($db)===null,'Requests cannot enable queue');
    foreach(['true','false',' 1','1 ','01','yes'] as $value) {
        putenv('FAS_ADMIN_IMAGE_OUTBOX='.$value);$rejected=false;
        try { CatalogImageRuntime::outbox($db); } catch(RuntimeException $e) { $rejected=true; }
        runtimeCheck($rejected,'Ambiguous configuration rejected');
    }
    putenv('FAS_ADMIN_IMAGE_OUTBOX=1');$rejected=false;
    try { CatalogImageRuntime::outbox($db); } catch(RuntimeException $e) { $rejected=true; }
    runtimeCheck($rejected,'Enabled missing schema rejected');
    runtimeCheck((int)$db->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn()===0,'No automatic migration');
    $schema=file_get_contents(__DIR__.'/../database/schema.sqlite.sql');$db->exec(explode('-- Categories table',$schema)[0]);
    ImageQueueSchema::install($db);$rejected=false;
    try { CatalogImageRuntime::outbox($db); } catch(RuntimeException $e) { $rejected=true; }
    runtimeCheck($rejected,'v1-only installation rejected');
    ImageQueuePromotionSchema::upgrade($db);
    runtimeCheck(CatalogImageRuntime::outbox($db) instanceof FAS\Utils\CatalogImageOutbox,'Explicit enabled ready v2 accepted');
    runtimeCheck((int)$db->query('SELECT COUNT(*) FROM image_optimization_jobs')->fetchColumn()===0,'Initialization performs no backfill');
    putenv('FAS_ADMIN_IMAGE_OUTBOX=0');$db->exec('DROP TABLE image_optimization_activations');
    runtimeCheck(CatalogImageRuntime::outbox($db)===null,'Disabled rollback tolerates absent queue schema');
    echo "Catalog image runtime: $checks checks passed\n";
} finally { putenv($previous===false?'FAS_ADMIN_IMAGE_OUTBOX':'FAS_ADMIN_IMAGE_OUTBOX='.$previous); }
