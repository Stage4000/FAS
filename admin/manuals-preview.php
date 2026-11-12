<?php
require_once __DIR__.'/manuals-common.php';
$manual=$manualsRepo->getAdmin(manualsId(),(int)$admin['id']);if(!$manual){http_response_code(404);exit('Manual not found.');}
$manualMetadata=$manual['draft'];$manualFileUrl='/admin/manuals-file.php?id='.$manual['id'];$manualBackUrl='/admin/manuals-edit.php?id='.$manual['id'];$manualPreview=true;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=manualsH($manualMetadata['title'])?> · Private PDF preview</title><link rel="stylesheet" href="/public/css/manuals.css?v=20261112a"></head><body class="manual-preview-page"><?php include __DIR__.'/../includes/manual-reader.php';?></body></html>
