<?php
/**
 * Warehouse Model
 * Handles warehouse data and operations
 */

namespace FAS\Models;

class Warehouse
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Get all active warehouses
     */
    public function getAll($includeInactive = false)
    {
        $sql = "SELECT * FROM warehouses";
        
        if (!$includeInactive) {
            $sql .= " WHERE is_active = 1";
        }
        
        $sql .= " ORDER BY is_default DESC, name ASC";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get warehouse by ID
     */
    public function getById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM warehouses WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get warehouse by code
     */
    public function getByCode($code)
    {
        $stmt = $this->db->prepare("SELECT * FROM warehouses WHERE code = ?");
        $stmt->execute([$code]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get default warehouse
     */
    public function getDefault()
    {
        $stmt = $this->db->query("SELECT * FROM warehouses WHERE is_default = 1 AND is_active = 1 LIMIT 1");
        $warehouse = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // If no default warehouse, get any active warehouse
        if (!$warehouse) {
            $stmt = $this->db->query("SELECT * FROM warehouses WHERE is_active = 1 LIMIT 1");
            $warehouse = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        
        return $warehouse;
    }
    
    /**
     * Get warehouse for a specific product
     */
    public function getForProduct($productId)
    {
        $stmt = $this->db->prepare("
            SELECT w.* FROM warehouses w
            INNER JOIN products p ON p.warehouse_id = w.id
            WHERE p.id = ? AND w.is_active = 1
        ");
        $stmt->execute([$productId]);
        $warehouse = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Fallback to default warehouse if product has no warehouse assigned
        if (!$warehouse) {
            $warehouse = $this->getDefault();
        }
        
        return $warehouse;
    }
    
    /**
     * Get warehouse for cart items (finds optimal warehouse)
     */
    public function getForCartItems($items)
    {
        if (empty($items)) {
            return $this->getDefault();
        }
        
        // Get all warehouses for products in cart
        $productIds = array_column($items, 'id');
        
        // If no product IDs found in cart items, return default warehouse
        // This happens when cart items don't have 'id' field (e.g., from frontend)
        if (empty($productIds)) {
            return $this->getDefault();
        }
        
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        
        $stmt = $this->db->prepare("
            SELECT w.*, COUNT(p.id) as product_count
            FROM warehouses w
            INNER JOIN products p ON p.warehouse_id = w.id
            WHERE p.id IN ($placeholders) AND w.is_active = 1
            GROUP BY w.id
            ORDER BY product_count DESC, w.is_default DESC
            LIMIT 1
        ");
        $stmt->execute($productIds);
        $warehouse = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Fallback to default if no warehouse found
        if (!$warehouse) {
            $warehouse = $this->getDefault();
        }
        
        return $warehouse;
    }
    
    /**
     * Create new warehouse
     */
    public function create($data)
    {
        $stmt = $this->db->prepare("
            INSERT INTO warehouses (
                name, code, address_line1, address_line2, city, state, 
                postal_code, country_code, phone, email, is_active, is_default
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['name'],
            $data['code'],
            $data['address_line1'],
            $data['address_line2'] ?? null,
            $data['city'],
            $data['state'],
            $data['postal_code'],
            $data['country_code'] ?? 'US',
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['is_active'] ?? 1,
            $data['is_default'] ?? 0
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Update warehouse
     */
    public function update($id, $data)
    {
        $fields = [];
        $values = [];
        
        $allowedFields = [
            'name', 'code', 'address_line1', 'address_line2', 'city', 'state',
            'postal_code', 'country_code', 'phone', 'email', 'is_active', 'is_default'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = datetime('now')";
        $values[] = $id;
        
        $sql = "UPDATE warehouses SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($values);
    }
    
    /**
     * Delete warehouse
     */
    public function delete($id)
    {
        // Don't allow deleting the default warehouse
        $warehouse = $this->getById($id);
        if ($warehouse && $warehouse['is_default']) {
            throw new \Exception('Cannot delete default warehouse');
        }
        
        // Set products' warehouse_id to NULL
        $stmt = $this->db->prepare("UPDATE products SET warehouse_id = NULL WHERE warehouse_id = ?");
        $stmt->execute([$id]);
        
        // Delete warehouse
        $stmt = $this->db->prepare("DELETE FROM warehouses WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Set warehouse as default
     */
    public function setAsDefault($id)
    {
        // Remove default from all warehouses
        $this->db->exec("UPDATE warehouses SET is_default = 0");
        
        // Set this warehouse as default
        $stmt = $this->db->prepare("UPDATE warehouses SET is_default = 1, is_active = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
