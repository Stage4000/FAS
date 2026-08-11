-- Database Schema for Flip and Strip E-commerce
-- MySQL/MariaDB

-- Products table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ebay_item_id VARCHAR(50) UNIQUE,
    sku VARCHAR(100),
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    quantity INT DEFAULT 1,
    category VARCHAR(100),
    manufacturer VARCHAR(100),
    model VARCHAR(100),
    condition_name VARCHAR(50),
    weight DECIMAL(8, 2),
    image_url VARCHAR(500),
    images JSON,
    ebay_url VARCHAR(500),
    source VARCHAR(20) DEFAULT 'manual', -- 'ebay' or 'manual'
    show_on_website BOOLEAN DEFAULT TRUE, -- TRUE = visible, FALSE = hidden
    free_shipping BOOLEAN DEFAULT FALSE, -- TRUE = always free shipping
    is_active BOOLEAN DEFAULT TRUE,
    -- eBay store category hierarchy (exact 3-level mapping)
    ebay_store_cat1_id INT,
    ebay_store_cat1_name VARCHAR(255),
    ebay_store_cat2_id INT,
    ebay_store_cat2_name VARCHAR(255),
    ebay_store_cat3_id INT,
    ebay_store_cat3_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_sku (sku),
    INDEX idx_ebay_item_id (ebay_item_id),
    INDEX idx_is_active (is_active),
    INDEX idx_manufacturer (manufacturer),
    INDEX idx_source (source),
    INDEX idx_show_on_website (show_on_website),
    INDEX idx_ebay_store_cat1 (ebay_store_cat1_id),
    INDEX idx_ebay_store_cat2 (ebay_store_cat2_id),
    INDEX idx_ebay_store_cat3 (ebay_store_cat3_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categories table
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    parent_id INT NULL,
    image_url VARCHAR(500),
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_name VARCHAR(255),
    customer_phone VARCHAR(50),
    billing_address JSON,
    shipping_address JSON,
    subtotal DECIMAL(10, 2) NOT NULL,
    shipping_cost DECIMAL(10, 2) DEFAULT 0,
    tax_amount DECIMAL(10, 2) DEFAULT 0,
    total_amount DECIMAL(10, 2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'paypal',
    payment_status VARCHAR(50) DEFAULT 'pending',
    paypal_order_id VARCHAR(100),
    paypal_transaction_id VARCHAR(100),
    order_status VARCHAR(50) DEFAULT 'pending',
    tracking_number VARCHAR(100),
    shipped_at TIMESTAMP NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order_number (order_number),
    INDEX idx_customer_email (customer_email),
    INDEX idx_order_status (order_status),
    INDEX idx_payment_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Order items table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    product_sku VARCHAR(100),
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    total_price DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Coupons table for discount codes
CREATE TABLE IF NOT EXISTS coupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255),
    discount_type ENUM('percentage', 'fixed') NOT NULL,
    discount_value DECIMAL(10, 2) NOT NULL,
    minimum_purchase DECIMAL(10, 2) DEFAULT 0,
    max_uses INT DEFAULT NULL,
    times_used INT DEFAULT 0,
    expires_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_is_active (is_active),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add sale_price column to products table if not exists
ALTER TABLE products ADD COLUMN IF NOT EXISTS sale_price DECIMAL(10, 2) NULL AFTER price;
ALTER TABLE products ADD COLUMN IF NOT EXISTS discount_percentage INT NULL AFTER sale_price;

-- Add source and show_on_website columns if they don't exist
ALTER TABLE products ADD COLUMN IF NOT EXISTS source VARCHAR(20) DEFAULT 'manual' AFTER ebay_url;
ALTER TABLE products ADD COLUMN IF NOT EXISTS show_on_website BOOLEAN DEFAULT TRUE AFTER source;

-- Add indexes for new columns
CREATE INDEX IF NOT EXISTS idx_source ON products(source);
CREATE INDEX IF NOT EXISTS idx_show_on_website ON products(show_on_website);

-- Add discount_code and discount_amount to orders table
ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_code VARCHAR(50) NULL AFTER tax_amount;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10, 2) DEFAULT 0 AFTER discount_code;

