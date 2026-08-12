<?php
/**
 * Product Model
 * Handles product data and database operations
 */

namespace FAS\Models;

class Product
{
    private $db;
    private $mappingModel = null; // Cache for HomepageCategoryMapping model
    private static $freeShippingColumnEnsured = false;

    /**
     * Gift-specific keywords for category mapping (Priority 1)
     * These should be checked first to prevent brand names from overriding gift classification
     */
    private const GIFT_KEYWORDS = [
        'gift', 'apparel', 'clothing', 'shirt', 'hat', 'watch',
        'collectible', 'memorabilia', 'keychain', 'accessory'
    ];

    /**
     * Parts-specific category mappings (Priority 2)
     * Only checked if no gift keywords are found
     */
    private const PARTS_CATEGORY_MAPPINGS = [
        'motorcycle' => ['motorcycle', 'motorbike', 'bike', 'harley', 'honda', 'yamaha', 'kawasaki', 'suzuki', 'ducati', 'triumph'],
        'atv' => ['atv', 'utv', 'quad', 'four wheeler', 'side by side', 'polaris', 'can-am', 'arctic cat'],
        'boat' => ['boat', 'marine', 'watercraft', 'jet ski', 'outboard', 'inboard', 'yacht', 'fishing', 'nautical'],
        'automotive' => ['auto', 'automobile', 'car', 'ford', 'chevy', 'chevrolet', 'dodge', 'gmc']
    ];

    private const SEARCH_SYNONYMS = [
        'motorcycle' => ['motorbike', 'bike', 'cycle', 'cycles', 'moto'],
        'atv' => ['quad', 'four wheeler', '4 wheeler', 'four-wheeler', '4-wheeler'],
        'utv' => ['side by side', 'side-by-side', 'sxs'],
        'boat' => ['marine', 'watercraft'],
        'jet ski' => ['jetski', 'pwc', 'sea doo', 'sea-doo', 'seadoo'],
        'automotive' => ['auto', 'car', 'truck', 'vehicle'],
        'brake' => ['brakes', 'break'],
        'carburetor' => ['carb', 'carburator', 'carbuerator', 'carby'],
        'handlebar' => ['handlebars', 'bars'],
        'headlight' => ['head light', 'lamp'],
        'taillight' => ['tail light', 'tail lamp'],
        'exhaust' => ['muffler', 'pipe'],
        'wheel' => ['rim', 'tire', 'tyre'],
        'seat' => ['saddle'],
        'fuel tank' => ['gas tank', 'petrol tank', 'tank'],
        'gauge' => ['speedometer', 'tachometer', 'cluster'],
        'engine' => ['motor'],
        'transmission' => ['gearbox'],
        'fender' => ['mudguard'],
        'cover' => ['cowling', 'cowl'],
        'harley' => ['harly', 'harley davidson', 'harley-davidson', 'hd'],
        'yamaha' => ['yammaha', 'yami'],
        'kawasaki' => ['kawi', 'kawaski'],
        'suzuki' => ['suzki'],
        'honda' => ['hondaa'],
        'polaris' => ['polris'],
        'can-am' => ['can am', 'canam'],
    ];

    private const SEARCH_CORRECTIONS = [
        'break' => 'brake',
        'braks' => 'brake',
        'carburator' => 'carburetor',
        'carbuerator' => 'carburetor',
        'carberator' => 'carburetor',
        'faring' => 'fairing',
        'handlebars' => 'handlebar',
        'hedlight' => 'headlight',
        'tailight' => 'taillight',
        'taillite' => 'taillight',
        'exhuast' => 'exhaust',
        'muffeler' => 'muffler',
        'harlet' => 'harley',
        'harly' => 'harley',
        'yammaha' => 'yamaha',
        'kawaski' => 'kawasaki',
        'suzki' => 'suzuki',
        'polris' => 'polaris',
        'seadoo' => 'sea doo',
        'jetski' => 'jet ski',
    ];

    public function __construct($db)
    {
        $this->db = $db;
        $this->ensureFreeShippingColumn();
    }

    private function ensureFreeShippingColumn(): void
    {
        if (self::$freeShippingColumnEnsured) {
            return;
        }

        try {
            $result = $this->db->query("PRAGMA table_info(products)");
            $columns = $result ? $result->fetchAll(\PDO::FETCH_ASSOC) : [];
            $hasColumn = false;

            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'free_shipping') {
                    $hasColumn = true;
                    break;
                }
            }

