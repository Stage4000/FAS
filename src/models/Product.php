<?php
/**
 * Product Model
 * Handles product data and database operations
 */

namespace FAS\Models;

class Product
{
    private $db;
    
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
    
    public function __construct($db)
    {
        $this->db = $db;
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
    public function getAllProducts($page = 1, $perPage = 20, $search = null, $sourceFilter = null)
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
    public function getCountAll($search = null, $sourceFilter = null)
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
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result['total'];
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
    public function getManufacturers()
    {
        $sql = "SELECT DISTINCT manufacturer FROM products 
                WHERE is_active = 1 AND show_on_website = 1 AND manufacturer IS NOT NULL 
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
            manufacturer, model, condition_name, weight, length, width, height, image_url, images, ebay_url, source, show_on_website,
            ebay_store_cat1_id, ebay_store_cat1_name, ebay_store_cat2_id, ebay_store_cat2_name, ebay_store_cat3_id, ebay_store_cat3_name
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
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
            'image_url', 'images', 'ebay_url', 'source', 'show_on_website',
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
     * Map eBay category to local category
     * Maps eBay category names/IDs to our categories: motorcycle, atv, boat, automotive, gifts
     */
    private function mapEbayCategory($ebayCategoryName, $ebayCategoryId, $itemTitle)
    {
        // Default category - use 'other' as true fallback instead of 'automotive'
        $category = 'other';
        
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
            return $hierarchy;
        }
        
        // Get all store categories
        $storeCategories = $ebayAPI->getStoreCategories();
        if (empty($storeCategories)) {
            return $hierarchy;
        }
        
        // Look up the category by ID
        if (!isset($storeCategories[$storeCategoryId])) {
            // Try secondary category if primary not found
            if ($storeCategory2Id && isset($storeCategories[$storeCategory2Id])) {
                $storeCategoryId = $storeCategory2Id;
            } else {
                return $hierarchy;
            }
        }
        
        $category = $storeCategories[$storeCategoryId];
        $level = $category['level'] ?? 0;
        
        // Based on the level, extract the hierarchy
        if ($level == 1) {
            // Level 1: Only top-level category
            $hierarchy['cat1_id'] = $storeCategoryId;
            $hierarchy['cat1_name'] = $category['name'];
        } elseif ($level == 2) {
            // Level 2: Top-level + manufacturer
            $hierarchy['cat2_id'] = $storeCategoryId;
            $hierarchy['cat2_name'] = $category['name'];
            
            // Find parent (level 1) by looking for topLevel
            $topLevelName = $category['topLevel'] ?? null;
            if ($topLevelName) {
                foreach ($storeCategories as $catId => $cat) {
                    if ($cat['level'] == 1 && $cat['name'] === $topLevelName) {
                        $hierarchy['cat1_id'] = $catId;
                        $hierarchy['cat1_name'] = $cat['name'];
                        break;
                    }
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
            if ($parentName) {
                foreach ($storeCategories as $catId => $cat) {
                    if ($cat['level'] == 2 && $cat['name'] === $parentName && $cat['topLevel'] === $topLevelName) {
                        $hierarchy['cat2_id'] = $catId;
                        $hierarchy['cat2_name'] = $cat['name'];
                        break;
                    }
                }
            }
            
            // Find level 1 (top-level)
            if ($topLevelName) {
                foreach ($storeCategories as $catId => $cat) {
                    if ($cat['level'] == 1 && $cat['name'] === $topLevelName) {
                        $hierarchy['cat1_id'] = $catId;
                        $hierarchy['cat1_name'] = $cat['name'];
                        break;
                    }
                }
            }
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
        
        // Default: Map eBay category to local category using standard mapping
        $category = $this->mapEbayCategory(
            $ebayData['ebay_category_name'] ?? null,
            $ebayData['ebay_category_id'] ?? null,
            $ebayData['title']
        );
        
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
        if ($ebayAPI && isset($ebayData['id'])) {
            $itemDetails = $ebayAPI->getItemDetails($ebayData['id']);
            if ($itemDetails) {
                // Get condition (REQUIRED - always from GetItem)
                $condition = $itemDetails['condition'] ?? 'Used';
                
                // Get SKU (REQUIRED - always from GetItem)
                $sku = $itemDetails['sku'] ?? '';
                
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
        $storeCategoryHierarchy = $this->extractStoreCategoryHierarchy(
            $ebayData['store_category_id'] ?? null,
            $ebayData['store_category2_id'] ?? null,
            $ebayAPI
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
}
