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
     */
    public function syncFromEbay($ebayData)
    {
        $existing = $this->getByEbayId($ebayData['id']);
        
        // Map eBay category to local category
        $category = $this->mapEbayCategory(
            $ebayData['ebay_category_name'] ?? null,
            $ebayData['ebay_category_id'] ?? null,
            $ebayData['title']
        );
        
        $productData = [
            'ebay_item_id' => $ebayData['id'],
            'name' => $ebayData['title'],
            'description' => $ebayData['description'] ?? '',
            'price' => $ebayData['price'],
            'quantity' => $ebayData['quantity'] ?? 1,
            'category' => $category,
            'condition_name' => $ebayData['condition'] ?? 'Used',
            'image_url' => $ebayData['image'],
            'images' => $ebayData['images'] ?? [],
            'ebay_url' => $ebayData['url'] ?? null,
            'source' => 'ebay'
        ];
        
        if ($existing) {
            // Don't update category on sync - preserve admin's setting
            // show_on_website is already excluded from productData for existing products
            unset($productData['category']);
            return $this->update($existing['id'], $productData);
        } else {
            // New eBay products default to visible with auto-mapped category
            $productData['show_on_website'] = 1;
            return $this->create($productData);
        }
    }
    
    public function updateImages($prodId, $newImagesJson) {
        $sql = "UPDATE products SET images = :imgs WHERE id = :pid";
        $stmt = $this->connection->prepare($sql);
        $stmt->bindParam(':imgs', $newImagesJson);
        $stmt->bindParam(':pid', $prodId);
        return $stmt->execute();
    }
}
