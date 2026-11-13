<?php
require_once __DIR__.'/../src/manuals/bootstrap.php';
header('Cache-Control: no-store');
try {
    if(!manualsEnabled()){http_response_code(404);exit('Manual not found.');}
    $repo=new \FAS\Manuals\ManualLibrary(manualsDatabase(),static function(){return false;});
    $manual=$repo->getPublished(manualsId());if(!$manual){http_response_code(404);exit('Manual not found.');}
    manualsStream($manual['file'],$manual['metadata']['title']);
}catch(Throwable $e){error_log('Manual PDF unavailable: '.$e->getMessage());http_response_code(503);exit('The PDF is temporarily unavailable.');}
