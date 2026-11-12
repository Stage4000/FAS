<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/blog/BlogRepository.php';
use FAS\Blog\BlogContent;
use FAS\Blog\BlogRepository;

/** No application bootstrap, config loading, fallback database, or CREATE open flag. */
function blogMaintenanceConnection(string $path, bool $write = false): PDO
{
    $db = new PDO('sqlite:'.$path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::SQLITE_ATTR_OPEN_FLAGS => $write ? PDO::SQLITE_OPEN_READWRITE : PDO::SQLITE_OPEN_READONLY,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');
    // Connection-local durability setting, not a journal-mode conversion.
    $db->exec('PRAGMA synchronous = FULL');
    return $db;
}

function blogMaintenanceDatabasePath(string $path): string
{
    clearstatcache(true);
    $absolute = PHP_OS_FAMILY === 'Windows' ? preg_match('~\A[A-Za-z]:[/\\\\]~', $path) : str_starts_with($path, '/');
    if (!$absolute || str_contains($path, "\0") || !is_file($path) || is_link($path)) {
        throw new RuntimeException('Supply an explicit absolute path to an EXISTING regular SQLite database; no database will be created.');
    }
    $resolved = realpath($path);
    $comparable = static function (string $value): string {
        return PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $value)) : $value;
    };
    if ($resolved === false || $comparable($resolved) !== $comparable($path)) {
        throw new RuntimeException('Use the canonical absolute database path without symlinks or traversal.');
    }
    return $resolved;
}

function blogMaintenanceSourceState(string $path, array $original, $handle): void
{
    blogMaintenanceDatabasePath($path);
    $current = lstat($path);
    $held = fstat($handle);
    if (!$current || !$held || ($current['mode'] & 0170000) !== 0100000
        || [$current['dev'], $current['ino']] !== [$original['dev'], $original['ino']]
        || [$held['dev'], $held['ino']] !== [$original['dev'], $original['ino']]) {
        throw new RuntimeException('Source database identity changed; stop and investigate.');
    }
}

