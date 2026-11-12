<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
require_once __DIR__.'/../src/manuals/bootstrap.php';
$auth=new AdminAuth();$admin=$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');
$manualsDb=manualsDatabase();
$manualsRepo=new \FAS\Manuals\ManualLibrary($manualsDb,function($actor,$action)use($auth,$admin){return $actor===(int)$admin['id']&&$auth->isLoggedIn();});
function manualsAdminHeader(string $title):void {
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=manualsH($title)?> · Flip and Strip</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"><link rel="stylesheet" href="css/admin-style.css"><link rel="stylesheet" href="/public/css/manuals.css?v=20261112a"></head><body><?php include __DIR__.'/includes/nav.php';?><main class="manual-admin"><a href="manuals.php">Manual library</a><h1><?=manualsH($title)?></h1><?php
}
function manualsAdminFooter():void {echo '</main>';include __DIR__.'/includes/footer.php';echo '</body></html>';}
if(!\FAS\Manuals\ManualLibrary::installed($manualsDb)){http_response_code(503);manualsAdminHeader('Manuals not initialized');echo '<p>Ask the site operator to initialize the reviewed manuals storage before uploading. No content has changed.</p>';manualsAdminFooter();exit;}
