#!/usr/bin/env php
<?php

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/../admin/auth.php';

    $auth = new AdminAuth();
    if (!$auth->isLoggedIn()) {
        header('Location: /admin/login.php');
        exit;
    }
}

require_once __DIR__ . '/../src/config/Database.php';

use FAS\Config\Database;

function fasFreeShippingMigrationColumnExists(PDO $db, string $column): bool
{
    $stmt = $db->query('PRAGMA table_info(products)');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function fasRunFreeShippingMigration(): array
{
    $messages = [];

    try {
        $db = Database::getInstance()->getConnection();

        if (!fasFreeShippingMigrationColumnExists($db, 'free_shipping')) {
            $db->exec('ALTER TABLE products ADD COLUMN free_shipping INTEGER NOT NULL DEFAULT 0');
            $messages[] = ['success', 'Added products.free_shipping.'];
        } else {
            $messages[] = ['success', 'products.free_shipping already exists.'];
        }

        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_free_shipping ON products(free_shipping)');
        $messages[] = ['success', 'Verified idx_products_free_shipping.'];
        $messages[] = ['success', 'Migration complete.'];
    } catch (Throwable $e) {
        $messages[] = ['danger', 'Migration failed: ' . $e->getMessage()];
    }

    return $messages;
}

$messages = fasRunFreeShippingMigration();

if ($isCli) {
    foreach ($messages as $message) {
        echo $message[1] . PHP_EOL;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Free Shipping Migration - Flip and Strip Admin</title>
    <link rel="shortcut icon" href="/gallery/favicons/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3"><i class="fas fa-truck-fast text-danger me-2"></i>Free Shipping Migration</h1>
                <?php foreach ($messages as $message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($message[0], ENT_QUOTES, 'UTF-8'); ?> mb-2">
                        <?php echo htmlspecialchars($message[1], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endforeach; ?>
                <a class="btn btn-danger mt-3" href="/admin/free-shipping.php">Open Free Shipping Settings</a>
            </div>
        </div>
    </div>
</body>
</html>
