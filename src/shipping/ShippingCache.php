<?php
declare(strict_types=1);
namespace FAS\Shipping;

/** Private, bounded cache. No customer addresses or carrier response bodies are stored. */
final class ShippingCache
{
    private \PDO $db;

    public function __construct(string $path, bool $initialize = false)
    {
        if ($path === '' || strpos($path, "\0") !== false || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) {
            throw new \RuntimeException('An absolute private shipping cache path is required.');
        }
        // Resolve the real parent, including symlinks, before opening or creating storage.
        $parent = realpath(dirname($path));
        if ($parent === false) throw new \RuntimeException('Create the private shipping cache directory first.');
        $root = realpath(dirname(__DIR__, 2));
        $normalize = static fn($p)=>strtolower(rtrim(str_replace('\\','/',$p),'/'));
        $target = $normalize(is_file($path) ? (realpath($path) ?: $path) : $parent.'/'.basename($path));
        $roots = [$root, $_SERVER['DOCUMENT_ROOT'] ?? ''];
        foreach ($roots as $webRoot) {
            if ($webRoot && ($resolved = realpath($webRoot))) {
                $web = $normalize($resolved);
                if ($target === $web || strpos($target,$web.'/') === 0) {
                    throw new \RuntimeException('Shipping cache must be outside the web root.');
                }
            }
        }
        if ($initialize && PHP_SAPI !== 'cli') throw new \RuntimeException('Initialize shipping storage from the CLI.');
        if (!$initialize && !is_file($path)) throw new \RuntimeException('Shipping cache is not initialized.');
        $oldMask = umask(0077);
        try {
            $this->db = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            $this->db->exec('PRAGMA busy_timeout=1000');
            if ($initialize) {
                $this->db->exec('CREATE TABLE IF NOT EXISTS shipping_cache(cache_key TEXT PRIMARY KEY, payload TEXT NOT NULL, expires INTEGER NOT NULL)');
                $this->db->exec('CREATE INDEX IF NOT EXISTS shipping_cache_expiry ON shipping_cache(expires)');
                chmod($path, 0600);
            }
            $this->db->query('SELECT cache_key FROM shipping_cache LIMIT 1');
        } finally { umask($oldMask); }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0077)) {
            throw new \RuntimeException('Shipping cache must have owner-only permissions.');
        }
    }

    public function get(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT payload FROM shipping_cache WHERE cache_key=? AND expires>?');
        $stmt->execute([$key,time()]);
        $value = $stmt->fetchColumn();
        if ($value === false) return null;
        $data = json_decode($value,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid shipping cache record.');
        return $data;
    }

    public function put(string $key, array $value, int $ttl): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT OR REPLACE INTO shipping_cache VALUES(?,?,?)');
            $stmt->execute([$key,json_encode($value,JSON_THROW_ON_ERROR),time()+max(1,min(86400,$ttl))]);
            $this->db->exec('DELETE FROM shipping_cache WHERE cache_key IN (SELECT cache_key FROM shipping_cache ORDER BY expires DESC LIMIT -1 OFFSET 1000)');
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function forget(string $key): void
    {
        $stmt=$this->db->prepare('DELETE FROM shipping_cache WHERE cache_key=?'); $stmt->execute([$key]);
    }

    public function cleanup(): int
    {
        return $this->db->exec('DELETE FROM shipping_cache WHERE cache_key IN (SELECT cache_key FROM shipping_cache WHERE expires<='.time().' LIMIT 200)');
    }

    public function health(): array
    {
        return ['healthy'=>$this->db->query('PRAGMA quick_check')->fetchColumn()==='ok',
            'entries'=>(int)$this->db->query('SELECT COUNT(*) FROM shipping_cache')->fetchColumn()];
    }

    /** Share the validated private connection with fulfillment operation storage. */
    public function database(): \PDO
    {
        return $this->db;
    }
}
