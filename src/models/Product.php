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
        // Default category
        $category = 'automotive';
        
        // Convert to lowercase for case-insensitive matching
        $categoryName = strtolower($ebayCategoryName ?? '');
        $title = strtolower($itemTitle ?? '');
        
        // Category mapping based on keywords in category name and title
        $mappings = [
            'motorcycle' => ['motorcycle', 'motorbike', 'bike', 'harley', 'honda', 'yamaha', 'kawasaki', 'suzuki', 'ducati', 'triumph'],
            'atv' => ['atv', 'utv', 'quad', 'four wheeler', 'side by side', 'polaris', 'can-am', 'arctic cat'],
            'boat' => ['boat', 'marine', 'watercraft', 'jet ski', 'outboard', 'inboard', 'yacht', 'fishing', 'nautical'],
            'automotive' => ['auto', 'car', 'truck', 'vehicle', 'ford', 'chevy', 'dodge', 'gmc'],
            'gifts' => ['gift', 'apparel', 'clothing', 'shirt', 'hat', 'collectible', 'memorabilia', 'keychain', 'accessory']
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
        
        // Debug logging
        error_log("[Product Sync Debug] Item ID: " . $ebayData['id']);
        error_log("[Product Sync Debug] Title: " . $ebayData['title']);
        error_log("[Product Sync Debug] eBay Brand from GetSellerList: " . ($ebayData['brand'] ?? 'NULL'));
        error_log("[Product Sync Debug] eBay MPN from GetSellerList: " . ($ebayData['mpn'] ?? 'NULL'));
        
        // Priority 1.5: If Brand/MPN not found in GetSellerList, try GetItem API (more reliable)
        // Also get dimensions and weight from GetItem
        $weight = $ebayData['weight'] ?? null;
        $length = $ebayData['length'] ?? null;
        $width = $ebayData['width'] ?? null;
        $height = $ebayData['height'] ?? null;
        
        if ((!$manufacturer || !$model || !$weight) && $ebayAPI && isset($ebayData['id'])) {
            error_log("[Product Sync Debug] Brand/MPN/Dimensions missing, calling GetItem API for ItemID: " . $ebayData['id']);
            $itemDetails = $ebayAPI->getItemDetails($ebayData['id']);
            if ($itemDetails) {
                if (!$manufacturer && $itemDetails['brand']) {
                    $manufacturer = $itemDetails['brand'];
                    error_log("[Product Sync Debug] Brand from GetItem: " . $manufacturer);
                }
                if (!$model && $itemDetails['mpn']) {
                    $model = $itemDetails['mpn'];
                    error_log("[Product Sync Debug] MPN from GetItem: " . $model);
                }
                // Get dimensions and weight from GetItem
                if (!$weight && $itemDetails['weight']) {
                    $weight = $itemDetails['weight'];
                    error_log("[Product Sync Debug] Weight from GetItem: " . $weight . " lbs");
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
                if ($length || $width || $height) {
                    error_log("[Product Sync Debug] Dimensions from GetItem: L:" . ($length ?? 'NULL') . " W:" . ($width ?? 'NULL') . " H:" . ($height ?? 'NULL') . " inches");
                }
            }
        }
        
        error_log("[Product Sync Debug] After GetItem - manufacturer: " . ($manufacturer ?? 'NULL'));
        error_log("[Product Sync Debug] After GetItem - model: " . ($model ?? 'NULL'));
        
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
        
        // Debug: Log final values before database operation
        error_log("[Product Sync Debug] Final manufacturer for DB: " . ($manufacturer ?? 'NULL'));
        error_log("[Product Sync Debug] Final model for DB: " . ($model ?? 'NULL'));
        error_log("[Product Sync Debug] Store category ID: " . ($ebayData['store_category_id'] ?? 'NULL'));
        error_log("[Product Sync Debug] Store category 2 ID: " . ($ebayData['store_category2_id'] ?? 'NULL'));
        
        $productData = [
            'ebay_item_id' => $ebayData['id'],
            'sku' => $ebayData['sku'] ?? null,
            'name' => $ebayData['title'],
            'description' => $ebayData['description'] ?? '',
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
            'image_url' => $ebayData['image'],
            'images' => $ebayData['images'] ?? [],
            'ebay_url' => $ebayData['url'] ?? null,
            'source' => 'ebay'
        ];
        
        // Download images from eBay to local gallery directory
        $productData = $this->downloadProductImages($productData, $ebayData['id']);
        
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
            
            return $this->update($existing['id'], $productData);
        } else {
            // New eBay products default to visible with auto-mapped category and store data
            $productData['show_on_website'] = 1;
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
     * Download image from URL and save to gallery directory
     * 
     * @param string $imageUrl The URL of the image to download
     * @param string $ebayItemId The eBay item ID (used for filename)
     * @param int $imageIndex The index of the image (for multiple images)
     * @return string|false The local path to the saved image, or false on failure
     */
    public function downloadImage($imageUrl, $ebayItemId, $imageIndex = 0) {
        if (empty($imageUrl) || empty($ebayItemId)) {
            return false;
        }
        
        // Create gallery directory if it doesn't exist
        $galleryDir = __DIR__ . '/../../gallery';
        if (!file_exists($galleryDir)) {
            mkdir($galleryDir, 0755, true);
        }
        
        // Generate filename: ebayItemId.jpg or ebayItemId_1.jpg, ebayItemId_2.jpg, etc.
        $extension = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($extension) || !in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $extension = 'jpg'; // Default to jpg if extension not found or invalid
        }
        
        if ($imageIndex === 0) {
            $filename = $ebayItemId . '.' . $extension;
        } else {
            $filename = $ebayItemId . '_' . $imageIndex . '.' . $extension;
        }
        
        $localPath = $galleryDir . '/' . $filename;
        $relativePath = 'gallery/' . $filename;
        
        // Skip if file already exists
        if (file_exists($localPath)) {
            return $relativePath;
        }
        
        // Download the image using cURL
        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // eBay uses HTTPS
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($imageData === false || $httpCode !== 200) {
            error_log("[Image Download] Failed to download image from $imageUrl: HTTP $httpCode, Error: $error");
            return false;
        }
        
        // Save the image to the gallery directory
        $result = file_put_contents($localPath, $imageData);
        if ($result === false) {
            error_log("[Image Download] Failed to save image to $localPath");
            return false;
        }
        
        return $relativePath;
    }
    
    /**
     * Download all images for a product from eBay
     * 
     * @param array $productData The product data containing image URLs
     * @param string $ebayItemId The eBay item ID
     * @return array Updated product data with local image paths
     */
    public function downloadProductImages($productData, $ebayItemId) {
        if (empty($ebayItemId)) {
            return $productData;
        }
        
        // Download main image
        if (!empty($productData['image_url']) && filter_var($productData['image_url'], FILTER_VALIDATE_URL)) {
            $localPath = $this->downloadImage($productData['image_url'], $ebayItemId, 0);
            if ($localPath !== false) {
                $productData['image_url'] = $localPath;
            }
        }
        
        // Download additional images
        if (!empty($productData['images']) && is_array($productData['images'])) {
            $localImages = [];
            $imageIndex = 0;
            
            foreach ($productData['images'] as $imageUrl) {
                if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $localPath = $this->downloadImage($imageUrl, $ebayItemId, $imageIndex);
                    if ($localPath !== false) {
                        $localImages[] = $localPath;
                    } else {
                        // Keep original URL if download failed
                        $localImages[] = $imageUrl;
                    }
                } else {
                    // Already a local path
                    $localImages[] = $imageUrl;
                }
                $imageIndex++;
            }
            
            $productData['images'] = $localImages;
        }
        
        return $productData;
    }
}
