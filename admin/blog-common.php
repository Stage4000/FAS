<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/utils/CSRF.php';
require_once __DIR__.'/../src/blog/BlogContent.php';
require_once __DIR__.'/../src/blog/BlogRepository.php';

use FAS\Blog\BlogRepository;

$auth = new AdminAuth();
$admin = $auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
$db = \FAS\Config\Database::getInstance()->getConnection();
$repository = null;
try {
    $repository = new BlogRepository($db, function ($actorId, $action) use ($auth, $admin) {
        return (int)$actorId === (int)$admin['id'] && $auth->isLoggedIn();
    });
} catch (Throwable $e) { error_log('Blog repository unavailable: '.$e->getMessage()); blogUnavailable(); }
function blogH($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function blogPublicEnabled(): bool {
    $path = __DIR__.'/../src/config/config.php';
    if (!is_file($path)) return false;
    try { $config = require $path; return is_array($config) && ($config['blog']['public_enabled'] ?? false) === true; }
    catch (Throwable $e) { error_log('Blog release configuration unavailable.'); return false; }
}
function blogStateLabel(string $state, bool $publicEnabled): string {
    if ($publicEnabled) return $state;
    $labels = ['Published'=>'Reviewed snapshot', 'Unpublished changes'=>'Draft changes after review', 'Withdrawn'=>'Reviewed snapshot removed'];
    return $labels[$state] ?? $state;
}
function blogHeader(string $title): void {
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= blogH($title) ?> - Flip and Strip Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"><link rel="stylesheet" href="css/admin-style.css">
    <link rel="stylesheet" href="css/blog-editor.css"></head><body class="bg-light"><?php
    include __DIR__.'/includes/nav.php';
    echo '<main class="blog-admin"><h1 class="h3 mb-4">'.blogH($title).'</h1>';
}
function blogFooter(): void { echo '</main>'; include __DIR__.'/includes/footer.php'; echo '</body></html>'; }
function blogReady(): bool {
    global $db;
    try { return BlogRepository::installed($db); }
    catch (Throwable $e) { error_log('Blog storage check failed: '.$e->getMessage()); return false; }
}
function blogUnavailable(): void {
    http_response_code(503);
    blogHeader('Blog unavailable');
    echo '<div class="alert alert-warning" role="alert">Blog storage is unavailable or has not been initialized. No content has been changed. Ask the site operator to check the blog deployment before editing.</div>';
    blogFooter();
    exit;
}
