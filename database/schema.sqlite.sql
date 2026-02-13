-- Database Schema for Flip and Strip E-commerce
-- SQLite Version

-- Products table
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ebay_item_id TEXT UNIQUE,
    sku TEXT,
    name TEXT NOT NULL,
    description TEXT,
    price REAL NOT NULL,
    sale_price REAL,
    discount_percentage INTEGER,
    quantity INTEGER DEFAULT 1,
    category TEXT,
    manufacturer TEXT,
    model TEXT,
    condition_name TEXT,
    weight REAL,
    length REAL,
    width REAL,
    height REAL,
    image_url TEXT,
    images TEXT, -- JSON stored as TEXT
    ebay_url TEXT,
    source TEXT DEFAULT 'manual', -- 'ebay' or 'manual'
    show_on_website INTEGER DEFAULT 1, -- 1 = visible, 0 = hidden
    warehouse_id INTEGER,
    is_active INTEGER DEFAULT 1,
    -- eBay store category hierarchy (exact 3-level mapping)
    ebay_store_cat1_id INTEGER,
    ebay_store_cat1_name TEXT,
    ebay_store_cat2_id INTEGER,
    ebay_store_cat2_name TEXT,
    ebay_store_cat3_id INTEGER,
    ebay_store_cat3_name TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
);

CREATE INDEX IF NOT EXISTS idx_products_category ON products(category);
CREATE INDEX IF NOT EXISTS idx_products_sku ON products(sku);
CREATE INDEX IF NOT EXISTS idx_products_ebay_item_id ON products(ebay_item_id);
CREATE INDEX IF NOT EXISTS idx_products_is_active ON products(is_active);
CREATE INDEX IF NOT EXISTS idx_products_manufacturer ON products(manufacturer);
CREATE INDEX IF NOT EXISTS idx_products_source ON products(source);
CREATE INDEX IF NOT EXISTS idx_products_show_on_website ON products(show_on_website);
CREATE INDEX IF NOT EXISTS idx_products_ebay_store_cat1 ON products(ebay_store_cat1_id);
CREATE INDEX IF NOT EXISTS idx_products_ebay_store_cat2 ON products(ebay_store_cat2_id);
CREATE INDEX IF NOT EXISTS idx_products_ebay_store_cat3 ON products(ebay_store_cat3_id);

-- Categories table
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    parent_id INTEGER,
    image_url TEXT,
    sort_order INTEGER DEFAULT 0,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Orders table
CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_number TEXT UNIQUE NOT NULL,
    customer_email TEXT NOT NULL,
    customer_name TEXT,
    customer_phone TEXT,
    billing_address TEXT, -- JSON stored as TEXT
    shipping_address TEXT, -- JSON stored as TEXT
    subtotal REAL NOT NULL,
    shipping_cost REAL DEFAULT 0,
    tax_amount REAL DEFAULT 0,
    discount_code TEXT,
    discount_amount REAL DEFAULT 0,
    total_amount REAL NOT NULL,
    payment_method TEXT DEFAULT 'paypal',
    payment_status TEXT DEFAULT 'pending',
    paypal_order_id TEXT,
    paypal_transaction_id TEXT,
    order_status TEXT DEFAULT 'pending',
    tracking_number TEXT,
    shipped_at TEXT,
    notes TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_orders_order_number ON orders(order_number);
CREATE INDEX IF NOT EXISTS idx_orders_customer_email ON orders(customer_email);
CREATE INDEX IF NOT EXISTS idx_orders_order_status ON orders(order_status);
CREATE INDEX IF NOT EXISTS idx_orders_payment_status ON orders(payment_status);

-- Order items table
CREATE TABLE IF NOT EXISTS order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    product_name TEXT NOT NULL,
    product_sku TEXT,
    quantity INTEGER NOT NULL,
    unit_price REAL NOT NULL,
    total_price REAL NOT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
);

-- Coupons table for discount codes
CREATE TABLE IF NOT EXISTS coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    description TEXT,
    discount_type TEXT NOT NULL CHECK(discount_type IN ('percentage', 'fixed')),
    discount_value REAL NOT NULL,
    minimum_purchase REAL DEFAULT 0,
    max_uses INTEGER DEFAULT NULL,
    times_used INTEGER DEFAULT 0,
    expires_at TEXT,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_coupons_code ON coupons(code);
CREATE INDEX IF NOT EXISTS idx_coupons_is_active ON coupons(is_active);
CREATE INDEX IF NOT EXISTS idx_coupons_expires_at ON coupons(expires_at);

-- Sync log table for eBay synchronization
CREATE TABLE IF NOT EXISTS ebay_sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sync_type TEXT NOT NULL,
    items_processed INTEGER DEFAULT 0,
    items_added INTEGER DEFAULT 0,
    items_updated INTEGER DEFAULT 0,
    items_failed INTEGER DEFAULT 0,
    status TEXT DEFAULT 'running',
    error_message TEXT,
    started_at TEXT DEFAULT (datetime('now')),
    completed_at TEXT,
    last_sync_timestamp TEXT
);

CREATE INDEX IF NOT EXISTS idx_ebay_sync_log_status ON ebay_sync_log(status);
CREATE INDEX IF NOT EXISTS idx_ebay_sync_log_sync_type ON ebay_sync_log(sync_type);

-- Admin users table
CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    full_name TEXT,
    role TEXT DEFAULT 'admin',
    is_active INTEGER DEFAULT 1,
    last_login TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_admin_users_username ON admin_users(username);
CREATE INDEX IF NOT EXISTS idx_admin_users_email ON admin_users(email);

-- Warehouses table
CREATE TABLE IF NOT EXISTS warehouses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    code TEXT UNIQUE,
    location TEXT,
    address_line1 TEXT,
    address_line2 TEXT,
    city TEXT,
    state TEXT,
    postal_code TEXT,
    country_code TEXT DEFAULT 'US',
    phone TEXT,
    email TEXT,
    is_default INTEGER DEFAULT 0,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);

-- Homepage category mappings table
CREATE TABLE IF NOT EXISTS homepage_category_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    homepage_category TEXT NOT NULL,
    ebay_store_cat1_name TEXT NOT NULL,
    priority INTEGER DEFAULT 0,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now')),
    UNIQUE(homepage_category, ebay_store_cat1_name)
);

CREATE INDEX IF NOT EXISTS idx_homepage_cat ON homepage_category_mappings(homepage_category);
CREATE INDEX IF NOT EXISTS idx_ebay_cat1 ON homepage_category_mappings(ebay_store_cat1_name);
CREATE INDEX IF NOT EXISTS idx_is_active ON homepage_category_mappings(is_active);

-- Insert default categories
INSERT OR IGNORE INTO categories (name, slug, description, sort_order) VALUES
('Motorcycle Parts', 'motorcycle', 'Parts for motorcycles from all major brands', 1),
('ATV/UTV Parts', 'atv', 'Parts for ATVs, UTVs and quads', 2),
('Boat Parts', 'boat', 'Parts for boats and marine vehicles', 3),
('Automotive Parts', 'automotive', 'Auto and truck parts', 4),
('Biker Gifts', 'gifts', 'Gifts and accessories for bikers', 5);
