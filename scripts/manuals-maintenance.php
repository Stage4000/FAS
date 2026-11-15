<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../src/manuals/bootstrap.php';
$action=$argv[1]??'';
if(!in_array($action,['status','init'],true)){fwrite(STDERR,"Usage: php scripts/manuals-maintenance.php status|init\n");exit(2);}
try{
    manualsStorage();$db=manualsDatabase();
    if($action==='init')\FAS\Manuals\ManualLibrary::install($db);
    echo json_encode(['installed'=>\FAS\Manuals\ManualLibrary::installed($db),'public_enabled'=>manualsEnabled(),'private_storage'=>'validated'],JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){fwrite(STDERR,'Manuals maintenance failed: '.$e->getMessage()."\n");exit(1);}
