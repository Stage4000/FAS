<?php
/**
 * Homepage Category Mapping Model
 * Manages mappings between eBay store categories (level 1) and homepage categories
 */

namespace FAS\Models;

class HomepageCategoryMapping
{
    private $db;
    
    // Available homepage categories
    public const HOMEPAGE_CATEGORIES = [
        'motorcycle' => 'Motorcycle Parts',
        'atv' => 'ATV/UTV Parts',
        'boat' => 'Boat Parts',
        'automotive' => 'Automotive Parts',
        'gifts' => 'Gifts',
        'other' => 'Other'
    ];
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Normalize eBay category name to uppercase for consistent storage and lookup
     */
    private function normalizeEbayCategoryName($name)
    {
        return strtoupper(trim($name));
    }
    
    /**
     * Get all mappings
     */
    public function getAll()
    {
        $sql = "SELECT * FROM homepage_category_mappings 
                WHERE is_active = 1 
                ORDER BY priority DESC, homepage_category ASC, ebay_store_cat1_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get mappings grouped by eBay category
     */
    public function getAllGroupedByEbayCategory()
    {
        $sql = "SELECT * FROM homepage_category_mappings 
                WHERE is_active = 1 
                ORDER BY ebay_store_cat1_name ASC, priority DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        $mappings = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $grouped = [];
        
        foreach ($mappings as $mapping) {
            $ebayCategory = $mapping['ebay_store_cat1_name'];
            if (!isset($grouped[$ebayCategory])) {
                $grouped[$ebayCategory] = [];
            }
            $grouped[$ebayCategory][] = $mapping;
        }
        
        return $grouped;
    }
    
    /**
     * Get homepage category for an eBay category name
     * Returns the homepage category with highest priority, or null if no mapping exists
     */
    public function getHomepageCategoryForEbayCategory($ebayCat1Name)
    {
        if (empty($ebayCat1Name)) {
            return null;
        }
        
        $normalizedName = $this->normalizeEbayCategoryName($ebayCat1Name);
        
        $sql = "SELECT homepage_category FROM homepage_category_mappings 
                WHERE ebay_store_cat1_name = ? AND is_active = 1 
                ORDER BY priority DESC 
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$normalizedName]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ? $result['homepage_category'] : null;
    }
    
    /**
     * Create or update a mapping
     */
    public function createOrUpdate($homepageCategory, $ebayCat1Name, $priority = 0)
    {
        if (empty($homepageCategory) || empty($ebayCat1Name)) {
            return false;
        }
        
        // Normalize eBay category name to uppercase
        $normalizedName = $this->normalizeEbayCategoryName($ebayCat1Name);
        
        // Check if mapping exists
        $sql = "SELECT id FROM homepage_category_mappings 
                WHERE homepage_category = ? AND ebay_store_cat1_name = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$homepageCategory, $normalizedName]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing mapping
            $sql = "UPDATE homepage_category_mappings 
                    SET priority = ?, is_active = 1, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$priority, $existing['id']]);
        } else {
            // Create new mapping
            $sql = "INSERT INTO homepage_category_mappings 
                    (homepage_category, ebay_store_cat1_name, priority, is_active) 
                    VALUES (?, ?, ?, 1)";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$homepageCategory, $normalizedName, $priority]);
        }
    }
    
    /**
     * Delete a mapping
     */
    public function delete($id)
    {
        $sql = "DELETE FROM homepage_category_mappings WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }
    
    /**
     * Get eBay category names for a homepage category
     * Returns array of eBay category names mapped to this homepage category, ordered by priority
     */
    public function getEbayCategoriesForHomepageCategory($homepageCategory)
    {
        if (empty($homepageCategory)) {
            return [];
        }
        
        $sql = "SELECT ebay_store_cat1_name FROM homepage_category_mappings 
                WHERE homepage_category = ? AND is_active = 1 
                ORDER BY priority DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$homepageCategory]);
        
        $categories = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $categories[] = $row['ebay_store_cat1_name'];
        }
        
        return $categories;
    }
    
    /**
     * Get all unique eBay category names from products
     */
    public function getEbayCategoriesFromProducts()
    {
        $sql = "SELECT DISTINCT ebay_store_cat1_name 
                FROM products 
                WHERE ebay_store_cat1_name IS NOT NULL 
                  AND ebay_store_cat1_name != '' 
                ORDER BY ebay_store_cat1_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        $categories = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $categories[] = $row['ebay_store_cat1_name'];
        }
        
        return $categories;
    }
    
    /**
     * Get mapping by ID
     */
    public function getById($id)
    {
        $sql = "SELECT * FROM homepage_category_mappings WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
