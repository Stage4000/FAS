<?php
require_once __DIR__.'/manuals-common.php';
try{$manual=$manualsRepo->getAdmin(manualsId(),(int)$admin['id']);if(!$manual){http_response_code(404);exit('Manual not found.');}manualsStream($manual['file'],$manual['draft']['title']);}
catch(Throwable $e){error_log('Private PDF unavailable: '.$e->getMessage());http_response_code(503);exit('The PDF is temporarily unavailable.');}
