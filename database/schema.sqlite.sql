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

-- Cached eBay seller rating data
CREATE TABLE IF NOT EXISTS ebay_seller_rating_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    store_name TEXT NOT NULL UNIQUE,
    seller_name TEXT,
    feedback_score INTEGER,
    positive_feedback_percent REAL,
    store_url TEXT,
    last_fetched_at TEXT,
    last_error TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_ebay_seller_rating_store_name ON ebay_seller_rating_cache(store_name);

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

-- Banners table (alert banners shown above the navbar on the front end)
CREATE TABLE IF NOT EXISTS banners (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message TEXT NOT NULL,
    bg_color TEXT NOT NULL DEFAULT 'danger',
    text_color TEXT NOT NULL DEFAULT 'white',
    link_url TEXT,
    link_text TEXT,
    is_dismissible INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    show_countdown INTEGER NOT NULL DEFAULT 0,
    countdown_end TEXT,
    starts_at TEXT,
    ends_at TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_banners_is_active ON banners(is_active);
CREATE INDEX IF NOT EXISTS idx_banners_sort_order ON banners(sort_order);

-- First-party analytics sessions
CREATE TABLE IF NOT EXISTS analytics_sessions (
    session_id TEXT PRIMARY KEY,
    visitor_id TEXT NOT NULL,
    started_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    ended_at TEXT,
    duration_seconds INTEGER DEFAULT 0,
    landing_page TEXT,
    last_page TEXT,
    referrer TEXT,
    utm_source TEXT,
    utm_medium TEXT,
    utm_campaign TEXT,
    utm_term TEXT,
    utm_content TEXT,
    device_type TEXT,
    browser TEXT,
    os TEXT,
    language TEXT,
    timezone TEXT,
    screen_width INTEGER,
    screen_height INTEGER,
    viewport_width INTEGER,
    viewport_height INTEGER,
    ip_hash TEXT,
    user_agent TEXT,
    client_ip TEXT,
    client_ip_source TEXT,
    cf_country TEXT,
    cf_region TEXT,
    cf_region_code TEXT,
    cf_city TEXT,
    cf_postal_code TEXT,
    cf_latitude REAL,
    cf_longitude REAL,
    cf_timezone TEXT,
    cf_ray TEXT,
    geo_source TEXT,
    cf_bot_score INTEGER,
    cf_verified_bot INTEGER DEFAULT 0,
    is_potential_bot INTEGER DEFAULT 0,
    bot_reason TEXT
);

CREATE TABLE IF NOT EXISTS analytics_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL,
    visitor_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    event_name TEXT,
    page_url TEXT,
    page_path TEXT,
    page_title TEXT,
    referrer TEXT,
    referrer_host TEXT,
    previous_page_path TEXT,
    page_sequence INTEGER DEFAULT 0,
    session_age_seconds INTEGER DEFAULT 0,
    session_expires_at TEXT,
    session_ttl_days INTEGER DEFAULT 0,
    visitor_first_seen_at TEXT,
    visitor_pageviews INTEGER DEFAULT 0,
    is_returning_visitor INTEGER DEFAULT 0,
    viewport_orientation TEXT,
    connection_type TEXT,
    save_data INTEGER DEFAULT 0,
    color_scheme TEXT,
    cookies_enabled INTEGER DEFAULT 0,
    product_id TEXT,
    product_name TEXT,
    product_sku TEXT,
    category TEXT,
    manufacturer TEXT,
    product_source TEXT,
    product_price REAL DEFAULT 0,
    condition_name TEXT,
    stock_quantity INTEGER DEFAULT 0,
    list_name TEXT,
    list_position INTEGER DEFAULT 0,
    quantity INTEGER DEFAULT 0,
    cart_items_count INTEGER DEFAULT 0,
    cart_unique_items INTEGER DEFAULT 0,
    cart_value REAL DEFAULT 0,
    coupon_code TEXT,
    coupon_status TEXT,
    discount_amount REAL DEFAULT 0,
    shipping_service TEXT,
    shipping_cost REAL DEFAULT 0,
    destination_state TEXT,
    checkout_step TEXT,
    payment_provider TEXT,
    order_id TEXT,
    order_number TEXT,
    revenue REAL DEFAULT 0,
    currency TEXT,
    search_term TEXT,
    link_text TEXT,
    link_source TEXT,
    target_url TEXT,
    target_host TEXT,
    banner_id TEXT,
    campaign_name TEXT,
    event_value REAL DEFAULT 0,
    scroll_depth INTEGER DEFAULT 0,
    duration_seconds INTEGER DEFAULT 0,
    metadata TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id)
);

CREATE TABLE IF NOT EXISTS analytics_ip_geo_cache (
    ip_hash TEXT PRIMARY KEY,
    status TEXT NOT NULL,
    source TEXT,
    country TEXT,
    region TEXT,
    region_code TEXT,
    city TEXT,
    postal_code TEXT,
    latitude REAL,
    longitude REAL,
    timezone TEXT,
    message TEXT,
    looked_up_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_analytics_sessions_started ON analytics_sessions(started_at);
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_visitor ON analytics_sessions(visitor_id);
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_country ON analytics_sessions(cf_country);
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_geo_source ON analytics_sessions(geo_source);
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_bot ON analytics_sessions(is_potential_bot);
CREATE INDEX IF NOT EXISTS idx_analytics_events_created ON analytics_events(created_at);
CREATE INDEX IF NOT EXISTS idx_analytics_events_type ON analytics_events(event_type);
CREATE INDEX IF NOT EXISTS idx_analytics_events_page ON analytics_events(page_path);
CREATE INDEX IF NOT EXISTS idx_analytics_events_product ON analytics_events(product_id);
CREATE INDEX IF NOT EXISTS idx_analytics_events_session ON analytics_events(session_id);
CREATE INDEX IF NOT EXISTS idx_analytics_events_referrer_host ON analytics_events(referrer_host);
CREATE INDEX IF NOT EXISTS idx_analytics_events_link_source ON analytics_events(link_source);
CREATE INDEX IF NOT EXISTS idx_analytics_events_coupon ON analytics_events(coupon_code);
CREATE INDEX IF NOT EXISTS idx_analytics_events_order ON analytics_events(order_id);
CREATE INDEX IF NOT EXISTS idx_analytics_events_target_host ON analytics_events(target_host);
CREATE INDEX IF NOT EXISTS idx_analytics_events_banner ON analytics_events(banner_id);
