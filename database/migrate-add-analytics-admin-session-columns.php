#!/usr/bin/env php
<?php

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/../admin/auth.php';
    require_once __DIR__ . '/../src/utils/CSRF.php';

    $auth = new AdminAuth();
    if (!$auth->isLoggedIn()) {
        header('Location: /admin/login.php');
        exit;
    }
}

require_once __DIR__ . '/../src/config/Database.php';

function migrationTableExists(PDO $db, string $driver, string $table): bool
{
    if ($driver === 'sqlite') {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = $db->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function migrationColumnExists(PDO $db, string $driver, string $table, string $column): bool
{
    if ($driver === 'sqlite') {
        $stmt = $db->query("PRAGMA table_info({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strcasecmp((string) ($row['name'] ?? ''), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

function migrationIndexExists(PDO $db, string $driver, string $table, string $index): bool
{
    if ($driver === 'sqlite') {
        $stmt = $db->query("PRAGMA index_list({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strcasecmp((string) ($row['name'] ?? ''), $index) === 0) {
                return true;
            }
        }

        return false;
    }

    $stmt = $db->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
    $stmt->execute([$index]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

function runAnalyticsAdminSessionMigration(): array
{
    $messages = [];

    try {
        $db = \FAS\Config\Database::getInstance()->getConnection();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $table = 'analytics_sessions';

        $messages[] = ['info', 'Starting migration: Add analytics admin session columns'];
        $messages[] = ['info', "Database driver: {$driver}"];

        if (!migrationTableExists($db, $driver, $table)) {
            $messages[] = ['danger', 'analytics_sessions table does not exist. Load the analytics dashboard once or run analytics setup first.'];
            return [false, $messages];
        }

        $columns = [
            'is_admin_session' => [
                'sqlite' => 'INTEGER NOT NULL DEFAULT 0',
                'mysql' => 'TINYINT(1) NOT NULL DEFAULT 0',
            ],
            'admin_username' => [
                'sqlite' => 'TEXT',
                'mysql' => 'VARCHAR(255) NULL',
            ],
        ];

        foreach ($columns as $column => $definitions) {
            if (migrationColumnExists($db, $driver, $table, $column)) {
                $messages[] = ['success', "Already exists: {$table}.{$column}"];
                continue;
            }

            $definition = $driver === 'sqlite' ? $definitions['sqlite'] : $definitions['mysql'];
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            $messages[] = ['success', "Added: {$table}.{$column}"];
        }

        $indexName = 'idx_analytics_sessions_admin';
        if (migrationIndexExists($db, $driver, $table, $indexName)) {
            $messages[] = ['success', "Already exists: {$indexName}"];
        } else {
            $db->exec("CREATE INDEX {$indexName} ON {$table}(is_admin_session)");
            $messages[] = ['success', "Added: {$indexName}"];
        }

        $messages[] = ['success', 'Migration complete.'];
        return [true, $messages];
    } catch (Throwable $e) {
        $messages[] = ['danger', 'Migration failed: ' . $e->getMessage()];
        return [false, $messages];
    }
}

function migrationTextOutput(array $messages): string
{
    return implode('', array_map(static function (array $message): string {
        return $message[1] . PHP_EOL;
    }, $messages));
}

function migrationEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function renderMigrationPage(bool $ran, bool $success, array $messages): void
{
    http_response_code($success ? 200 : ($ran ? 500 : 200));
    header('Content-Type: text/html; charset=UTF-8');

    $token = \FAS\Utils\CSRF::generateToken();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Analytics Admin Session Migration</title>
        <style>
            body {
                background: #f5f6f8;
                color: #1f2937;
                font-family: Arial, sans-serif;
                margin: 0;
                padding: 32px;
            }
            main {
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 16px 40px rgba(15, 23, 42, .12);
                margin: 0 auto;
                max-width: 780px;
                padding: 28px;
            }
            h1 {
                margin: 0 0 8px;
            }
            p {
                color: #64748b;
                line-height: 1.5;
            }
            form {
                margin: 24px 0;
            }
            button, a.button {
                background: #db0335;
                border: 0;
                border-radius: 999px;
                color: #fff;
                cursor: pointer;
                display: inline-block;
                font-weight: 700;
                padding: 11px 18px;
                text-decoration: none;
            }
            .messages {
                display: grid;
                gap: 10px;
                margin-top: 20px;
            }
            .message {
                border-left: 4px solid #94a3b8;
                border-radius: 10px;
                padding: 12px 14px;
            }
            .success { background: #ecfdf3; border-color: #16a34a; color: #14532d; }
            .danger { background: #fef2f2; border-color: #dc2626; color: #7f1d1d; }
            .info { background: #eff6ff; border-color: #2563eb; color: #1e3a8a; }
            code {
                background: #f1f5f9;
                border-radius: 6px;
                padding: 2px 6px;
            }
        </style>
    </head>
    <body>
        <main>
            <h1>Analytics Admin Session Migration</h1>
            <p>Adds the columns required to identify admin analytics sessions: <code>is_admin_session</code> and <code>admin_username</code>.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo migrationEscape($token); ?>">
                <button type="submit">Run Migration</button>
                <a class="button" href="/admin/analytics.php" style="background:#334155;margin-left:8px;">Back to Analytics</a>
            </form>
            <?php if ($ran): ?>
                <div class="messages">
                    <?php foreach ($messages as $message): ?>
                        <div class="message <?php echo migrationEscape($message[0]); ?>">
                            <?php echo migrationEscape($message[1]); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </body>
    </html>
    <?php
}

if ($isCli) {
    [$success, $messages] = runAnalyticsAdminSessionMigration();
    echo migrationTextOutput($messages);
    exit($success ? 0 : 1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!\FAS\Utils\CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        renderMigrationPage(true, false, [['danger', 'Security token expired. Refresh and try again.']]);
        exit;
    }

    [$success, $messages] = runAnalyticsAdminSessionMigration();
    renderMigrationPage(true, $success, $messages);
    exit;
}

renderMigrationPage(false, true, []);
