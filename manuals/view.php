<?php
require_once __DIR__.'/../src/manuals/bootstrap.php';
header('Cache-Control: no-store');
try {
    if(!manualsEnabled()){http_response_code(404);exit('Manual not found.');}
    $repo=new \FAS\Manuals\ManualLibrary(manualsDatabase(),static function(){return false;});
    $manual=$repo->getPublished(manualsId());if(!$manual){http_response_code(404);exit('Manual not found.');}
}catch(Throwable $e){error_log('Manual reader unavailable: '.$e->getMessage());http_response_code(503);exit('The manual is temporarily unavailable.');}
$manualMetadata=$manual['metadata'];$manualFileUrl='/manuals/file.php?id='.$manual['id'];$manualBackUrl='/manuals/';$manualPreview=false;
$pageTitle=$manualMetadata['title'];$pageDescription=$manualMetadata['description'];$canonicalUrl='/manuals/view.php?id='.$manual['id'];
$extraHeadMeta='<link rel="stylesheet" href="/public/css/manuals.css?v=20261112a">';
header('Cache-Control: no-store');include __DIR__.'/../includes/header.php';include __DIR__.'/../includes/manual-reader.php';include __DIR__.'/../includes/footer.php';
