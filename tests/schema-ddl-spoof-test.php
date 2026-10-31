<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/ProductSyncOwnership.php';
require_once __DIR__.'/../src/utils/ImageQueueSchema.php';
use FAS\Utils\{ProductSyncOwnership,ImageQueueSchema};
$checks=0;
function ddlDb(){return new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function ddlReject(callable $f,string $label){global $checks;try{$f();}catch(RuntimeException $e){$checks++;echo "PASS $label\n";return;}throw new RuntimeException('Accepted spoof: '.$label);}
function ddlDefinitions(PDO $db){return $db->query("SELECT name,sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type DESC,name")->fetchAll(PDO::FETCH_ASSOC);}
$source=ddlDb();$source->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,description TEXT)');ProductSyncOwnership::install($source);ImageQueueSchema::install($source);
$definitions=ddlDefinitions($source);
foreach ([
 ['product_sync_ownership',"CHECK(local_owned IN(0,1))","CONSTRAINT \"check(local_ownedin(0,1))\" CHECK(1)",'ownership fake named constraint'],
 ['image_optimization_jobs',"CHECK(state IN ('pending','leased','retry','done','superseded','quarantined'))","CONSTRAINT \"check(statein('pending','leased','retry','done','superseded','quarantined'))\" CHECK(1)",'queue fake named constraint'],
 ['image_optimization_jobs',"'pending'","'PENDING'",'literal case is not folded'],
 ['image_optimization_jobs',"'pending'","'pen ding'",'literal whitespace is not removed'],
 ['product_sync_ownership_meta','CHECK(migration_version=1)','CONSTRAINT "check(migration_version=1)" CHECK(1)','ownership marker fake constraint'],
 ['image_optimization_queue_meta','CHECK(schema_version=1)','CONSTRAINT "check(schema_version=1)" CHECK(1)','queue marker fake constraint'],
] as [$target,$needle,$replacement,$label]) {
    $db=ddlDb();$changed=false;
    foreach($definitions as $definition){$sql=$definition['sql'];if($definition['name']===$target){$sql=str_replace($needle,$replacement,$sql,$count);if($count!==1)throw new RuntimeException('Fixture did not replace expected constraint');$changed=true;}$db->exec($sql);}
    if(!$changed)throw new RuntimeException('Missing fixture target');
    $db->exec("INSERT INTO product_sync_ownership_meta VALUES(1,1,0,'synthetic');INSERT INTO image_optimization_queue_meta VALUES(1,1,1)");
    if(str_starts_with($target,'product_'))ddlReject(fn()=>ProductSyncOwnership::installed($db),$label);
    else ddlReject(fn()=>ImageQueueSchema::requireReady($db),$label);
}
echo "DDL spoof regression: $checks checks passed\n";
