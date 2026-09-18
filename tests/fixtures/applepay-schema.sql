-- Minimal synthetic fixture matching the existing FAS columns used by this service.
PRAGMA foreign_keys=ON;
CREATE TABLE products(id INTEGER PRIMARY KEY, name TEXT, sku TEXT, quantity INTEGER, price REAL,
 sale_price REAL, is_active INTEGER, show_on_website INTEGER, updated_at TEXT);
CREATE TABLE coupons(code TEXT PRIMARY KEY, times_used INTEGER DEFAULT 0);
CREATE TABLE orders(id INTEGER PRIMARY KEY AUTOINCREMENT,order_number TEXT UNIQUE,customer_email TEXT,
 customer_name TEXT,customer_phone TEXT,shipping_address TEXT,subtotal REAL,shipping_cost REAL,tax_amount REAL,
 discount_code TEXT,discount_amount REAL,total_amount REAL,payment_method TEXT,payment_status TEXT,order_status TEXT,
 paypal_order_id TEXT,paypal_transaction_id TEXT,notes TEXT,updated_at TEXT);
CREATE TABLE order_items(id INTEGER PRIMARY KEY,order_id INTEGER REFERENCES orders(id),product_id INTEGER REFERENCES products(id),
 product_name TEXT,product_sku TEXT,quantity INTEGER,unit_price REAL,total_price REAL);
INSERT INTO products VALUES(1,'Synthetic item','TEST-1',10,10.00,NULL,1,1,NULL);
INSERT INTO products VALUES(2,'Synthetic sale item','TEST-2',2,20.00,15.00,1,1,NULL);
INSERT INTO coupons VALUES('SAVE',0);