            if (!$hasColumn) {
                $this->db->exec("ALTER TABLE products ADD COLUMN free_shipping INTEGER NOT NULL DEFAULT 0");
            }

            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_products_free_shipping ON products(free_shipping)");
            self::$freeShippingColumnEnsured = true;
        } catch (\Throwable $e) {
            error_log('Product free shipping column check failed: ' . $e->getMessage());
        }
    }

    /**
     * Get database connection
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * Get all products with pagination (public website - only visible and active)
     */
    public function getAll($page = 1, $perPage = 24, $category = null, $search = null, $manufacturer = null)
    {
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT * FROM products WHERE is_active = 1 AND show_on_website = 1";
        $params = [];

        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        if ($manufacturer) {
            $sql .= " AND manufacturer = ?";
            $params[] = $manufacturer;
        }

        if ($search) {
            $sql .= " AND (name LIKE ? OR description LIKE ? OR sku LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all products for admin (includes hidden but excludes deleted)
     */
    public function getAllProducts($page = 1, $perPage = 20, $search = null, $sourceFilter = null, $visibilityFilter = null)
    {
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT * FROM products WHERE is_active = 1";
        $params = [];

        if ($search) {
            $sql .= " AND (name LIKE ? OR description LIKE ? OR sku LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if ($sourceFilter) {
            $sql .= " AND source = ?";
            $params[] = $sourceFilter;
        }

        if ($visibilityFilter === 'visible') {
            $sql .= " AND show_on_website = 1";
        } elseif ($visibilityFilter === 'hidden') {
            $sql .= " AND show_on_website = 0";
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get count of all products for admin (excludes deleted products)
     */
    public function getCountAll($search = null, $sourceFilter = null, $visibilityFilter = null)
    {
        $sql = "SELECT COUNT(*) as total FROM products WHERE is_active = 1";
        $params = [];

        if ($search) {
            $sql .= " AND (name LIKE ? OR description LIKE ? OR sku LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if ($sourceFilter) {
            $sql .= " AND source = ?";
            $params[] = $sourceFilter;
        }

        if ($visibilityFilter === 'visible') {
            $sql .= " AND show_on_website = 1";
        } elseif ($visibilityFilter === 'hidden') {
            $sql .= " AND show_on_website = 0";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result['total'];
    }

    /**
     * Get active products for admin data-quality reporting.
     */
    public function getProductsForQualityAudit()
    {
        $stmt = $this->db->query(
            "SELECT * FROM products
             WHERE is_active = 1
             ORDER BY show_on_website ASC, updated_at DESC, created_at DESC"
        );

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get total count of products (public website - only visible)
     */
    public function getCount($category = null, $search = null, $manufacturer = null)
    {
        $sql = "SELECT COUNT(*) as total FROM products WHERE is_active = 1 AND show_on_website = 1";
        $params = [];

        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        if ($manufacturer) {
            $sql .= " AND manufacturer = ?";
            $params[] = $manufacturer;
        }

        if ($search) {
            $sql .= " AND (name LIKE ? OR description LIKE ? OR sku LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result['total'];
    }

    /**
     * Get unique manufacturers for filtering
     */
    public function getManufacturers($includeHidden = false)
    {
        $sql = "SELECT DISTINCT manufacturer FROM products
                WHERE is_active = 1 AND manufacturer IS NOT NULL ";
        if (!$includeHidden) {
            $sql .= "AND show_on_website = 1 ";
        }
        $sql .= "
                ORDER BY manufacturer";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $manufacturers = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $manufacturers[] = $row['manufacturer'];
        }

        return $manufacturers;
    }

    /**
     * Get unique model names for storefront fitment filtering.
     */
    public function getModels($includeHidden = false, $manufacturer = null)
    {
        $sql = "SELECT DISTINCT model FROM products
                WHERE is_active = 1 AND model IS NOT NULL AND model != '' ";
        $params = [];

        if (!$includeHidden) {
            $sql .= "AND show_on_website = 1 ";
        }

        if ($manufacturer !== null && trim((string)$manufacturer) !== '') {
            $sql .= "AND manufacturer = ? ";
            $params[] = $manufacturer;
        }

        $sql .= "ORDER BY model";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $models = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $models[] = $row['model'];
        }

        return $models;
    }

    /**
     * Get product by ID
     */
    public function getById($id, $includeInactive = false)
    {
        $sql = "SELECT * FROM products WHERE id = ?";
        if (!$includeInactive) {
            $sql .= " AND is_active = 1";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Get product by eBay item ID
     */
    public function getByEbayId($ebayItemId)
    {
        $sql = "SELECT * FROM products WHERE ebay_item_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$ebayItemId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Create new product
     */
    public function create($data)
    {
        $sql = "INSERT INTO products (
            ebay_item_id, sku, name, description, price, sale_price, quantity, category,
            manufacturer, model, condition_name, weight, length, width, height, image_url, images, ebay_url, source, show_on_website, free_shipping,
            ebay_store_cat1_id, ebay_store_cat1_name, ebay_store_cat2_id, ebay_store_cat2_name, ebay_store_cat3_id, ebay_store_cat3_name
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([
            $data['ebay_item_id'] ?? null,
            $data['sku'] ?? null,
            $data['name'],
            $data['description'] ?? null,
            $data['price'],
            $data['sale_price'] ?? null,
            $data['quantity'] ?? 1,
            $data['category'] ?? null,
            $data['manufacturer'] ?? null,
            $data['model'] ?? null,
            $data['condition_name'] ?? null,
            $data['weight'] ?? null,
            $data['length'] ?? null,
            $data['width'] ?? null,
            $data['height'] ?? null,
            $data['image_url'] ?? null,
            isset($data['images']) ? json_encode($data['images']) : null,
            $data['ebay_url'] ?? null,
            $data['source'] ?? 'manual',
            isset($data['show_on_website']) ? $data['show_on_website'] : 1,
            isset($data['free_shipping']) ? (int)$data['free_shipping'] : 0,
            $data['ebay_store_cat1_id'] ?? null,
            $data['ebay_store_cat1_name'] ?? null,
            $data['ebay_store_cat2_id'] ?? null,
            $data['ebay_store_cat2_name'] ?? null,
            $data['ebay_store_cat3_id'] ?? null,
            $data['ebay_store_cat3_name'] ?? null
        ]);

        if ($result) {
            return $this->db->lastInsertId();
        }

        return false;
    }

    /**
     * Update product
     */
    public function update($id, $data)
    {
        $fields = [];
        $params = [];

        $allowedFields = [
            'sku', 'name', 'description', 'price', 'sale_price', 'quantity', 'category',
            'manufacturer', 'model', 'condition_name', 'weight', 'length', 'width', 'height',
            'image_url', 'images', 'ebay_url', 'source', 'show_on_website', 'free_shipping',
            'ebay_store_cat1_id', 'ebay_store_cat1_name', 'ebay_store_cat2_id', 'ebay_store_cat2_name',
            'ebay_store_cat3_id', 'ebay_store_cat3_name'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                if ($field === 'images' && is_array($data[$field])) {
                    $params[] = json_encode($data[$field]);
                } else {
                    $params[] = $data[$field];
                }
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Toggle website visibility
     */
    public function toggleWebsiteVisibility($id)
    {
        $sql = "UPDATE products SET show_on_website = CASE WHEN show_on_website = 1 THEN 0 ELSE 1 END WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Toggle product-level free shipping flag
     */
    public function toggleFreeShipping($id)
    {
        $sql = "UPDATE products SET free_shipping = CASE WHEN free_shipping = 1 THEN 0 ELSE 1 END WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Bulk update selected active products for admin maintenance actions.
     */
    public function bulkUpdateProducts(array $ids, array $updates, bool $onlyMissingShipping = false): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));

        if (empty($ids)) {
            return 0;
        }

        $allowedFields = [
            'show_on_website',
            'free_shipping',
            'category',
            'manufacturer',
            'model',
            'weight',
            'length',
            'width',
            'height',
        ];
        $shippingFields = ['weight', 'length', 'width', 'height'];
        $setClauses = [];
        $params = [];

        foreach ($updates as $field => $value) {
            if (!in_array($field, $allowedFields, true)) {
                continue;
            }

            if ($onlyMissingShipping && in_array($field, $shippingFields, true)) {
                $setClauses[] = "{$field} = CASE WHEN {$field} IS NULL OR {$field} <= 0 THEN ? ELSE {$field} END";
            } else {
                $setClauses[] = "{$field} = ?";
            }

            $params[] = $value;
        }

        if (empty($setClauses)) {
            return 0;
        }

        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE products SET " . implode(', ', $setClauses) . " WHERE is_active = 1 AND id IN ({$idPlaceholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge($params, $ids));

        return $stmt->rowCount();
    }

    /**
     * Find old active inventory and return promotion recommendations.
     */
    public function getStaleInventoryRecommendations(int $minimumAgeDays = 90, int $limit = 100, string $visibilityFilter = 'visible'): array
    {
        $minimumAgeDays = max(1, min(1000, $minimumAgeDays));
        $limit = max(1, min(500, $limit));
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $isSqlite = $driver === 'sqlite';
        $nowExpression = $isSqlite ? "datetime('now')" : 'NOW()';
        $createdExpression = "COALESCE(NULLIF(p.created_at, ''), {$nowExpression})";
        $updatedExpression = "COALESCE(NULLIF(p.updated_at, ''), p.created_at, {$nowExpression})";
        $daysListedExpression = $isSqlite
            ? "CAST((julianday({$nowExpression}) - julianday({$createdExpression})) AS INTEGER)"
            : "DATEDIFF({$nowExpression}, {$createdExpression})";
        $daysSinceUpdateExpression = $isSqlite
            ? "CAST((julianday({$nowExpression}) - julianday({$updatedExpression})) AS INTEGER)"
            : "DATEDIFF({$nowExpression}, {$updatedExpression})";
        $staleCutoffExpression = $isSqlite
            ? "datetime('now', '-{$minimumAgeDays} days')"
            : "DATE_SUB(NOW(), INTERVAL {$minimumAgeDays} DAY)";

        $salesJoin = "LEFT JOIN (SELECT product_id, 0 AS units_sold, NULL AS last_order_at FROM products WHERE 1 = 0) sales ON sales.product_id = p.id";
        if ($this->tableExists('orders') && $this->tableExists('order_items')) {
            $salesJoin = "
                LEFT JOIN (
                    SELECT
                        oi.product_id,
                        SUM(oi.quantity) AS units_sold,
                        MAX(o.created_at) AS last_order_at
                    FROM order_items oi
                    INNER JOIN orders o ON o.id = oi.order_id
                    WHERE (
                        o.payment_status IN ('completed', 'paid', 'approved')
                        OR o.order_status IN ('paid', 'processing', 'completed', 'shipped')
                    )
                    GROUP BY oi.product_id
                ) sales ON sales.product_id = p.id
            ";
        }

        $analyticsJoin = "LEFT JOIN (SELECT id AS product_id, 0 AS views_30, 0 AS views_90, 0 AS carts_90, NULL AS last_engagement_at FROM products WHERE 1 = 0) activity ON activity.product_id = p.id";
        if ($this->tableExists('analytics_events')) {
            $thirtyDayCutoff = $isSqlite ? "datetime('now', '-30 days')" : "DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $ninetyDayCutoff = $isSqlite ? "datetime('now', '-90 days')" : "DATE_SUB(NOW(), INTERVAL 90 DAY)";
            $productIdCastExpression = $isSqlite ? 'CAST(product_id AS INTEGER)' : 'CAST(product_id AS UNSIGNED)';
            $analyticsJoin = "
                LEFT JOIN (
                    SELECT
                        {$productIdCastExpression} AS product_id,
                        SUM(CASE WHEN event_type IN ('product_view', 'product_viewed') AND created_at >= {$thirtyDayCutoff} THEN 1 ELSE 0 END) AS views_30,
                        SUM(CASE WHEN event_type IN ('product_view', 'product_viewed') AND created_at >= {$ninetyDayCutoff} THEN 1 ELSE 0 END) AS views_90,
                        SUM(CASE WHEN event_type = 'add_to_cart' AND created_at >= {$ninetyDayCutoff} THEN 1 ELSE 0 END) AS carts_90,
                        MAX(created_at) AS last_engagement_at
                    FROM analytics_events
                    WHERE product_id IS NOT NULL AND product_id != ''
                    GROUP BY {$productIdCastExpression}
                ) activity ON activity.product_id = p.id
            ";
        }

        $visibilitySql = '';
        if ($visibilityFilter === 'visible') {
            $visibilitySql = 'AND p.show_on_website = 1';
        } elseif ($visibilityFilter === 'hidden') {
            $visibilitySql = 'AND p.show_on_website = 0';
        }

        $sql = "
            SELECT
                p.*,
                {$daysListedExpression} AS days_listed,
                {$daysSinceUpdateExpression} AS days_since_update,
                COALESCE(sales.units_sold, 0) AS units_sold,
                sales.last_order_at,
                COALESCE(activity.views_30, 0) AS views_30,
                COALESCE(activity.views_90, 0) AS views_90,
                COALESCE(activity.carts_90, 0) AS carts_90,
                activity.last_engagement_at
            FROM products p
            {$salesJoin}
            {$analyticsJoin}
            WHERE p.is_active = 1
              AND COALESCE(p.quantity, 0) > 0
              {$visibilitySql}
              AND {$createdExpression} <= {$staleCutoffExpression}
            ORDER BY days_listed DESC, COALESCE(activity.views_90, 0) DESC, p.price DESC
            LIMIT {$limit}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($product) {
            return $this->addStaleInventoryRecommendation($product);
        }, $products);
    }

    /**
     * Apply per-product sale prices generated by stale inventory recommendations.
     */
    public function applySalePrices(array $salePricesById): int
    {
        $normalized = [];
        foreach ($salePricesById as $id => $salePrice) {
            $productId = (int)$id;
            $salePrice = round((float)$salePrice, 2);
            if ($productId > 0 && $salePrice > 0) {
                $normalized[$productId] = $salePrice;
            }
        }

        if (empty($normalized)) {
            return 0;
        }

        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $timestampExpression = $driver === 'sqlite' ? "datetime('now')" : 'CURRENT_TIMESTAMP';
        $select = $this->db->prepare("SELECT id, price FROM products WHERE id = ? AND is_active = 1");
        $update = $this->db->prepare("UPDATE products SET sale_price = ?, updated_at = {$timestampExpression} WHERE id = ? AND is_active = 1");
        $updated = 0;

        $this->db->beginTransaction();
        try {
            foreach ($normalized as $productId => $salePrice) {
                $select->execute([$productId]);
                $product = $select->fetch(\PDO::FETCH_ASSOC);
                $price = $product ? (float)$product['price'] : 0.0;

                if (!$product || $price <= 0 || $salePrice >= $price) {
                    continue;
                }

                $update->execute([$salePrice, $productId]);
                $updated += $update->rowCount();
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $updated;
    }

    private function addStaleInventoryRecommendation(array $product): array
    {
        $daysListed = max(0, (int)($product['days_listed'] ?? 0));
        $views30 = max(0, (int)($product['views_30'] ?? 0));
        $views90 = max(0, (int)($product['views_90'] ?? 0));
        $carts90 = max(0, (int)($product['carts_90'] ?? 0));
        $unitsSold = max(0, (int)($product['units_sold'] ?? 0));
        $price = (float)($product['price'] ?? 0);
        $salePrice = !empty($product['sale_price']) ? (float)$product['sale_price'] : null;
        $currentDiscount = ($salePrice !== null && $salePrice > 0 && $salePrice < $price && $price > 0)
            ? (int)round((1 - ($salePrice / $price)) * 100)
            : 0;

        $recommendedDiscount = 5;
        $priority = 'Watch';
        $priorityClass = 'secondary';
        $recommendationType = 'Coupon Campaign';
        $reason = 'Older active listing with no recent completed sale signal.';

        if ($daysListed >= 365) {
            $recommendedDiscount = 25;
            $priority = 'Critical';
            $priorityClass = 'danger';
            $recommendationType = 'Clearance Markdown';
            $reason = 'Listing is over a year old; direct markdown is the clearest action.';
        } elseif ($daysListed >= 180) {
            $recommendedDiscount = 20;
            $priority = 'High';
            $priorityClass = 'warning';
            $recommendationType = 'Markdown + Coupon';
            $reason = 'Listing is over 180 days old and should be pushed with a stronger offer.';
        } elseif ($daysListed >= 120) {
            $recommendedDiscount = 15;
            $priority = 'Medium';
            $priorityClass = 'primary';
            $recommendationType = 'Short Coupon Campaign';
            $reason = 'Listing is aging into stale inventory; use a limited campaign before deeper markdown.';
        } elseif ($daysListed >= 90) {
            $recommendedDiscount = 10;
            $priority = 'Medium';
            $priorityClass = 'info';
            $recommendationType = 'Coupon Campaign';
            $reason = 'Listing is older than 90 days; test a modest conversion incentive.';
        }

        if ($views30 >= 10 && $carts90 === 0) {
            $recommendedDiscount = max($recommendedDiscount, 12);
            $recommendationType = 'Offer Test';
            $reason = 'Product gets views but few cart signals; test a visible offer or refresh price.';
        }

        if ($carts90 > 0 && $unitsSold === 0) {
            $recommendedDiscount = max($recommendedDiscount, 15);
            $recommendationType = 'Abandoned-Cart Coupon';
            $reason = 'Shoppers added this item to cart without sale completion; coupon campaign is appropriate.';
        }

        if ($views90 === 0 && $daysListed >= 120) {
            $recommendationType = 'Refresh + Markdown';
            $reason = 'No recent product-view signal; refresh merchandising and pair with markdown.';
        }

        if ($currentDiscount >= $recommendedDiscount && $currentDiscount > 0) {
            $recommendationType = 'Promote Existing Markdown';
            $reason = 'Current sale price already meets or beats the recommended markdown.';
        }

        $effectiveDiscount = max($recommendedDiscount, $currentDiscount);
        $recommendedSalePrice = $price > 0 ? round($price * (1 - ($effectiveDiscount / 100)), 2) : 0.0;
        $campaignCode = 'OLDSTOCK' . max(5, min(30, $effectiveDiscount));
        $score = $daysListed + ($carts90 * 15) + min(100, $views90) - ($unitsSold * 30);

        $product['current_discount_percent'] = $currentDiscount;
        $product['recommended_discount_percent'] = $effectiveDiscount;
        $product['recommended_sale_price'] = $recommendedSalePrice;
        $product['recommendation_type'] = $recommendationType;
        $product['recommendation_reason'] = $reason;
        $product['priority_label'] = $priority;
        $product['priority_class'] = $priorityClass;
        $product['campaign_code'] = $campaignCode;
        $product['stale_score'] = max(0, $score);

        return $product;
    }

    private function tableExists(string $table): bool
    {
        try {
            $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
                $stmt->execute([$table]);
                return (bool)$stmt->fetchColumn();
            }

            $stmt = $this->db->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Delete product (soft delete)
     */
    public function delete($id)
    {
        $sql = "UPDATE products SET is_active = 0 WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hide product from website by eBay item ID
     * Used when item is sold/ended on eBay
     */
    public function hideByEbayId($ebayItemId)
    {
        $sql = "UPDATE products SET show_on_website = 0 WHERE ebay_item_id = ? AND is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$ebayItemId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Map eBay category to local category
     * Maps eBay category names/IDs to our categories: motorcycle, atv, boat, automotive, gifts
     */
    private function mapEbayCategory($ebayCategoryName, $ebayCategoryId, $itemTitle, $ebayCat1Name = null)
    {
        // Default category - use 'other' as true fallback instead of 'automotive'
        $category = 'other';

        // Priority 0: Check database mappings for eBay store category (level 1)
        // This takes highest priority if configured
        if (!empty($ebayCat1Name)) {
            try {
                // Lazy load and cache the mapping model
                if ($this->mappingModel === null) {
                    require_once __DIR__ . '/HomepageCategoryMapping.php';
                    $this->mappingModel = new HomepageCategoryMapping($this->db);
                }
                $mappedCategory = $this->mappingModel->getHomepageCategoryForEbayCategory($ebayCat1Name);

                if ($mappedCategory) {
                    return $mappedCategory;
                }
            } catch (\Exception $e) {
                // If table doesn't exist or there's an error, fall back to hardcoded logic
                error_log("Homepage category mapping error: " . $e->getMessage());
            }
        }

        // Convert to lowercase for case-insensitive matching
        $categoryName = strtolower($ebayCategoryName ?? '');
        $title = strtolower($itemTitle ?? '');

        // Priority 1: Check for gift-specific keywords first (highest priority)
        // These should override vehicle brand names (e.g., "Harley Davidson shirt" is a gift, not a motorcycle part)
        foreach (self::GIFT_KEYWORDS as $keyword) {
            if (strpos($categoryName, $keyword) !== false || strpos($title, $keyword) !== false) {
                return 'gifts';
            }
        }

        // Priority 2: Check for parts-specific categories (motorcycle, ATV, boat, automotive)
        foreach (self::PARTS_CATEGORY_MAPPINGS as $localCategory => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($categoryName, $keyword) !== false || strpos($title, $keyword) !== false) {
                    return $localCategory;
                }
            }
        }

        return $category;
    }

    /**
     * Extract full 3-level eBay store category hierarchy
     * Returns array with category IDs and names for all 3 levels
     *
     * @param int|null $storeCategoryId Primary store category ID from eBay
     * @param int|null $storeCategory2Id Secondary store category ID from eBay
     * @param \FAS\Integrations\EbayAPI|null $ebayAPI EbayAPI instance
     * @return array Array with cat1_id, cat1_name, cat2_id, cat2_name, cat3_id, cat3_name
     */
    private function extractStoreCategoryHierarchy($storeCategoryId, $storeCategory2Id, $ebayAPI)
    {
        $hierarchy = [
            'cat1_id' => null,
            'cat1_name' => null,
            'cat2_id' => null,
            'cat2_name' => null,
            'cat3_id' => null,
            'cat3_name' => null
        ];

        if (!$ebayAPI || !$storeCategoryId) {
            if (!$ebayAPI) {
                error_log("[Category Extraction] No EbayAPI instance provided");
            }
            if (!$storeCategoryId) {
                error_log("[Category Extraction] No store_category_id provided (store_category_id: " . var_export($storeCategoryId, true) . ", store_category2_id: " . var_export($storeCategory2Id, true) . ")");
            }
            return $hierarchy;
        }

        // Get all store categories (cached in EbayAPI)
        // This calls eBay GetStore API
        $storeCategories = $ebayAPI->getStoreCategories();
        if (empty($storeCategories)) {
            if ($storeCategories === null) {
                error_log("[Category Extraction] getStoreCategories() returned NULL - API/token issue");
            } else {
                error_log("[Category Extraction] getStoreCategories() returned empty array - no categories in store");
            }
            return $hierarchy;
        }

        error_log("[Category Extraction] Retrieved " . count($storeCategories) . " store categories from eBay");

        // Look up the category by ID
        if (!isset($storeCategories[$storeCategoryId])) {
            error_log("[Category Extraction] Primary category ID $storeCategoryId not found in store categories");
            // Try secondary category if primary not found
            if ($storeCategory2Id && isset($storeCategories[$storeCategory2Id])) {
                error_log("[Category Extraction] Using secondary category ID $storeCategory2Id");
                $storeCategoryId = $storeCategory2Id;
            } else {
                error_log("[Category Extraction] Secondary category ID also not found. Available IDs: " . implode(', ', array_keys($storeCategories)));
                return $hierarchy;
            }
        }

        $category = $storeCategories[$storeCategoryId];
        $level = $category['level'] ?? 0;

        error_log("[Category Extraction] Processing category ID $storeCategoryId: '" . ($category['name'] ?? 'unnamed') . "' at level $level");

        // Build indexed lookups to avoid O(n) searches
        // Group categories by level and name for fast lookup
        // Note: Do not use static to avoid stale data across different product syncs
        $levelIndex = [];
        foreach ($storeCategories as $catId => $cat) {
            $levelKey = $cat['level'] . ':' . $cat['name'] . ':' . ($cat['topLevel'] ?? '');
            $levelIndex[$levelKey] = ['id' => $catId, 'name' => $cat['name']];
        }

        // Based on the level, extract the hierarchy
        if ($level == 1) {
            // Level 1: Only top-level category
            $hierarchy['cat1_id'] = $storeCategoryId;
            $hierarchy['cat1_name'] = $category['name'];
            error_log("[Category Extraction] Extracted Level 1: " . $category['name']);
        } elseif ($level == 2) {
            // Level 2: Top-level + manufacturer
            $hierarchy['cat2_id'] = $storeCategoryId;
            $hierarchy['cat2_name'] = $category['name'];

            // Find parent (level 1) by looking for topLevel
            $topLevelName = $category['topLevel'] ?? null;
            if ($topLevelName) {
                $key = '1:' . $topLevelName . ':' . $topLevelName;
                if (isset($levelIndex[$key])) {
                    $hierarchy['cat1_id'] = $levelIndex[$key]['id'];
                    $hierarchy['cat1_name'] = $levelIndex[$key]['name'];
                    error_log("[Category Extraction] Extracted Level 1: " . $hierarchy['cat1_name'] . ", Level 2: " . $hierarchy['cat2_name']);
                } else {
                    error_log("[Category Extraction] Could not find parent Level 1 for: $topLevelName");
                }
            }
        } elseif ($level == 3) {
            // Level 3: Complete hierarchy
            $hierarchy['cat3_id'] = $storeCategoryId;
            $hierarchy['cat3_name'] = $category['name'];

            // Get parent (level 2) name
            $parentName = $category['parent'] ?? null;
            $topLevelName = $category['topLevel'] ?? null;

            // Find level 2 parent
            if ($parentName && $topLevelName) {
                $key = '2:' . $parentName . ':' . $topLevelName;
                if (isset($levelIndex[$key])) {
                    $hierarchy['cat2_id'] = $levelIndex[$key]['id'];
                    $hierarchy['cat2_name'] = $levelIndex[$key]['name'];
                } else {
                    error_log("[Category Extraction] Could not find Level 2 parent: $parentName");
                }
            }

            // Find level 1 (top-level)
            if ($topLevelName) {
                $key = '1:' . $topLevelName . ':' . $topLevelName;
                if (isset($levelIndex[$key])) {
                    $hierarchy['cat1_id'] = $levelIndex[$key]['id'];
                    $hierarchy['cat1_name'] = $levelIndex[$key]['name'];
                    error_log("[Category Extraction] Extracted complete hierarchy: L1={$hierarchy['cat1_name']}, L2={$hierarchy['cat2_name']}, L3={$hierarchy['cat3_name']}");
                } else {
                    error_log("[Category Extraction] Could not find Level 1 top-level: $topLevelName");
                }
            }
        } else {
            error_log("[Category Extraction] Unknown category level: $level");
        }

        return $hierarchy;
    }

    /**
     * Sync product from eBay data
     * @param array $ebayData Product data from eBay API
     * @param \FAS\Integrations\EbayAPI|null $ebayAPI Optional EbayAPI instance for store category extraction
     */
    public function syncFromEbay($ebayData, $ebayAPI = null)
    {
        $existing = $this->getByEbayId($ebayData['id']);

        // Priority 1: Use eBay's Brand and MPN fields if available (most reliable)
        $manufacturer = $ebayData['brand'] ?? null;
        $model = $ebayData['mpn'] ?? null;

        // Defensive: Ensure manufacturer and model are strings (handle arrays)
        // eBay can return arrays when there are multiple values for the same field
        if (is_array($manufacturer)) {
            $manufacturer = implode(', ', $manufacturer);
        }
        if (is_array($model)) {
            $model = implode(', ', $model);
        }

        // Priority 1.5: Always fetch Condition and SKU from GetItem API (most reliable)
        // GetSellerEvents does not reliably return these fields
        // Also get dimensions, weight, description, brand, MPN, and images from GetItem
        $weight = $ebayData['weight'] ?? null;
        $length = $ebayData['length'] ?? null;
        $width = $ebayData['width'] ?? null;
        $height = $ebayData['height'] ?? null;
        $description = $ebayData['description'] ?? '';
        $sku = '';  // Will be fetched from GetItem
        $condition = '';  // Will be fetched from GetItem

        $images = $ebayData['images'] ?? [];
        $image = $ebayData['image'] ?? null;

        // ALWAYS call GetItem to fetch Condition and SKU (required fields)
        // Also fetch other fields if missing from GetSellerEvents
        // IMPORTANT: GetItem also provides store_category_id and store_category2_id
        if ($ebayAPI && isset($ebayData['id'])) {
            $itemDetails = $ebayAPI->getItemDetails($ebayData['id']);
            if ($itemDetails) {
                // Get condition (REQUIRED - always from GetItem)
                $condition = $itemDetails['condition'] ?? 'Used';

                // Get SKU (REQUIRED - always from GetItem)
                $sku = $itemDetails['sku'] ?? '';

                // Get store category IDs from GetItem (more reliable than GetSellerEvents)
                // Override any values from GetSellerEvents with GetItem data
                if (isset($itemDetails['store_category_id'])) {
                    $ebayData['store_category_id'] = $itemDetails['store_category_id'];
                }
                if (isset($itemDetails['store_category2_id'])) {
                    $ebayData['store_category2_id'] = $itemDetails['store_category2_id'];
                }

                // Get brand and model
                if (!$manufacturer && $itemDetails['brand']) {
                    $manufacturer = $itemDetails['brand'];
                }
                if (!$model && $itemDetails['mpn']) {
                    $model = $itemDetails['mpn'];
                }
                // Get dimensions and weight from GetItem
                if (!$weight && $itemDetails['weight']) {
                    $weight = $itemDetails['weight'];
                }
                if (!$length && $itemDetails['length']) {
                    $length = $itemDetails['length'];
                }
                if (!$width && $itemDetails['width']) {
                    $width = $itemDetails['width'];
                }
                if (!$height && $itemDetails['height']) {
                    $height = $itemDetails['height'];
                }
                // Get description and images from GetItem
                if (empty($description) && !empty($itemDetails['description'])) {
                    $description = $itemDetails['description'];
                }
                if (empty($images) && !empty($itemDetails['images'])) {
                    $images = $itemDetails['images'];
                    $image = $itemDetails['image'];
                }
            }
        }

        // Defensive: Ensure sku is a string (handle arrays)
        if (is_array($sku)) {
            $sku = implode(', ', $sku);
        }

        // If SKU is empty or null (but not '0'), use ebay_item_id as fallback
        if ($sku === '' || $sku === null) {
            $sku = $ebayData['id'];
        }

        // Extract full 3-level eBay store category hierarchy
        // Uses eBay GetStore API to fetch custom store categories
        error_log("[Product Sync] Item {$ebayData['id']}: Extracting category hierarchy from store_category_id=" . ($ebayData['store_category_id'] ?? 'null') . ", store_category2_id=" . ($ebayData['store_category2_id'] ?? 'null'));

        $storeCategoryHierarchy = $this->extractStoreCategoryHierarchy(
            $ebayData['store_category_id'] ?? null,
            $ebayData['store_category2_id'] ?? null,
            $ebayAPI
        );

        error_log("[Product Sync] Item {$ebayData['id']}: Category hierarchy extracted - L1: " . ($storeCategoryHierarchy['cat1_name'] ?? 'none') . ", L2: " . ($storeCategoryHierarchy['cat2_name'] ?? 'none') . ", L3: " . ($storeCategoryHierarchy['cat3_name'] ?? 'none'));

        // Map eBay category to local category using store category level 1 first, then standard mapping
        $category = $this->mapEbayCategory(
            $ebayData['ebay_category_name'] ?? null,
            $ebayData['ebay_category_id'] ?? null,
            $ebayData['title'],
            $storeCategoryHierarchy['cat1_name'] ?? null
        );

        // Priority 2: Extract category, manufacturer and model from store categories (ONLY as fallback)
        $storeCategoryFound = false;
        if ($ebayAPI && isset($ebayData['store_category_id']) && $ebayData['store_category_id']) {
            $extracted = $ebayAPI->extractCategoryMfgModelFromStoreCategory($ebayData['store_category_id']);
            if ($extracted['category']) {
                $category = $extracted['category'];
                // Only use store category mfg/model if eBay fields are not available
                if (!$manufacturer) {
                    $manufacturer = $extracted['manufacturer'];
                }
                if (!$model) {
                    $model = $extracted['model'];
                }
                $storeCategoryFound = true;
            }
        }

        // Try secondary store category if primary didn't yield category
        if ($ebayAPI && !$storeCategoryFound && isset($ebayData['store_category2_id']) && $ebayData['store_category2_id']) {
            $extracted = $ebayAPI->extractCategoryMfgModelFromStoreCategory($ebayData['store_category2_id']);
            if ($extracted['category']) {
                $category = $extracted['category'];
                // Only use store category mfg/model if eBay fields are not available
                if (!$manufacturer) {
                    $manufacturer = $extracted['manufacturer'];
                }
                if (!$model) {
                    $model = $extracted['model'];
                }
            }
        }


        $productData = [
            'ebay_item_id' => $ebayData['id'],
            'sku' => $sku,
            'name' => $ebayData['title'],
            'description' => $description,
            'price' => $ebayData['price'],
            'quantity' => $ebayData['quantity'] ?? 1,
            'category' => $category,
            'manufacturer' => $manufacturer,
            'model' => $model,
            'condition_name' => $condition,  // Fetched from GetItem API
            'weight' => $weight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'image_url' => $image,
            'images' => $images,
            'ebay_url' => $ebayData['url'] ?? null,
            'source' => 'ebay',
            // Store exact eBay store category hierarchy (all 3 levels)
            'ebay_store_cat1_id' => $storeCategoryHierarchy['cat1_id'],
            'ebay_store_cat1_name' => $storeCategoryHierarchy['cat1_name'],
            'ebay_store_cat2_id' => $storeCategoryHierarchy['cat2_id'],
            'ebay_store_cat2_name' => $storeCategoryHierarchy['cat2_name'],
            'ebay_store_cat3_id' => $storeCategoryHierarchy['cat3_id'],
            'ebay_store_cat3_name' => $storeCategoryHierarchy['cat3_name']
        ];

        // Check if product has required shipping dimensions/weight
        // If missing, set show_on_website to 0 (unlisted) until admin adds them
        $hasDimensions = !empty($weight) && !empty($length) && !empty($width) && !empty($height);

        if ($existing) {
            // Don't update category on sync - preserve admin's setting
            unset($productData['category']);

            // ALWAYS update eBay store category hierarchy - this must stay in sync with eBay
            // These fields represent the exact eBay store structure and should always reflect current state

            // Always update manufacturer if we have Brand from eBay (most reliable source)
            // Only preserve existing manufacturer if we don't have Brand from eBay
            if (empty($ebayData['brand']) && !empty($existing['manufacturer'])) {
                unset($productData['manufacturer']);
            }

            // Always update model if we have MPN from eBay (most reliable source)
            // Only preserve existing model if we don't have MPN from eBay
            if (empty($ebayData['mpn']) && !empty($existing['model'])) {
                unset($productData['model']);
            }

            // Preserve existing dimensions/weight if eBay doesn't provide them
            if (empty($weight) && !empty($existing['weight'])) {
                unset($productData['weight']);
            }
            if (empty($length) && !empty($existing['length'])) {
                unset($productData['length']);
            }
            if (empty($width) && !empty($existing['width'])) {
                unset($productData['width']);
            }
            if (empty($height) && !empty($existing['height'])) {
                unset($productData['height']);
            }

            // If this update removes dimensions/weight, hide from website
            if (!$hasDimensions) {
                $productData['show_on_website'] = 0;
                error_log("[Product Sync] Item {$ebayData['id']} missing dimensions/weight - hiding from website");
            }

            return $this->update($existing['id'], $productData);
        } else {
            // New eBay products: only show on website if they have complete dimensions/weight
            if ($hasDimensions) {
                $productData['show_on_website'] = 1;
            } else {
                $productData['show_on_website'] = 0;
                error_log("[Product Sync] New item {$ebayData['id']} missing dimensions/weight - hiding from website");
            }
            return $this->create($productData);
        }
    }

    public function updateImages($prodId, $newImagesJson) {
        $sql = "UPDATE products SET images = :imgs WHERE id = :pid";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':imgs', $newImagesJson);
        $stmt->bindParam(':pid', $prodId);
        return $stmt->execute();
    }

    /**
     * Get all products that are currently visible on the storefront.
     *
     * This is used by feed/export style integrations that should mirror the
     * public catalog rather than the admin inventory view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllVisibleForFeed()
    {
        $sql = "SELECT * FROM products
                WHERE is_active = 1 AND show_on_website = 1
                ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get full eBay store category path for a product
     * Returns formatted string like "Motorcycle > Honda > CR500"
     *
     * @param array $product Product data array
     * @return string|null Category path or null if no eBay categories
     */
    public function getEbayStoreCategoryPath($product)
    {
        $path = [];

        if (!empty($product['ebay_store_cat1_name'])) {
            $path[] = $product['ebay_store_cat1_name'];
        }
        if (!empty($product['ebay_store_cat2_name'])) {
            $path[] = $product['ebay_store_cat2_name'];
        }
        if (!empty($product['ebay_store_cat3_name'])) {
            $path[] = $product['ebay_store_cat3_name'];
        }

        return !empty($path) ? implode(' > ', $path) : null;
    }

    /**
     * Get eBay store categories as an array
     * Returns array with level => name mapping
     *
     * @param array $product Product data array
     * @return array Array of categories [1 => 'Level 1', 2 => 'Level 2', 3 => 'Level 3']
     */
    public function getEbayStoreCategoryArray($product)
    {
        $categories = [];

        if (!empty($product['ebay_store_cat1_name'])) {
            $categories[1] = $product['ebay_store_cat1_name'];
        }
        if (!empty($product['ebay_store_cat2_name'])) {
            $categories[2] = $product['ebay_store_cat2_name'];
        }
        if (!empty($product['ebay_store_cat3_name'])) {
            $categories[3] = $product['ebay_store_cat3_name'];
        }

        return $categories;
    }

    /**
     * Get all products with pagination, filtered by eBay store category IDs
     */
    public function getAllByEbayCategory($page = 1, $perPage = 24, $cat1Id = null, $cat2Id = null, $cat3Id = null, $search = null, $manufacturer = null, $includeHidden = false, $model = null)
    {
        if ($search !== null && trim((string)$search) !== '') {
            return $this->searchByEbayCategory($page, $perPage, $cat1Id, $cat2Id, $cat3Id, $search, $manufacturer, $includeHidden, $model);
        }

        $offset = ($page - 1) * $perPage;

        $sql = "SELECT * FROM products WHERE is_active = 1";
        if (!$includeHidden) {
            $sql .= " AND show_on_website = 1";
        }
        $params = [];

        // Filter by eBay category (most specific first)
        if ($cat3Id) {
            $sql .= " AND ebay_store_cat3_id = ?";
            $params[] = $cat3Id;
        } elseif ($cat2Id) {
            $sql .= " AND ebay_store_cat2_id = ?";
            $params[] = $cat2Id;
        } elseif ($cat1Id) {
            $sql .= " AND ebay_store_cat1_id = ?";
            $params[] = $cat1Id;
        }

        if ($manufacturer) {
            $sql .= " AND manufacturer = ?";
            $params[] = $manufacturer;
        }

        if ($model) {
            $sql .= " AND model = ?";
            $params[] = $model;
        }

        if ($search) {
            $sql .= " AND (name LIKE ? OR description LIKE ? OR sku LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get count of products filtered by eBay store category IDs
     */
    public function getCountByEbayCategory($cat1Id = null, $cat2Id = null, $cat3Id = null, $search = null, $manufacturer = null, $includeHidden = false, $model = null)
    {
        if ($search !== null && trim((string)$search) !== '') {
            return count($this->searchByEbayCategory(1, 0, $cat1Id, $cat2Id, $cat3Id, $search, $manufacturer, $includeHidden, $model));
        }

        $sql = "SELECT COUNT(*) as total FROM products WHERE is_active = 1";
        if (!$includeHidden) {
            $sql .= " AND show_on_website = 1";
        }
        $params = [];

        // Filter by eBay category (most specific first)
        if ($cat3Id) {
            $sql .= " AND ebay_store_cat3_id = ?";
            $params[] = $cat3Id;
        } elseif ($cat2Id) {
            $sql .= " AND ebay_store_cat2_id = ?";
            $params[] = $cat2Id;
        } elseif ($cat1Id) {
            $sql .= " AND ebay_store_cat1_id = ?";
            $params[] = $cat1Id;
        }

        if ($manufacturer) {
            $sql .= " AND manufacturer = ?";
            $params[] = $manufacturer;
        }

        if ($model) {
            $sql .= " AND model = ?";
            $params[] = $model;
        }

        if ($search) {
            $sql .= " AND (name LIKE ? OR description LIKE ? OR sku LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result['total'];
    }

    /**
     * Get eBay store category IDs that currently have visible products.
     *
     * Returns a lookup array keyed by category ID so sidebar hierarchies can be
     * pruned without exposing empty branches to customers.
     */
    public function getSearchSuggestionQueries($search, int $limit = 6): array
    {
        $tokens = $this->tokenizeProductSearch($search);
        if (empty($tokens)) {
            return [];
        }

        $suggestions = [];
        foreach ($tokens as $index => $token) {
            $variants = $this->expandProductSearchToken($token);
            foreach ($variants as $variant) {
                if ($variant === $token || strlen($variant) < 2) {
                    continue;
                }

                $suggestionTokens = $tokens;
                $suggestionTokens[$index] = $variant;
                $suggestion = trim(implode(' ', $suggestionTokens));
                if ($suggestion !== '' && $suggestion !== implode(' ', $tokens)) {
                    $suggestions[$suggestion] = $suggestion;
                }

                if (count($suggestions) >= $limit) {
                    break 2;
                }
            }
        }

        return array_values($suggestions);
    }

    public function getSearchFallbackRecommendations($search, int $limit = 6, array $excludeIds = [], $cat1Id = null, $cat2Id = null, $cat3Id = null, $manufacturer = null, $includeHidden = false, $model = null): array
    {
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds), function ($id) {
            return $id > 0;
        })));
        $candidates = $this->getSearchCandidateProductsByEbayCategory($cat1Id, $cat2Id, $cat3Id, $manufacturer, $includeHidden, $model);
        $ranked = [];

        foreach ($candidates as $product) {
            if (in_array((int)($product['id'] ?? 0), $excludeIds, true)) {
                continue;
            }

            $score = $this->productSearchScore($product, (string)$search, true);
            if ($score > 0) {
                $ranked[] = [
                    'score' => $score,
                    'product' => $product,
                ];
            }
        }

        usort($ranked, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strtotime((string)($b['product']['created_at'] ?? '')) <=> strtotime((string)($a['product']['created_at'] ?? ''));
        });

        return array_column(array_slice($ranked, 0, $limit), 'product');
    }

    private function searchByEbayCategory($page, $perPage, $cat1Id, $cat2Id, $cat3Id, $search, $manufacturer, $includeHidden, $model): array
    {
        $page = max(1, (int)$page);
        $perPage = (int)$perPage;
        $candidates = $this->getSearchCandidateProductsByEbayCategory($cat1Id, $cat2Id, $cat3Id, $manufacturer, $includeHidden, $model);
        $ranked = [];

        foreach ($candidates as $product) {
            $score = $this->productSearchScore($product, (string)$search, false);
            if ($score > 0) {
                $ranked[] = [
                    'score' => $score,
                    'product' => $product,
                ];
            }
        }

        usort($ranked, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strtotime((string)($b['product']['created_at'] ?? '')) <=> strtotime((string)($a['product']['created_at'] ?? ''));
        });

        $products = array_column($ranked, 'product');
        if ($perPage <= 0) {
            return $products;
        }

        return array_slice($products, ($page - 1) * $perPage, $perPage);
    }

    private function getSearchCandidateProductsByEbayCategory($cat1Id = null, $cat2Id = null, $cat3Id = null, $manufacturer = null, $includeHidden = false, $model = null): array
    {
        $sql = "SELECT * FROM products WHERE is_active = 1";
        $params = [];

        if (!$includeHidden) {
            $sql .= " AND show_on_website = 1";
        }

        if ($cat3Id) {
            $sql .= " AND ebay_store_cat3_id = ?";
            $params[] = $cat3Id;
        } elseif ($cat2Id) {
            $sql .= " AND ebay_store_cat2_id = ?";
            $params[] = $cat2Id;
        } elseif ($cat1Id) {
            $sql .= " AND ebay_store_cat1_id = ?";
            $params[] = $cat1Id;
        }

        if ($manufacturer) {
            $sql .= " AND manufacturer = ?";
            $params[] = $manufacturer;
        }

        if ($model) {
            $sql .= " AND model = ?";
            $params[] = $model;
        }

        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function productSearchScore(array $product, string $search, bool $relaxed = false): int
    {
        $groups = $this->buildProductSearchTokenGroups($search);
        if (empty($groups)) {
            return 0;
        }

        $fields = $this->buildProductSearchFieldText($product);
        $productTokens = $this->productSearchCorpusTokens($fields);
        $phrase = $this->normalizeProductSearchText($search);
        $score = 0;

        if ($phrase !== '') {
            if (strpos($fields['name'], $phrase) !== false) {
                $score += 90;
            }
            if (strpos($fields['sku'], str_replace(' ', '', $phrase)) !== false) {
                $score += 80;
            }
            if (strpos($fields['category'], $phrase) !== false) {
                $score += 45;
            }
            if (strpos($fields['description'], $phrase) !== false) {
                $score += 20;
            }
        }

        $matchedGroups = 0;
        foreach ($groups as $group) {
            $groupScore = 0;

            foreach ($group as $variant) {
                $variant = $this->normalizeProductSearchText($variant);
                if ($variant === '') {
                    continue;
                }

                $variantNoSpace = str_replace(' ', '', $variant);
                if (strpos($fields['sku'], $variantNoSpace) !== false) {
                    $groupScore = max($groupScore, 70);
                }
                if (strpos($fields['name'], $variant) !== false) {
                    $groupScore = max($groupScore, 55);
                }
                if (strpos($fields['manufacturer_model'], $variant) !== false) {
                    $groupScore = max($groupScore, 45);
                }
                if (strpos($fields['category'], $variant) !== false) {
                    $groupScore = max($groupScore, 35);
                }
                if (strpos($fields['description'], $variant) !== false) {
                    $groupScore = max($groupScore, 16);
                }
            }

            if ($groupScore === 0) {
                $token = $group[0] ?? '';
                foreach ($productTokens as $candidateToken) {
                    if ($this->isLikelyTypoMatch($token, $candidateToken)) {
                        $groupScore = 18;
                        break;
                    }
                }
            }

            if ($groupScore > 0) {
                $matchedGroups++;
                $score += $groupScore;
            }
        }

        $minimumMatches = $relaxed ? 1 : (count($groups) <= 2 ? count($groups) : max(2, (int)ceil(count($groups) * 0.7)));
        if ($matchedGroups < $minimumMatches) {
            return 0;
        }

        return $score + ($matchedGroups * 10);
    }

    private function buildProductSearchFieldText(array $product): array
    {
        $category = implode(' ', array_filter([
            $product['category'] ?? '',
            $product['ebay_store_cat1_name'] ?? '',
            $product['ebay_store_cat2_name'] ?? '',
            $product['ebay_store_cat3_name'] ?? '',
        ]));
        $manufacturerModel = trim((string)($product['manufacturer'] ?? '') . ' ' . (string)($product['model'] ?? ''));

        return [
            'name' => $this->normalizeProductSearchText($product['name'] ?? ''),
            'sku' => str_replace(' ', '', $this->normalizeProductSearchText($product['sku'] ?? '')),
            'description' => $this->normalizeProductSearchText(strip_tags((string)($product['description'] ?? ''))),
            'manufacturer_model' => $this->normalizeProductSearchText($manufacturerModel),
            'category' => $this->normalizeProductSearchText($category),
        ];
    }

    private function buildProductSearchTokenGroups(string $search): array
    {
        $tokens = $this->tokenizeProductSearch($search);
        $groups = [];

        foreach ($tokens as $token) {
            $variants = $this->expandProductSearchToken($token);
            if (!empty($variants)) {
                $groups[] = $variants;
            }
        }

        return $groups;
    }

    private function tokenizeProductSearch($search): array
    {
        $normalized = $this->normalizeProductSearchText($search);
        if ($normalized === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($tokens ?: [], function ($token) {
            return strlen($token) >= 2;
        }));
    }

    private function expandProductSearchToken(string $token): array
    {
        $token = $this->normalizeProductSearchText($token);
        if ($token === '') {
            return [];
        }

        $variants = [$token];
        if (isset(self::SEARCH_CORRECTIONS[$token])) {
            $variants[] = self::SEARCH_CORRECTIONS[$token];
        }

        $synonyms = $this->bidirectionalSearchSynonyms();
        $baseVariants = $variants;
        foreach ($baseVariants as $variant) {
            foreach ($synonyms[$variant] ?? [] as $synonym) {
                $variants[] = $synonym;
            }
        }

        return array_values(array_unique(array_filter(array_map(function ($variant) {
            return $this->normalizeProductSearchText($variant);
        }, $variants))));
    }

    private function productSearchCorpusTokens(array $fields): array
    {
        $combined = implode(' ', [
            $fields['name'] ?? '',
            $fields['sku'] ?? '',
            $fields['manufacturer_model'] ?? '',
            $fields['category'] ?? '',
        ]);
        $tokens = preg_split('/\s+/', $combined, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($tokens ?: [], function ($token) {
            return strlen($token) >= 3;
        })));
    }

    private function normalizeProductSearchText($value): string
    {
        $text = strtolower(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = str_replace(['&', '+'], ' and ', $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }

    private function isLikelyTypoMatch(string $needle, string $candidate): bool
    {
        $needle = $this->normalizeProductSearchText($needle);
        $candidate = $this->normalizeProductSearchText($candidate);
        $needleLength = strlen($needle);
        $candidateLength = strlen($candidate);

        if ($needleLength < 4 || $candidateLength < 4 || abs($needleLength - $candidateLength) > 2) {
            return false;
        }

        if ($needle[0] !== $candidate[0]) {
            return false;
        }

        $distance = levenshtein($needle, $candidate);
        $threshold = $needleLength <= 5 ? 1 : 2;

        return $distance <= $threshold;
    }

    private function bidirectionalSearchSynonyms(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach (self::SEARCH_SYNONYMS as $canonical => $synonyms) {
            $canonical = $this->normalizeProductSearchText($canonical);
            $allTerms = array_values(array_unique(array_merge([$canonical], array_map(function ($synonym) {
                return $this->normalizeProductSearchText($synonym);
            }, $synonyms))));

            foreach ($allTerms as $term) {
                if ($term === '') {
                    continue;
                }
                $map[$term] = array_values(array_unique(array_merge($map[$term] ?? [], $allTerms)));
            }
        }

        return $map;
    }

    public function getVisibleEbayCategoryIds($includeHidden = false)
    {
        $sql = "SELECT ebay_store_cat1_id, ebay_store_cat2_id, ebay_store_cat3_id
                FROM products
                WHERE is_active = 1";
        if (!$includeHidden) {
            $sql .= " AND show_on_website = 1";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $visibleCategoryIds = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            foreach (['ebay_store_cat1_id', 'ebay_store_cat2_id', 'ebay_store_cat3_id'] as $field) {
                if (!empty($row[$field])) {
                    $visibleCategoryIds[(string)$row[$field]] = true;
                }
            }
        }

        return $visibleCategoryIds;
    }

    public function getVisibleByIds(array $ids, int $limit = 12): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));

        if (empty($ids)) {
            return [];
        }

        $ids = array_slice($ids, 0, max(1, $limit));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT *
                FROM products
                WHERE is_active = 1
                  AND show_on_website = 1
                  AND id IN ({$placeholders})";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function getBestSellingVisible(int $limit = 8, int $days = 90, array $excludeIds = []): array
    {
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds), function ($id) {
            return $id > 0;
        })));
        $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'));

        $sql = "SELECT p.*, sales.units_sold, sales.last_order_at
                FROM products p
                INNER JOIN (
                    SELECT oi.product_id, SUM(oi.quantity) AS units_sold, MAX(o.created_at) AS last_order_at
                    FROM order_items oi
                    INNER JOIN orders o ON o.id = oi.order_id
                    WHERE o.created_at >= ?
                      AND (
                          o.payment_status IN ('completed', 'paid', 'approved')
                          OR o.order_status IN ('paid', 'processing', 'completed', 'shipped')
                      )
                    GROUP BY oi.product_id
                ) sales ON sales.product_id = p.id
                WHERE p.is_active = 1
                  AND p.show_on_website = 1";
        $params = [$since];

        if (!empty($excludeIds)) {
            $sql .= " AND p.id NOT IN (" . implode(',', array_fill(0, count($excludeIds), '?')) . ")";
            $params = array_merge($params, $excludeIds);
        }

        $sql .= " ORDER BY sales.units_sold DESC, sales.last_order_at DESC LIMIT ?";
        $params[] = max(1, $limit);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getRecentVisible(int $limit = 8, array $excludeIds = [], ?string $category = null, ?string $manufacturer = null, ?string $model = null): array
    {
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds), function ($id) {
            return $id > 0;
        })));

        $sql = "SELECT *
                FROM products
                WHERE is_active = 1
                  AND show_on_website = 1";
        $params = [];

        if (!empty($excludeIds)) {
            $sql .= " AND id NOT IN (" . implode(',', array_fill(0, count($excludeIds), '?')) . ")";
            $params = array_merge($params, $excludeIds);
        }

        if ($category !== null && trim($category) !== '') {
            $sql .= " AND (
                category = ?
                OR ebay_store_cat1_name = ?
                OR ebay_store_cat2_name = ?
                OR ebay_store_cat3_name = ?
            )";
            $params[] = $category;
            $params[] = $category;
            $params[] = $category;
            $params[] = $category;
        }

        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $sql .= " AND manufacturer = ?";
            $params[] = $manufacturer;
        }

        if ($model !== null && trim($model) !== '') {
            $sql .= " AND model = ?";
            $params[] = $model;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = max(1, $limit);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getRelatedVisible(array $product, int $limit = 4, array $excludeIds = []): array
    {
        $currentId = (int)($product['id'] ?? 0);
        if ($currentId > 0) {
            $excludeIds[] = $currentId;
        }

        $category = $product['ebay_store_cat3_name']
            ?? $product['ebay_store_cat2_name']
            ?? $product['ebay_store_cat1_name']
            ?? $product['category']
            ?? null;
        $manufacturer = $product['manufacturer'] ?? null;
        $model = $product['model'] ?? null;

        $related = [];
        if ($manufacturer !== null && trim((string)$manufacturer) !== '' && $model !== null && trim((string)$model) !== '') {
            $related = $this->getRecentVisible($limit, $excludeIds, null, (string)$manufacturer, (string)$model);
        }

        if (count($related) < $limit && $category !== null && trim((string)$category) !== '') {
            $more = $this->getRecentVisible($limit - count($related), array_merge($excludeIds, array_column($related, 'id')), (string)$category, null);
            $related = array_merge($related, $more);
        }

        if (count($related) < $limit && $manufacturer !== null && trim((string)$manufacturer) !== '') {
            $more = $this->getRecentVisible($limit - count($related), array_merge($excludeIds, array_column($related, 'id')), null, (string)$manufacturer);
            $related = array_merge($related, $more);
        }

        if (count($related) < $limit) {
            $more = $this->getRecentVisible($limit - count($related), array_merge($excludeIds, array_column($related, 'id')));
            $related = array_merge($related, $more);
        }

        return array_slice($related, 0, $limit);
    }
}