function blogMaintenanceCheck(PDO $db, bool $required = false): array
{
    $version = (int)$db->query('PRAGMA data_version')->fetchColumn();
    if ($db->query('PRAGMA main.integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
        throw new RuntimeException('SQLite integrity check failed; stop and investigate.');
    }
    foreach (['products'=>['id','description','is_active'],
              'admin_users'=>['id','username','password_hash','role','is_active']] as $table=>$columns) {
        $object = $db->prepare('SELECT type FROM main.sqlite_master WHERE name=?');
        $object->execute([$table]);
        $actual = array_column($db->query('PRAGMA main.table_info('.$table.')')->fetchAll(), 'name');
        if ($object->fetchColumn() !== 'table' || array_diff($columns, $actual)) {
            throw new RuntimeException('Required inventory/auth table schema is missing: '.$table);
        }
    }
    if ($db->query('PRAGMA main.foreign_key_check')->fetch() !== false) {
        throw new RuntimeException('SQLite foreign-key check failed; review existing data.');
    }
    $installed = BlogRepository::installed($db);
    if ($required && !$installed) { throw new RuntimeException('Blog storage has not been initialized.'); }
    $result = ['installed'=>$installed, 'posts'=>0, 'published'=>0, 'history'=>0];
    if ($installed) {
        foreach ($db->query('SELECT draft_json,published_json,slug FROM main.blog_posts') as $row) {
            foreach (['draft_json'=>false,'published_json'=>true] as $column=>$publishing) {
                if ($row[$column] === null && $publishing) { continue; }
                $content = json_decode($row[$column], true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($content) || array_is_list($content)) { throw new RuntimeException('Stored blog content must be a JSON object.'); }
                $content = BlogContent::validate($content, $publishing);
                if ($row['slug'] !== null && $content['slug'] !== $row['slug']) {
                    throw new RuntimeException('Stored blog canonical slug is inconsistent.');
                }
            }
            $result['posts']++;
            if ($row['published_json'] !== null) { $result['published']++; }
        }
        $result['history'] = (int)$db->query('SELECT COUNT(*) FROM main.blog_history')->fetchColumn();
        if ($db->query('SELECT p.id FROM main.blog_posts p LEFT JOIN main.blog_history h ON h.post_id=p.id
            GROUP BY p.id HAVING MIN(h.revision) IS NOT 1 OR MAX(h.revision) IS NOT p.revision
            OR COUNT(h.id) IS NOT p.revision LIMIT 1')->fetchColumn() !== false) {
            throw new RuntimeException('Blog revision history is incomplete or inconsistent.');
        }
    }
    if ((int)$db->query('PRAGMA data_version')->fetchColumn() !== $version) {
        throw new RuntimeException('Database changed during checks; retry in a quiet maintenance window.');
    }
    return $result;
}

/** Deliberately POSIX-only: never infer private access from Windows permission bits. */
function blogMaintenanceBackupTarget(string $path, bool $new = true): array
{
    clearstatcache(true);
    if (PHP_OS_FAMILY === 'Windows' || $path === '' || $path[0] !== '/' || str_contains($path, "\0")
        || str_contains($path, '\\') || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//~', $path)) {
        throw new RuntimeException('Supply a NEW absolute backup filename in a private POSIX directory outside the web root.');
    }
    $part = '';
    $directories = [];
    foreach (explode('/', ltrim($path, '/')) as $segment) {
        $part .= '/'.$segment;
        if (is_link($part)) { throw new RuntimeException('Backup paths must not contain symlinks.'); }
        if ($part !== $path && is_dir($part)) {
            $stat = lstat($part);
            if (!$stat || (($stat['mode'] & 0022) && !($stat['mode'] & 01000))) {
                throw new RuntimeException('Backup ancestry must not be group/other-writable unless protected by the sticky bit.');
            }
            $directories[$part] = [$stat['dev'], $stat['ino'], $stat['uid'], $stat['mode']];
        }
    }
    $dir = realpath(dirname($path));
    // The complete checkout is treated as potentially served, not only /public.
    $root = realpath(__DIR__.'/..');
    if (!$dir || !$root || !is_writable($dir) || (fileperms($dir) & 0777) !== 0700
        || $dir !== dirname($path) || $dir === $root || str_starts_with($dir, $root.'/') || ($new && file_exists($path))) {
        throw new RuntimeException('Backup needs a NEW filename in an existing writable 0700 directory outside the web root.');
    }
    return $directories;
}

function blogMaintenanceBackupState(string $path, array $directories, array $reserved, $directoryHandle): array
{
    if (blogMaintenanceBackupTarget($path, false) !== $directories) {
        throw new RuntimeException('Backup directory identity changed; stop and investigate the private path.');
    }
    $held = fstat($directoryHandle);
    $parent = $directories[dirname($path)];
    $saved = lstat($path);
    if (!$held || [$held['dev'], $held['ino']] !== [$parent[0], $parent[1]]
        || !$saved || is_link($path) || ($saved['mode'] & 0170000) !== 0100000 || $saved['nlink'] !== 1
        || $saved['ino'] !== $reserved['ino'] || $saved['dev'] !== $reserved['dev']
        || $saved['uid'] !== $reserved['uid'] || ($saved['mode'] & 0777) !== 0600) {
        throw new RuntimeException('Backup file or directory verification failed.');
    }
    return $saved;
}

$command = $argv[1] ?? 'help';
if (!in_array($command, ['init','status','check'], true) || count($argv) !== ($command === 'init' ? 4 : 3)) {
    echo "Usage: php scripts/blog-maintenance.php status|check /absolute/EXISTING.sqlite\n"
        ."       php scripts/blog-maintenance.php init /absolute/EXISTING.sqlite /private/0700-directory/NEW-backup.sqlite\n";
    exit($command === 'help' ? 0 : 1);
}

$backup = null;
$backupReady = false;
$installReturned = false;
try {
    if (PHP_VERSION_ID < 80100 || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PHP 8.1 or newer with pdo_sqlite is required.');
    }
    $path = blogMaintenanceDatabasePath($argv[2]);
    $sourceHandle = fopen($path, 'rb');
    if ($sourceHandle === false || ($sourceIdentity = fstat($sourceHandle)) === false) {
        throw new RuntimeException('Could not open the existing source database.');
    }
    blogMaintenanceSourceState($path, $sourceIdentity, $sourceHandle);
    $db = blogMaintenanceConnection($path);
    $version = (int)$db->query('PRAGMA data_version')->fetchColumn();
    $result = blogMaintenanceCheck($db, $command === 'check');
    if ($command === 'init') {
        $directories = blogMaintenanceBackupTarget($argv[3]);
        $backup = $argv[3];
        $directoryHandle = fopen(dirname($backup), 'r');
        if ($directoryHandle === false) { throw new RuntimeException('Could not open the private backup directory.'); }
        $oldMask = umask(0077);
        try { $handle = fopen($backup, 'x+b'); } finally { umask($oldMask); }
        if ($handle === false) { throw new RuntimeException('Could not exclusively reserve the new backup file.'); }
        $reserved = fstat($handle);
        fclose($handle);
        if ($reserved === false) { throw new RuntimeException('Could not inspect the reserved backup file.'); }
        foreach ($directories as $directory) {
            if (!in_array($directory[2], [0, $reserved['uid']], true)) {
                throw new RuntimeException('Backup directories must be owned by this user or root.');
            }
        }
        if ($directories[dirname($backup)][2] !== $reserved['uid']) {
            throw new RuntimeException('The private backup directory must belong to this user.');
        }
        blogMaintenanceBackupState($backup, $directories, $reserved, $directoryHandle);
        // VACUUM INTO takes a consistent snapshot including committed WAL content.
        // It does not include later writes or replace a quiesced maintenance window.
        $db->exec('VACUUM INTO '.$db->quote($backup));
        $saved = blogMaintenanceBackupState($backup, $directories, $reserved, $directoryHandle);
        if ($saved['size'] === 0) { throw new RuntimeException('Backup is empty.'); }
        $snapshot = blogMaintenanceConnection($backup);
        blogMaintenanceCheck($snapshot);
        $schemaQuery = "SELECT type,name,tbl_name,sql FROM main.sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name";
        if ($snapshot->query($schemaQuery)->fetchAll() !== $db->query($schemaQuery)->fetchAll()) {
            throw new RuntimeException('Database schema changed during backup; retry in a quiet maintenance window.');
        }
        $snapshot = null;
        blogMaintenanceBackupState($backup, $directories, $reserved, $directoryHandle);
        // VACUUM INTO does not promise to flush the destination. Flush both the
        // verified file and directory entry before treating it as a usable backup.
        $flushHandle = fopen($backup, 'r+b');
        if ($flushHandle === false) { throw new RuntimeException('Could not reopen the backup for synchronization.'); }
        try {
            $flushStat = fstat($flushHandle);
            if (!$flushStat || [$flushStat['dev'], $flushStat['ino']] !== [$reserved['dev'], $reserved['ino']]
                || !fsync($flushHandle) || !fsync($directoryHandle)) {
                throw new RuntimeException('Backup file/directory synchronization failed; no initialization performed.');
            }
        } finally { fclose($flushHandle); }
        blogMaintenanceBackupState($backup, $directories, $reserved, $directoryHandle);
        $checksum = hash_file('sha256', $backup);
        if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/D', $checksum)) {
            throw new RuntimeException('Backup checksum could not be verified.');
        }
        blogMaintenanceBackupState($backup, $directories, $reserved, $directoryHandle);
        $backupReady = true;
        $result['backup'] = $backup;
        $result['backup_sha256'] = $checksum;
        $result['backup_bytes'] = $saved['size'];
        blogMaintenanceSourceState($path, $sourceIdentity, $sourceHandle);
        $write = blogMaintenanceConnection($path, true);
        blogMaintenanceCheck($write);
        if ((int)$db->query('PRAGMA data_version')->fetchColumn() !== $version) {
            throw new RuntimeException('Database changed after preflight; no initialization performed. Retry in a quiet maintenance window.');
        }
        blogMaintenanceBackupState($backup, $directories, $reserved, $directoryHandle);
        blogMaintenanceSourceState($path, $sourceIdentity, $sourceHandle);
        // install() owns BEGIN IMMEDIATE/COMMIT/ROLLBACK. Never wrap it in a transaction.
        BlogRepository::install($write);
        $installReturned = true;
        $result = array_merge($result, blogMaintenanceCheck($write, true));
        fclose($directoryHandle);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    if ($installReturned) { fwrite(STDERR, "Initialization returned successfully, but the subsequent check failed. Do not assume it was rolled back.\n"); }
    if ($backup !== null && file_exists($backup)) {
        // Never restore over live data or remove a possibly useful snapshot automatically.
        fwrite(STDERR, ($backupReady ? 'Backup retained: ' : 'Unverified backup file retained; do not use for restore: ').$backup.PHP_EOL);
    }
    exit(1);
}