-- Sync log table for eBay synchronization
CREATE TABLE IF NOT EXISTS ebay_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sync_type VARCHAR(50) NOT NULL,
    items_processed INT DEFAULT 0,
    items_added INT DEFAULT 0,
    items_updated INT DEFAULT 0,
    items_failed INT DEFAULT 0,
    status VARCHAR(50) DEFAULT 'running',
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    last_sync_timestamp TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_sync_type (sync_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add last_sync_timestamp column if it doesn't exist
ALTER TABLE ebay_sync_log ADD COLUMN IF NOT EXISTS last_sync_timestamp TIMESTAMP NULL AFTER completed_at;

-- Cached eBay seller rating data
CREATE TABLE IF NOT EXISTS ebay_seller_rating_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_name VARCHAR(255) NOT NULL UNIQUE,
    seller_name VARCHAR(255),
    feedback_score INT,
    positive_feedback_percent DECIMAL(5, 1),
    store_url VARCHAR(500),
    last_fetched_at DATETIME NULL,
    last_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ebay_seller_rating_store_name (store_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin users table
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    full_name VARCHAR(255),
    role VARCHAR(50) DEFAULT 'admin',
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Homepage category mappings table
CREATE TABLE IF NOT EXISTS homepage_category_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    homepage_category VARCHAR(50) NOT NULL,
    ebay_store_cat1_name VARCHAR(255) NOT NULL,
    priority INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_mapping (homepage_category, ebay_store_cat1_name),
    INDEX idx_homepage_cat (homepage_category),
    INDEX idx_ebay_cat1 (ebay_store_cat1_name),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default categories
INSERT INTO categories (name, slug, description, sort_order) VALUES
('Motorcycle Parts', 'motorcycle', 'Parts for motorcycles from all major brands', 1),
('ATV/UTV Parts', 'atv', 'Parts for ATVs, UTVs and quads', 2),
('Boat Parts', 'boat', 'Parts for boats and marine vehicles', 3),
('Automotive Parts', 'automotive', 'Auto and truck parts', 4),
('Biker Gifts', 'gifts', 'Gifts and accessories for bikers', 5)
ON DUPLICATE KEY UPDATE 
    name = VALUES(name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

-- Banners table (alert banners shown above the navbar on the front end)
CREATE TABLE IF NOT EXISTS banners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message TEXT NOT NULL,
    bg_color VARCHAR(30) NOT NULL DEFAULT 'danger',
    text_color VARCHAR(30) NOT NULL DEFAULT 'white',
    link_url VARCHAR(500),
    link_text VARCHAR(255),
    is_dismissible BOOLEAN NOT NULL DEFAULT TRUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0,
    show_countdown BOOLEAN NOT NULL DEFAULT FALSE,
    countdown_end DATETIME,
    starts_at DATETIME,
    ends_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_banners_is_active (is_active),
    INDEX idx_banners_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- First-party analytics sessions
CREATE TABLE IF NOT EXISTS analytics_sessions (
    session_id VARCHAR(80) PRIMARY KEY,
    visitor_id VARCHAR(80) NOT NULL,
    started_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    duration_seconds INT DEFAULT 0,
    landing_page VARCHAR(1000),
    last_page VARCHAR(1000),
    referrer VARCHAR(1000),
    utm_source VARCHAR(255),
    utm_medium VARCHAR(255),
    utm_campaign VARCHAR(255),
    utm_term VARCHAR(255),
    utm_content VARCHAR(255),
    device_type VARCHAR(50),
    browser VARCHAR(100),
    os VARCHAR(100),
    language VARCHAR(50),
    timezone VARCHAR(100),
    screen_width INT,
    screen_height INT,
    viewport_width INT,
    viewport_height INT,
    ip_hash VARCHAR(64),
    user_agent VARCHAR(1000),
    client_ip VARCHAR(45),
    client_ip_source VARCHAR(40),
    cf_country VARCHAR(10),
    cf_region VARCHAR(255),
    cf_region_code VARCHAR(50),
    cf_city VARCHAR(255),
    cf_postal_code VARCHAR(40),
    cf_latitude DECIMAL(10, 6) NULL,
    cf_longitude DECIMAL(10, 6) NULL,
    cf_timezone VARCHAR(100),
    cf_ray VARCHAR(80),
    geo_source VARCHAR(40),
    cf_bot_score INT NULL,
    cf_verified_bot TINYINT(1) DEFAULT 0,
    is_potential_bot TINYINT(1) DEFAULT 0,
    bot_reason VARCHAR(500),
    INDEX idx_analytics_sessions_started (started_at),
    INDEX idx_analytics_sessions_visitor (visitor_id),
    INDEX idx_analytics_sessions_country (cf_country),
    INDEX idx_analytics_sessions_geo_source (geo_source),
    INDEX idx_analytics_sessions_bot (is_potential_bot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_ip_geo_cache (
    ip_hash VARCHAR(64) PRIMARY KEY,
    status VARCHAR(20) NOT NULL,
    source VARCHAR(40),
    country VARCHAR(10),
    region VARCHAR(255),
    region_code VARCHAR(50),
    city VARCHAR(255),
    postal_code VARCHAR(40),
    latitude DECIMAL(10, 6) NULL,
    longitude DECIMAL(10, 6) NULL,
    timezone VARCHAR(100),
    message VARCHAR(500),
    looked_up_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analytics_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(80) NOT NULL,
    visitor_id VARCHAR(80) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    event_name VARCHAR(255),
    page_url VARCHAR(1000),
    page_path VARCHAR(1000),
    page_title VARCHAR(255),
    referrer VARCHAR(1000),
    referrer_host VARCHAR(255),
    previous_page_path VARCHAR(1000),
    page_sequence INT DEFAULT 0,
    session_age_seconds INT DEFAULT 0,
    session_expires_at VARCHAR(40),
    session_ttl_days INT DEFAULT 0,
    visitor_first_seen_at VARCHAR(40),
    visitor_pageviews INT DEFAULT 0,
    is_returning_visitor TINYINT(1) DEFAULT 0,
    viewport_orientation VARCHAR(20),
    connection_type VARCHAR(50),
    save_data TINYINT(1) DEFAULT 0,
    color_scheme VARCHAR(20),
    cookies_enabled TINYINT(1) DEFAULT 0,
    product_id VARCHAR(80),
    product_name VARCHAR(500),
    product_sku VARCHAR(255),
    category VARCHAR(255),
    manufacturer VARCHAR(255),
    product_source VARCHAR(80),
    product_price DECIMAL(10, 2) DEFAULT 0,
    condition_name VARCHAR(100),
    stock_quantity INT DEFAULT 0,
    list_name VARCHAR(255),
    list_position INT DEFAULT 0,
    quantity INT DEFAULT 0,
    cart_items_count INT DEFAULT 0,
    cart_unique_items INT DEFAULT 0,
    cart_value DECIMAL(10, 2) DEFAULT 0,
    coupon_code VARCHAR(100),
    coupon_status VARCHAR(40),
    discount_amount DECIMAL(10, 2) DEFAULT 0,
    shipping_service VARCHAR(255),
    shipping_cost DECIMAL(10, 2) DEFAULT 0,
    destination_state VARCHAR(80),
    checkout_step VARCHAR(100),
    payment_provider VARCHAR(100),
    order_id VARCHAR(80),
    order_number VARCHAR(100),
    revenue DECIMAL(10, 2) DEFAULT 0,
    currency VARCHAR(10),
    search_term VARCHAR(255),
    link_text VARCHAR(255),
    link_source VARCHAR(100),
    target_url VARCHAR(1000),
    target_host VARCHAR(255),
    banner_id VARCHAR(80),
    campaign_name VARCHAR(255),
    event_value DECIMAL(10, 2) DEFAULT 0,
    scroll_depth INT DEFAULT 0,
    duration_seconds INT DEFAULT 0,
    metadata JSON,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_analytics_events_created (created_at),
    INDEX idx_analytics_events_type (event_type),
    INDEX idx_analytics_events_page (page_path),
    INDEX idx_analytics_events_product (product_id),
    INDEX idx_analytics_events_session (session_id),
    INDEX idx_analytics_events_referrer_host (referrer_host),
    INDEX idx_analytics_events_link_source (link_source),
    INDEX idx_analytics_events_coupon (coupon_code),
    INDEX idx_analytics_events_order (order_id),
    INDEX idx_analytics_events_target_host (target_host),
    INDEX idx_analytics_events_banner (banner_id),
    CONSTRAINT fk_analytics_events_session
        FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
