<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/ProductContent.php';
use FAS\Utils\ProductContent;

function editorialConnection(string $path, bool $write = false): PDO
{
    // Never create an empty inventory when the configured path is wrong.
    $db = new PDO('sqlite:'.$path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLITE_ATTR_OPEN_FLAGS => $write ? PDO::SQLITE_OPEN_READWRITE : PDO::SQLITE_OPEN_READONLY,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

function editorialCheck(PDO $db, bool $required = false): array
{
    if ($db->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
        throw new RuntimeException('SQLite integrity check failed; no initialization performed.');
    }
    foreach (['products'=>['id','description','is_active'],
              'admin_users'=>['id','username','password_hash','role','is_active']] as $table=>$columns) {
        $actual = array_column($db->query('PRAGMA table_info('.$table.')')->fetchAll(), 'name');
        if (array_diff($columns, $actual)) throw new RuntimeException('Required inventory/auth schema is missing: '.$table);
    }
    $objects = $db->query("SELECT name,type,tbl_name FROM sqlite_master WHERE name IN
        ('product_content_reviews','product_content_history','product_content_history_product')")->fetchAll();
    if (!$objects) {
        if ($required) throw new RuntimeException('Editorial storage has not been initialized.');
        return ['installed'=>false, 'reviews'=>0];
    }
    $expected = [
        'product_content_reviews'=>['table','product_content_reviews'],
        'product_content_history'=>['table','product_content_history'],
        'product_content_history_product'=>['index','product_content_history'],
    ];
    if (count($objects) !== count($expected)) throw new RuntimeException('Editorial schema is incomplete; review it before initialization.');
    foreach ($objects as $object) {
        if ([$object['type'],$object['tbl_name']] !== $expected[$object['name']]) {
            throw new RuntimeException('Unexpected editorial schema object: '.$object['name']);
        }
    }
    $schemas = [
        'product_content_reviews'=>[
            'product_id'=>['INTEGER',0,1], 'revision'=>['INTEGER',1,0], 'draft_json'=>['TEXT',1,0],
            'published_json'=>['TEXT',0,0], 'source_hash'=>['TEXT',1,0], 'reviewed_by'=>['INTEGER',0,0],
            'reviewed_at'=>['TEXT',0,0], 'updated_at'=>['TEXT',1,0],
        ],
        'product_content_history'=>[
            'id'=>['INTEGER',0,1], 'product_id'=>['INTEGER',1,0], 'revision'=>['INTEGER',1,0],
            'action'=>['TEXT',1,0], 'actor_id'=>['INTEGER',1,0], 'created_at'=>['TEXT',1,0],
        ],
    ];
    foreach ($schemas as $table=>$schema) {
        $actual = [];
        foreach ($db->query('PRAGMA table_info('.$table.')') as $column) {
            $actual[$column['name']] = [strtoupper($column['type']), (int)$column['notnull'], (int)$column['pk']];
        }
        foreach ($schema as $name=>$shape) {
            if (($actual[$name] ?? null) !== $shape) throw new RuntimeException('Unexpected editorial column: '.$table.'.'.$name);
        }
        if (array_diff_key($actual, $schema)) throw new RuntimeException('Unsupported extra editorial columns: '.$table);
    }
    $index = $db->query('PRAGMA index_info(product_content_history_product)')->fetchAll();
    if (array_column($index, 'name') !== ['product_id','id']) throw new RuntimeException('Unexpected editorial history index.');
    foreach ($db->query('PRAGMA index_list(product_content_history)') as $row) {
        if ($row['name'] === 'product_content_history_product' && ($row['unique'] || $row['partial'])) {
            throw new RuntimeException('Unexpected editorial history index constraints.');
        }
    }
    $count = 0;
    foreach ($db->query('SELECT draft_json,published_json FROM product_content_reviews') as $row) {
        ProductContent::validate(ProductContent::decode($row['draft_json']), false);
        if ($row['published_json'] !== null) ProductContent::validate(ProductContent::decode($row['published_json']), true);
        $count++;
    }
    return ['installed'=>true, 'reviews'=>$count];
}

function editorialBackupTarget(string $path, bool $new = true): array
{
    // Invalidate directory realpaths as well as leaf stats on every validation.
    clearstatcache(true);
    // POSIX permissions are deliberate: fail closed where private access cannot be verified.
    if (PHP_OS_FAMILY === 'Windows' || $path === '' || $path[0] !== '/' || str_contains($path, "\0")
        || str_contains($path, '\\') || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//~', $path)) {
        throw new RuntimeException('Supply a NEW absolute backup filename in a private POSIX directory outside the web root.');
    }
    $part = '';
    $directories = [];
    foreach (explode('/', ltrim($path, '/')) as $segment) {
        $part .= '/'.$segment;
        if (is_link($part)) throw new RuntimeException('Backup paths must not contain symlinks.');
        if ($part !== $path && is_dir($part)) {
            $stat = lstat($part);
            if (($stat['mode'] & 0022) && !($stat['mode'] & 01000)) {
                throw new RuntimeException('Backup ancestry must not be group/other-writable unless protected by the sticky bit.');
            }
            $directories[$part] = [$stat['dev'], $stat['ino'], $stat['uid'], $stat['mode']];
        }
    }
    $dir = realpath(dirname($path));
    $root = realpath(__DIR__.'/..');
    if (!$dir || !is_writable($dir) || (fileperms($dir) & 0777) !== 0700
        || $dir !== dirname($path) || $dir === $root || str_starts_with($dir, $root.'/') || ($new && file_exists($path))) {
        throw new RuntimeException('Backup needs a NEW filename in an existing writable 0700 directory outside the web root.');
    }
    return $directories;
}

function editorialBackupState(string $path, array $directories, array $reserved, $directoryHandle): array
{
    if (editorialBackupTarget($path, false) !== $directories) {
        throw new RuntimeException('Backup directory identity changed; stop and investigate the private path.');
    }
    $held = fstat($directoryHandle);
    $parent = $directories[dirname($path)];
    $saved = lstat($path);
    if (!$held || [$held['dev'], $held['ino']] !== [$parent[0], $parent[1]]
        || !$saved || is_link($path) || ($saved['mode'] & 0170000) !== 0100000
        || $saved['ino'] !== $reserved['ino'] || $saved['dev'] !== $reserved['dev']
        || $saved['uid'] !== $reserved['uid'] || ($saved['mode'] & 0777) !== 0600) {
        throw new RuntimeException('Backup file or directory verification failed.');
    }
    return $saved;
}

$command = $argv[1] ?? 'help';
if (!in_array($command, ['init','preflight','health'], true) || count($argv) !== ($command === 'init' ? 3 : 2)) {
    echo "Usage: php scripts/product-content-maintenance.php preflight|health\n"
        ."       php scripts/product-content-maintenance.php init /private/0700-directory/NEW-backup.sqlite\n";
    exit($command === 'help' ? 0 : 1);
}

$backup = null;
$backupReady = false;
try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !extension_loaded('mbstring')) {
        throw new RuntimeException('PHP pdo_sqlite and mbstring are required.');
    }
    $configFile = __DIR__.'/../src/config/config.php';
    if (!is_file($configFile)) throw new RuntimeException('Existing config.php was not found.');
    $config = require $configFile;
    $configuredPath = $config['database']['path'] ?? __DIR__.'/../database/flipandstrip.db';
    if (!is_string($configuredPath) || !is_file($configuredPath)) {
        throw new RuntimeException('Existing inventory database was not found; refusing to create one.');
    }
    $path = realpath($configuredPath);
    $db = editorialConnection($path);
    $result = editorialCheck($db, $command === 'health');
    if ($command === 'init') {
        $directories = editorialBackupTarget($argv[2]);
        $backup = $argv[2];
        // Keep the original directory inode open while repeatedly checking its path identity.
        $directoryHandle = fopen(dirname($backup), 'r');
        if ($directoryHandle === false) throw new RuntimeException('Could not open the private backup directory.');
        // Reserve exclusively, with private permissions from creation, even under a permissive shell umask.
        $oldMask = umask(0077);
        try { $handle = fopen($backup, 'x+b'); } finally { umask($oldMask); }
        if ($handle === false) throw new RuntimeException('Could not exclusively reserve the new backup file.');
        $reserved = fstat($handle);
        fclose($handle);
        foreach ($directories as $directory) {
            if (!in_array($directory[2], [0, $reserved['uid']], true)) {
                throw new RuntimeException('Backup directories must be owned by this user or root.');
            }
        }
        if ($directories[dirname($backup)][2] !== $reserved['uid']) {
            throw new RuntimeException('The private backup directory must belong to this user.');
        }
        editorialBackupState($backup, $directories, $reserved, $directoryHandle);
        // Unlike copying the main file, VACUUM INTO includes committed WAL content consistently.
        $db->exec('VACUUM INTO '.$db->quote($backup));
        $saved = editorialBackupState($backup, $directories, $reserved, $directoryHandle);
        if ($saved['size'] === 0) throw new RuntimeException('Backup is empty.');
        $snapshot = editorialConnection($backup);
        editorialCheck($snapshot);
        $schemaQuery = "SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name";
        if ($snapshot->query($schemaQuery)->fetchAll() !== $db->query($schemaQuery)->fetchAll()) {
            throw new RuntimeException('Inventory schema changed during backup; retry in a quiet maintenance window.');
        }
        $snapshot = null;
        editorialBackupState($backup, $directories, $reserved, $directoryHandle);
        $checksum = hash_file('sha256', $backup);
        if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/D', $checksum)) {
            throw new RuntimeException('Backup checksum could not be verified.');
        }
        editorialBackupState($backup, $directories, $reserved, $directoryHandle);
        $result['backup'] = $backup;
        $result['backup_sha256'] = $checksum;
        $result['backup_bytes'] = $saved['size'];
        $backupReady = true;
        $db = editorialConnection($path, true);
        $db->beginTransaction();
        editorialCheck($db);
        ProductContent::install($db);
        $result = array_merge($result, editorialCheck($db, true));
        $db->commit();
        fclose($directoryHandle);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    if ($backup !== null && file_exists($backup)) {
        // Never automatically restore over a live database or remove a potentially useful snapshot.
        fwrite(STDERR, ($backupReady ? 'Backup retained: ' : 'Unverified backup file retained; do not use for restore: ').$backup.PHP_EOL);
    }
    exit(1);
}
