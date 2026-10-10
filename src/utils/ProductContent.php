<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Editorial content is independent of source inventory and import writes. */
final class ProductContent
{
    public static function installed(\PDO $db): bool
    {
        return (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='product_content_reviews'")->fetchColumn();
    }

    public static function install(\PDO $db): void
    {
        if (PHP_SAPI !== 'cli') throw new \RuntimeException('Initialize editorial storage from the CLI.');
        // Maintenance may own the transaction so post-install checks can roll back DDL.
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
        $db->exec("CREATE TABLE IF NOT EXISTS product_content_reviews (
            product_id INTEGER PRIMARY KEY, revision INTEGER NOT NULL DEFAULT 0,
            draft_json TEXT NOT NULL, published_json TEXT, source_hash TEXT NOT NULL DEFAULT '',
            reviewed_by INTEGER, reviewed_at TEXT, updated_at TEXT NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS product_content_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL,
            revision INTEGER NOT NULL, action TEXT NOT NULL, actor_id INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS product_content_history_product ON product_content_history(product_id,id)');
            if ($ownsTransaction) $db->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction) $db->rollBack();
            throw $e;
        }
    }

    public static function sourceHash(array $product): string
    {
        $source = [];
        foreach (['id','source','ebay_item_id','name','description','sku','manufacturer','model',
            'condition_name','category','ebay_store_cat1_id','ebay_store_cat1_name',
            'ebay_store_cat2_id','ebay_store_cat2_name','ebay_store_cat3_id','ebay_store_cat3_name',
            'image_url','images'] as $key) {
            $source[$key] = (string)($product[$key] ?? '');
        }
        return hash('sha256', json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function get(\PDO $db, int $id): ?array
    {
        if (!self::installed($db)) return null;
        $stmt = $db->prepare('SELECT * FROM product_content_reviews WHERE product_id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function all(\PDO $db): array
    {
        if (!self::installed($db)) return [];
        $result = [];
        foreach ($db->query('SELECT * FROM product_content_reviews')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['product_id']] = $row;
        }
        return $result;
    }

    public static function decode(?string $json): array
    {
        $data = $json === null ? [] : json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid editorial content record.');
        return $data;
    }

    public static function applyPublished(\PDO $db, array $products): array
    {
        if (!$products || !self::installed($db)) return $products;
        $ids = array_unique(array_map(static fn($p)=>(int)$p['id'], $products));
        $published = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $query = $db->prepare('SELECT product_id,published_json FROM product_content_reviews WHERE published_json IS NOT NULL AND product_id IN ('.implode(',', array_fill(0,count($chunk),'?')).')');
            $query->execute($chunk);
            foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $published[(int)$row['product_id']] = self::validate(self::decode($row['published_json']),true);
            }
        }
        foreach ($products as &$product) {
            if (isset($published[(int)$product['id']])) {
                $content = $published[(int)$product['id']];
                $product['storefront_description'] = $content['description'];
                $product['seo_title'] = $content['seo_title'];
                $product['seo_description'] = $content['seo_description'];
            }
        }
        unset($product);
        return $products;
    }

    public static function validate(array $input, bool $publishing): array
    {
        $data = [];
        foreach (['description'=>5000, 'seo_title'=>110, 'seo_description'=>160] as $field=>$max) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || !mb_check_encoding($value,'UTF-8')) {
                throw new \InvalidArgumentException('Enter valid text in each content field.');
            }
            $value = trim(str_replace(["\r\n","\r"],"\n",$value));
            if (mb_strlen($value,'UTF-8') > $max) {
                throw new \InvalidArgumentException(ucfirst(str_replace('_',' ',$field)).' exceeds '.$max.' characters.');
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|<\/?[a-z][^>]*>/i',$value)) {
                throw new \InvalidArgumentException('Use plain text without HTML or control characters.');
            }
            if ($field !== 'description') $value = preg_replace('/\s+/u',' ',$value);
            $data[$field] = $value;
        }
        if ($publishing && $data['description'] === '') {
            throw new \InvalidArgumentException('Add a verified product description before publishing.');
        }
        return $data;
    }

    /** Optimistic revisions and source snapshots prevent silently overwriting another review. */
    public static function save(\PDO $db, int $id, int $revision, string $sourceHash, string $action, array $input, int $actor): void
    {
        if (!in_array($action,['draft','publish','withdraw'],true)) throw new \InvalidArgumentException('Unknown content action.');
        if ($id < 1 || $revision < 0 || $actor < 1) throw new \InvalidArgumentException('Invalid review reference.');
        $data = $action === 'withdraw' ? [] : self::validate($input,$action === 'publish');
        if (!self::installed($db)) throw new \RuntimeException('Editorial storage is not initialized.');
        $db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $db->prepare('SELECT * FROM products WHERE id=? AND is_active=1');
            $stmt->execute([$id]);
            $product = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$product) throw new \InvalidArgumentException('This active product is no longer available.');
            if (!hash_equals(self::sourceHash($product),$sourceHash)) {
                throw new \DomainException('The source listing changed. Reload and compare the latest source before saving.');
            }
            $current = self::get($db,$id);
            if ((int)($current['revision'] ?? 0) !== $revision) {
                throw new \DomainException('Another administrator saved this review. Reload before saving your changes.');
            }
            $draft = $action === 'withdraw' ? ($current['draft_json'] ?? '{}') : json_encode($data,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $published = $current['published_json'] ?? null;
            if ($action === 'publish') $published = $draft;
            if ($action === 'withdraw') $published = null;
            $now = gmdate('Y-m-d H:i:s');
            $reviewer = $action === 'publish' ? $actor : ($current['reviewed_by'] ?? null);
            $reviewed = $action === 'publish' ? $now : ($current['reviewed_at'] ?? null);
            $publishedHash = $action === 'publish' ? $sourceHash : ($current['source_hash'] ?? '');
            $stmt = $db->prepare('INSERT INTO product_content_reviews
                (product_id,revision,draft_json,published_json,source_hash,reviewed_by,reviewed_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(product_id) DO UPDATE SET
                revision=excluded.revision,draft_json=excluded.draft_json,published_json=excluded.published_json,
                source_hash=excluded.source_hash,reviewed_by=excluded.reviewed_by,reviewed_at=excluded.reviewed_at,updated_at=excluded.updated_at');
            $stmt->execute([$id,$revision+1,$draft,$published,$publishedHash,$reviewer,$reviewed,$now]);
            $db->prepare('INSERT INTO product_content_history(product_id,revision,action,actor_id,created_at) VALUES(?,?,?,?,?)')
                ->execute([$id,$revision+1,$action,$actor,$now]);
            $db->prepare('DELETE FROM product_content_history WHERE product_id=? AND id NOT IN (SELECT id FROM product_content_history WHERE product_id=? ORDER BY id DESC LIMIT 100)')
                ->execute([$id,$id]);
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    public static function state(array $product, ?array $review): string
    {
        if (!$review) return 'Not reviewed';
        if ($review['published_json'] === null) return 'Draft only';
        if (!hash_equals($review['source_hash'],self::sourceHash($product))) return 'Source changed';
        if ($review['draft_json'] !== $review['published_json']) return 'Unpublished changes';
        return 'Reviewed';
    }
}
