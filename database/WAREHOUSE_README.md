# Warehouse Management System

## Overview
The warehouse management system allows you to manage multiple warehouse locations and automatically calculate shipping rates from the appropriate warehouse based on product location.

## Database Schema

### Warehouses Table
- `id` - Primary key
- `name` - Warehouse name (e.g., "Main Warehouse - Florida")
- `code` - Unique warehouse code (e.g., "FL-MAIN")
- `address_line1` - Street address
- `address_line2` - Additional address info (optional)
- `city` - City name (REQUIRED for EasyShip API)
- `state` - State/province code (REQUIRED for EasyShip API)
- `postal_code` - ZIP/postal code
- `country_code` - ISO country code (default: US)
- `phone` - Contact phone (optional)
- `email` - Contact email (optional)
- `is_active` - Whether warehouse is active (1=yes, 0=no)
- `is_default` - Default warehouse for new products (1=yes, 0=no)

### Products Table
- Added `warehouse_id` column linking to warehouses table
- Products without assigned warehouse will use the default warehouse

## Setup

### 1. Run Migration
```bash
php database/migrate-add-warehouses.php
```

This will:
- Create the warehouses table
- Add warehouse_id column to products table
- Create default Florida warehouse (code: FL-MAIN)
- Assign all existing products to default warehouse

### 2. Configure Additional Warehouses (Optional)

#### Via SQL:
```sql
INSERT INTO warehouses (
    name, code, address_line1, city, state, postal_code, 
    country_code, is_active, is_default
) VALUES (
    'West Coast Warehouse',
    'CA-MAIN',
    '456 Distribution Blvd',
    'Los Angeles',
    'CA',
    '90001',
    'US',
    1,
    0
);
```

#### Via PHP:
```php
require_once 'src/config/Database.php';
require_once 'src/models/Warehouse.php';

use FAS\Config\Database;
use FAS\Models\Warehouse;

$db = Database::getInstance()->getConnection();
$warehouseModel = new Warehouse($db);

$warehouseId = $warehouseModel->create([
    'name' => 'West Coast Warehouse',
    'code' => 'CA-MAIN',
    'address_line1' => '456 Distribution Blvd',
    'city' => 'Los Angeles',
    'state' => 'CA',
    'postal_code' => '90001',
    'country_code' => 'US',
    'is_active' => 1,
    'is_default' => 0
]);
```

### 3. Assign Products to Warehouses

```sql
-- Assign specific products to a warehouse
UPDATE products 
SET warehouse_id = (SELECT id FROM warehouses WHERE code = 'CA-MAIN')
WHERE category = 'motorcycle' AND manufacturer = 'Harley Davidson';

-- Or assign by product ID
UPDATE products SET warehouse_id = 2 WHERE id IN (1, 2, 3, 4);
```

## How It Works

### Shipping Rate Calculation

1. **Cart with Multiple Products:**
   - System finds the warehouse that contains the most products from the cart
   - Uses that warehouse as the origin for shipping calculation
   - Fallback to default warehouse if no products have assigned warehouses

2. **API Call to EasyShip:**
   - Origin address now includes: `line_1`, `line_2`, `city`, `state`, `postal_code`, `country_alpha2`
   - City and state are ALWAYS included (EasyShip recommendation)
   - Rates calculated based on distance from warehouse to customer

3. **Label Generation:**
   - When creating shipping label, uses the actual warehouse where product is located
   - Ensures accurate pickup location for carriers

## Warehouse Model API

```php
$warehouseModel = new Warehouse($db);

// Get all warehouses
$warehouses = $warehouseModel->getAll();

// Get default warehouse
$defaultWarehouse = $warehouseModel->getDefault();

// Get warehouse for specific product
$warehouse = $warehouseModel->getForProduct($productId);

// Get optimal warehouse for cart items
$warehouse = $warehouseModel->getForCartItems($cartItems);

// Create warehouse
$id = $warehouseModel->create($data);

// Update warehouse
$warehouseModel->update($id, $data);

// Set as default
$warehouseModel->setAsDefault($id);

// Delete warehouse
$warehouseModel->delete($id);
```

## EasyShip Integration

The EasyShipAPI class now accepts an optional warehouse parameter:

```php
$easyship = new EasyShipAPI();

// With specific warehouse
$rates = $easyship->getShippingRates($items, $destinationAddress, $warehouse);

// Without warehouse (uses default)
$rates = $easyship->getShippingRates($items, $destinationAddress);
```

### Origin Address Format
The origin address sent to EasyShip now includes:
- `line_1` - Street address
- `line_2` - Additional address (optional)
- `city` - City name (REQUIRED)
- `state` - State code (REQUIRED)
- `postal_code` - ZIP code
- `country_alpha2` - Country code

## Benefits

1. **Accurate Shipping Costs:** Calculate shipping from actual warehouse locations
2. **Multi-Location Support:** Support multiple warehouses/fulfillment centers
3. **Flexible Management:** Easy to add/remove/modify warehouse locations
4. **Automatic Selection:** System automatically picks optimal warehouse for orders
5. **Better Rates:** More accurate rates from EasyShip with complete address info

## Troubleshooting

### HTTP 403 from EasyShip
- Verify API key is valid and has permissions for rates endpoint
- Ensure all required address fields are provided (especially city and state)
- Check that warehouse has valid address with city and state

### No Rates Returned
- Verify origin address is complete with city and state
- Check that products have valid weights
- Ensure destination address is valid

### Products Not Using Correct Warehouse
- Check product's `warehouse_id` field
- Verify warehouse is active (`is_active = 1`)
- Ensure at least one warehouse is set as default

## Migration Rollback (if needed)

```sql
-- Remove warehouse_id from products
ALTER TABLE products DROP COLUMN warehouse_id;

-- Drop warehouses table
DROP TABLE warehouses;
```

Note: SQLite doesn't support DROP COLUMN, so you'd need to recreate the products table without the column.
