-- Disposable synthetic inventory: no dependency on an existing local database.
CREATE TABLE products (id INTEGER PRIMARY KEY, ebay_item_id TEXT, name TEXT, description TEXT, sku TEXT,
price REAL, sale_price REAL, category TEXT, manufacturer TEXT, model TEXT, condition_name TEXT,
quantity INTEGER, is_active INTEGER, show_on_website INTEGER, free_shipping INTEGER, image_url TEXT,
images TEXT, ebay_url TEXT, source TEXT, weight REAL, length REAL, width REAL, height REAL,
ebay_store_cat1_id INTEGER, ebay_store_cat1_name TEXT, ebay_store_cat2_id INTEGER, ebay_store_cat2_name TEXT,
ebay_store_cat3_id INTEGER, ebay_store_cat3_name TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE homepage_category_mappings (id INTEGER PRIMARY KEY, homepage_category TEXT, ebay_store_cat1_name TEXT, is_active INTEGER, priority INTEGER);
INSERT INTO products(id,name,description,sku,price,category,manufacturer,model,condition_name,quantity,is_active,show_on_website,free_shipping,image_url,images,source,weight,length,width,height,created_at,updated_at)
VALUES(1,'Synthetic test part','Isolated QA item','TEST-1',100,'motorcycle','Honda','XR','Used',1,1,1,0,'/gallery/default.jpg','[]','manual',1,10,10,10,'2026-09-30','2026-09-30');
CREATE TABLE orders(id INTEGER PRIMARY KEY AUTOINCREMENT,order_number TEXT UNIQUE,customer_email TEXT,
customer_name TEXT,customer_phone TEXT,shipping_address TEXT,subtotal REAL,shipping_cost REAL,tax_amount REAL,
discount_code TEXT,discount_amount REAL,total_amount REAL,payment_method TEXT,payment_status TEXT,order_status TEXT,
paypal_order_id TEXT,paypal_transaction_id TEXT,notes TEXT,updated_at TEXT);
CREATE TABLE order_items(id INTEGER PRIMARY KEY,order_id INTEGER REFERENCES orders(id),product_id INTEGER REFERENCES products(id),
product_name TEXT,product_sku TEXT,quantity INTEGER,unit_price REAL,total_price REAL);
