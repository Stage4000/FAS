<?php
/**
 * Coupon Model
 * Handles coupon/discount code operations
 */

namespace FAS\Models;

class Coupon
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Validate and get coupon by code
     */
    public function validateCoupon($code, $subtotal = 0)
    {
        // Get database driver to use appropriate datetime function
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        // Use appropriate datetime function based on database driver
        // This is safe as we're using a whitelist approach with predefined strings
        switch ($driver) {
            case 'mysql':
                $dateTimeCheck = "expires_at > NOW()";
                break;
            case 'sqlite':
                $dateTimeCheck = "expires_at > datetime('now')";
                break;
            case 'pgsql':
                $dateTimeCheck = "expires_at > NOW()";
                break;
            default:
                // Fallback to SQLite syntax for unknown drivers
                $dateTimeCheck = "expires_at > datetime('now')";
                error_log("Unknown database driver: $driver. Using SQLite datetime syntax.");
                break;
        }
        
        $sql = "SELECT * FROM coupons 
                WHERE code = ? 
                AND is_active = 1 
                AND (expires_at IS NULL OR $dateTimeCheck)
                AND (max_uses IS NULL OR times_used < max_uses)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$code]);
        $coupon = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$coupon) {
            return ['valid' => false, 'message' => 'Invalid or expired coupon code'];
        }
        
        // Check minimum purchase
        if ($subtotal < $coupon['minimum_purchase']) {
            return [
                'valid' => false, 
                'message' => 'Minimum purchase of $' . number_format($coupon['minimum_purchase'], 2) . ' required'
            ];
        }
        
        return [
            'valid' => true,
            'coupon' => $coupon,
            'discount' => $this->calculateDiscount($coupon, $subtotal)
        ];
    }
    
    /**
     * Calculate discount amount
     */
    public function calculateDiscount($coupon, $subtotal)
    {
        if ($coupon['discount_type'] === 'percentage') {
            return ($subtotal * $coupon['discount_value']) / 100;
        } else {
            return min($coupon['discount_value'], $subtotal);
        }
    }
    
    /**
     * Increment coupon usage
     */
    public function incrementUsage($code)
    {
        $sql = "UPDATE coupons SET times_used = times_used + 1 WHERE code = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$code]);
    }
    
    /**
     * Get all coupons for admin
     */
    public function getAll()
    {
        $sql = "SELECT * FROM coupons ORDER BY created_at DESC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Create new coupon
     */
    public function create($data)
    {
        $sql = "INSERT INTO coupons (code, description, discount_type, discount_value, 
                minimum_purchase, max_uses, expires_at, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            strtoupper($data['code']),
            $data['description'],
            $data['discount_type'],
            $data['discount_value'],
            $data['minimum_purchase'] ?? 0,
            $data['max_uses'] ?? null,
            $data['expires_at'] ?? null,
            $data['is_active'] ?? 1
        ]);
    }
    
    /**
     * Update coupon
     */
    public function update($id, $data)
    {
        $sql = "UPDATE coupons SET 
                description = ?, 
                discount_type = ?, 
                discount_value = ?, 
                minimum_purchase = ?, 
                max_uses = ?, 
                expires_at = ?, 
                is_active = ? 
                WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['description'],
            $data['discount_type'],
            $data['discount_value'],
            $data['minimum_purchase'] ?? 0,
            $data['max_uses'] ?? null,
            $data['expires_at'] ?? null,
            $data['is_active'] ?? 1,
            $id
        ]);
    }
    
    /**
     * Delete coupon
     */
    public function delete($id)
    {
        $sql = "DELETE FROM coupons WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }
    
    /**
     * Get coupon by ID
     */
    public function getById($id)
    {
        $sql = "SELECT * FROM coupons WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
