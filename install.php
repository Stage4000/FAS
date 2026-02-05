<?php
header('Content-Type: text/html; charset=utf-8');
$messages = [];
$errors = [];

// Step 1: Config
$srcConfig = __DIR__ . '/src/config/config.example.php';
$dstConfig = __DIR__ . '/src/config/config.php';
if (!file_exists($dstConfig)) {
    copy($srcConfig, $dstConfig) ? $messages[] = 'Config ready' : $errors[] = 'Config failed';
} else {
    $messages[] = 'Config found';
}

// Step 2: Database  
if (empty($errors)) {
    ob_start();
    include __DIR__ . '/database/init-sqlite.php';
    $dbOutput = ob_get_clean();
    $messages[] = 'Database ready';
}

// Step 3: Admin
if (empty($errors)) {
    ob_start();
    include __DIR__ . '/admin/init-admin.php';
    $adminOutput = ob_get_clean();
    $messages[] = 'Admin ready (admin/admin123)';
}

$success = empty($errors);
?>
<!DOCTYPE html>
<html><head><title>FAS Installer</title><style>body{font:14px monospace;background:#222;color:#0f0;padding:40px}div{margin:10px 0}.ok{color:#0f0}.err{color:#f00}a{color:#0ff}</style></head><body>
<h2>FAS Installation</h2>
<?php foreach($messages as $m) echo "<div class='ok'>✓ $m</div>"; ?>
<?php foreach($errors as $e) echo "<div class='err'>✗ $e</div>"; ?>
<?php if($success): ?>
<div style="margin-top:20px"><a href="/admin/">→ Go to Admin</a></div>
<?php 
if (!unlink(__FILE__)) {
    echo "<div class='err' style='margin-top:10px'>⚠ Please manually delete install.php</div>";
}
endif; ?>
</body></html>
