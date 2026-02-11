<?php
/**
 * Order Model
 * Handles order data and database operations
 */

namespace FAS\Models;

class Order
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Create new order
     * 
     * @param array $data Order data with keys: order_number, customer_email, customer_name, 
     *                    customer_phone, billing_address, shipping_address, subtotal, 
     *                    shipping_cost, tax_amount, discount_code, discount_amount,
     *                    total_amount, payment_method, payment_status, paypal_order_id, 
     *                    paypal_transaction_id, order_status, notes
     * @return int|false Order ID on success, false on failure
     */
    public function create($data)
    {
        $sql = "INSERT INTO orders (
            order_number, customer_email, customer_name, customer_phone,
            billing_address, shipping_address, subtotal, shipping_cost, tax_amount,
            discount_code, discount_amount, total_amount, payment_method, payment_status,
            paypal_order_id, paypal_transaction_id, order_status, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([
            $data['order_number'],
            $data['customer_email'],
            $data['customer_name'] ?? null,
            $data['customer_phone'] ?? null,
            isset($data['billing_address']) ? json_encode($data['billing_address']) : null,
            isset($data['shipping_address']) ? json_encode($data['shipping_address']) : null,
            $data['subtotal'],
            $data['shipping_cost'] ?? 0,
            $data['tax_amount'] ?? 0,
            $data['discount_code'] ?? null,
            $data['discount_amount'] ?? 0,
            $data['total_amount'],
            $data['payment_method'] ?? 'paypal',
            $data['payment_status'] ?? 'pending',
            $data['paypal_order_id'] ?? null,
            $data['paypal_transaction_id'] ?? null,
            $data['order_status'] ?? 'pending',
            $data['notes'] ?? null
        ]);
        
        if ($result) {
            return $this->db->lastInsertId();
        }
        
        return false;
    }
    
    /**
     * Add items to order
     * 
     * @param int $orderId The order ID
     * @param array $items Array of items, each with keys: product_id, product_name, 
     *                     product_sku, quantity, unit_price, total_price
     * @return bool True on success
     */
    public function addItems($orderId, $items)
    {
        $sql = "INSERT INTO order_items (
            order_id, product_id, product_name, product_sku, quantity, unit_price, total_price
        ) VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        
        foreach ($items as $item) {
            $stmt->execute([
                $orderId,
                $item['product_id'],
                $item['product_name'],
                $item['product_sku'] ?? null,
                $item['quantity'],
                $item['unit_price'],
                $item['total_price']
            ]);
        }
        
        return true;
    }
    
    /**
     * Get order by ID
     */
    public function getById($id)
    {
        $sql = "SELECT * FROM orders WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get order by order number
     */
    public function getByOrderNumber($orderNumber)
    {
        $sql = "SELECT * FROM orders WHERE order_number = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$orderNumber]);
        
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get order by PayPal order ID
     */
    public function getByPayPalOrderId($paypalOrderId)
    {
        $sql = "SELECT * FROM orders WHERE paypal_order_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$paypalOrderId]);
        
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get order items
     */
    public function getItems($orderId)
    {
        $sql = "SELECT * FROM order_items WHERE order_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$orderId]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Update order
     */
    public function update($id, $data)
    {
        $fields = [];
        $params = [];
        
        $allowedFields = [
            'payment_status', 'order_status', 'paypal_transaction_id',
            'tracking_number', 'shipped_at', 'notes'
        ];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $params[] = $id;
        $sql = "UPDATE orders SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Generate unique order number
     */
    public function generateOrderNumber()
    {
        return 'FAS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
