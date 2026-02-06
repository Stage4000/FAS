<?php
/**
 * Product Model
 * Handles product data and database operations
 */

namespace FAS\Models;

class Product
{
    private $db;
    
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
            manufacturer, model, condition_name, weight, length, width, height, image_url, images, ebay_url, source, show_on_website
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
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
            isset($data['show_on_website']) ? $data['show_on_website'] : 1
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
            'image_url', 'images', 'ebay_url', 'source', 'show_on_website'
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
        
        // Category mapping based on keywords in category name and title
        // Order matters: more specific categories first to prevent false matches
        $mappings = [
            'motorcycle' => ['motorcycle', 'motorbike', 'bike', 'harley', 'honda', 'yamaha', 'kawasaki', 'suzuki', 'ducati', 'triumph'],
            'atv' => ['atv', 'utv', 'quad', 'four wheeler', 'side by side', 'polaris', 'can-am', 'arctic cat'],
            'boat' => ['boat', 'marine', 'watercraft', 'jet ski', 'outboard', 'inboard', 'yacht', 'fishing', 'nautical'],
            'gifts' => ['gift', 'apparel', 'clothing', 'shirt', 'hat', 'watch', 'collectible', 'memorabilia', 'keychain', 'accessory'],
            'automotive' => ['auto', 'automobile', 'car', 'ford', 'chevy', 'chevrolet', 'dodge', 'gmc']
        ];
        
        // Check category name and title for keywords
        foreach ($mappings as $localCategory => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($categoryName, $keyword) !== false || strpos($title, $keyword) !== false) {
                    return $localCategory;
                }
            }
        }
        
        return $category;
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
        
        // Priority 1.5: If Brand/MPN not found in GetSellerList, try GetItem API (more reliable)
        // Also get dimensions, weight, description, SKU, and images from GetItem
        $weight = $ebayData['weight'] ?? null;
        $length = $ebayData['length'] ?? null;
        $width = $ebayData['width'] ?? null;
        $height = $ebayData['height'] ?? null;
        $description = $ebayData['description'] ?? '';
        $sku = $ebayData['sku'] ?? '';
        $images = $ebayData['images'] ?? [];
        $image = $ebayData['image'] ?? null;
        
        // Call GetItem only if we're missing critical data that affects functionality:
        // Priority order: Only call GetItem if missing high-priority data
        // - Description is important but not critical (can be empty)
        // - SKU is important but not critical (we have eBay ID)
        // - Images should be fetched but GetSellerEvents often has them
        // Critical: Brand, Model, Weight (affects shipping)
        $needsGetItem = (!$manufacturer || !$model || !$weight);
        
        // Secondary check: if we have critical data but missing description/SKU/images
        // Only call GetItem if we're missing at least 2 of these secondary fields
        if (!$needsGetItem) {
            $missingSecondary = 0;
            if (empty($description)) $missingSecondary++;
            if (empty($sku)) $missingSecondary++;
            if (empty($images)) $missingSecondary++;
            $needsGetItem = ($missingSecondary >= 2);
        }
        
        if ($needsGetItem && $ebayAPI && isset($ebayData['id'])) {
            error_log("[Product Sync Debug] Missing critical data, calling GetItem API for ItemID: " . $ebayData['id']);
            $itemDetails = $ebayAPI->getItemDetails($ebayData['id']);
            if ($itemDetails) {
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
                // Get description, SKU, and images from GetItem
                if (empty($description) && !empty($itemDetails['description'])) {
                    $description = $itemDetails['description'];
                }
                if (empty($sku) && !empty($itemDetails['sku'])) {
                    $sku = $itemDetails['sku'];
                }
                if (empty($images) && !empty($itemDetails['images'])) {
                    $images = $itemDetails['images'];
                    $image = $itemDetails['image'];
                }
            }
        }
        
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
            'condition_name' => $ebayData['condition'] ?? 'Used',
            'weight' => $weight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'image_url' => $image,
            'images' => $images,
            'ebay_url' => $ebayData['url'] ?? null,
            'source' => 'ebay'
        ];
        
        // Check if product has required shipping dimensions/weight
        // If missing, set show_on_website to 0 (unlisted) until admin adds them
        $hasDimensions = !empty($weight) && !empty($length) && !empty($width) && !empty($height);
        
        if ($existing) {
            // Don't update category on sync - preserve admin's setting
            unset($productData['category']);
            
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
}
