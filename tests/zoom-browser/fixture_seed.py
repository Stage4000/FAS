import sqlite3
def seed_seller(site):
    # Disposable database only; real helper/model getLatest uses this exact schema.
    with sqlite3.connect(site/'database/flipandstrip.db') as db:
        db.execute("CREATE TABLE IF NOT EXISTS ebay_seller_rating_cache (\n    id INTEGER PRIMARY KEY AUTOINCREMENT,\n    store_name TEXT NOT NULL UNIQUE,\n    seller_name TEXT,\n    feedback_score INTEGER,\n    positive_feedback_percent REAL,\n    store_url TEXT,\n    last_fetched_at TEXT,\n    last_error TEXT,\n    created_at TEXT DEFAULT (datetime('now')),\n    updated_at TEXT DEFAULT (datetime('now'))\n);")
        if db.execute('SELECT count(*) FROM ebay_seller_rating_cache').fetchone()[0]:
            raise ValueError('Expected empty synthetic seller cache')
        db.execute("INSERT INTO ebay_seller_rating_cache(store_name,seller_name,feedback_score,positive_feedback_percent,store_url,last_fetched_at) VALUES(?,?,?,?,?,?)",
                   ('synthetic-heading-store','Synthetic Heading Seller',123,99.8,'https://example.invalid/synthetic-seller','2026-09-30'))

