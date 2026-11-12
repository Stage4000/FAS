BEGIN TRANSACTION;
CREATE TABLE image_handoff_cursor (singleton INTEGER PRIMARY KEY CHECK(singleton=1),last_id INTEGER NOT NULL CHECK(typeof(last_id)='integer' AND last_id>=0),upper_id INTEGER NOT NULL CHECK(typeof(upper_id)='integer' AND upper_id>=last_id), admission_last_id INTEGER NOT NULL DEFAULT 0 CHECK(admission_last_id>=0));
INSERT INTO "image_handoff_cursor" VALUES(1,0,0,0);
CREATE TABLE image_handoff_quarantine (product_id INTEGER NOT NULL CHECK(product_id>0),revision INTEGER NOT NULL CHECK(typeof(revision)='integer' AND revision>0),reason TEXT NOT NULL CHECK(reason IN ('field_bounds','invalid_utf8','admission_unsupported')),source_scope TEXT NOT NULL DEFAULT '*',PRIMARY KEY(product_id,revision,source_scope));
CREATE TABLE image_handoff_version(singleton INTEGER PRIMARY KEY CHECK(singleton=1),version INTEGER NOT NULL CHECK(version=6),contract TEXT NOT NULL CHECK(contract='renewal-ledger-v6'));
INSERT INTO "image_handoff_version" VALUES(1,6,'renewal-ledger-v6');
CREATE TABLE image_product_dirty (
 product_id INTEGER PRIMARY KEY CHECK(product_id > 0),
 revision INTEGER NOT NULL CHECK(typeof(revision)='integer' AND revision > 0),
 deleted INTEGER NOT NULL CHECK(deleted IN (0,1)),
 acknowledged_revision INTEGER NOT NULL DEFAULT 0
   CHECK(typeof(acknowledged_revision)='integer' AND acknowledged_revision >= 0 AND acknowledged_revision <= revision)
);
INSERT INTO "image_product_dirty" VALUES(1,1,0,1);
CREATE TABLE image_snapshot_handoff (
 product_id INTEGER NOT NULL CHECK(product_id > 0),
 revision INTEGER NOT NULL CHECK(typeof(revision)='integer' AND revision > 0),
 snapshot_json TEXT NOT NULL,
 snapshot_sha256 TEXT NOT NULL CHECK(length(snapshot_sha256)=64),
 PRIMARY KEY(product_id,revision)
);
INSERT INTO "image_snapshot_handoff" VALUES(1,1,'{"deleted":false,"image_url":"https://i.ebayimg.com/é.png","images":null,"is_active":1,"product_id":1,"revision":1,"show_on_website":1,"version":2}','655db4253e01d6d20060505a2deaa3fd69ac3790ab189fae4e46a59720ed46df');
CREATE TABLE ledger_acquisitions(attempt_id TEXT PRIMARY KEY REFERENCES ledger_attempts(attempt_id),proof_json TEXT NOT NULL,proof_sha256 TEXT NOT NULL);
CREATE TABLE ledger_attempts (
 attempt_id TEXT PRIMARY KEY NOT NULL CHECK(typeof(attempt_id)='text' AND instr(attempt_id,char(0))=0 AND length(CAST(attempt_id AS BLOB))=32 AND attempt_id NOT GLOB '*[^0-9a-f]*'),
 product_id INTEGER NOT NULL CHECK(typeof(product_id)='integer' AND product_id>0),revision INTEGER NOT NULL CHECK(typeof(revision)='integer' AND revision>0),snapshot_sha256 TEXT NOT NULL CHECK(typeof(snapshot_sha256)='text' AND instr(snapshot_sha256,char(0))=0 AND length(CAST(snapshot_sha256 AS BLOB))=64 AND snapshot_sha256 NOT GLOB '*[^0-9a-f]*'),
 owner_token TEXT NOT NULL CHECK(typeof(owner_token)='text' AND instr(owner_token,char(0))=0 AND length(CAST(owner_token AS BLOB))=32 AND owner_token NOT GLOB '*[^0-9a-f]*'),
 fence INTEGER NOT NULL CHECK(typeof(fence)='integer' AND fence>0),
 phase TEXT NOT NULL CHECK(typeof(phase)='text' AND phase IN ('registered','release_intent','execution_unknown','terminal_recorded','receipt_conflict')),
 operation_key TEXT NOT NULL UNIQUE CHECK(length(operation_key)=64),
 intent TEXT NOT NULL CHECK(intent IN ('initial','renew')),
 source_selector TEXT NOT NULL,
 source_url TEXT NOT NULL CHECK(length(CAST(source_url AS BLOB)) BETWEEN 1 AND 2048),
 receipt_preimage_sha256 TEXT CHECK(receipt_preimage_sha256 IS NULL OR length(receipt_preimage_sha256)=64),
 effect_state TEXT NOT NULL DEFAULT 'unresolved' CHECK(effect_state IN ('unresolved','settled')),
 CHECK((intent='initial' AND receipt_preimage_sha256 IS NULL) OR (intent='renew' AND receipt_preimage_sha256 IS NOT NULL)),
 FOREIGN KEY(product_id,revision,snapshot_sha256) REFERENCES ledger_snapshots(product_id,revision,snapshot_sha256)
) WITHOUT ROWID;
INSERT INTO "ledger_attempts" VALUES('40625371a6772444f50de0d4f56ff678',1,1,'655db4253e01d6d20060505a2deaa3fd69ac3790ab189fae4e46a59720ed46df','470282fdb9fe1a622cc1a35529b4f886',1,'registered','98d167b62b2feb52d5686f2cc9789e787ef3dcc2f3d156278dec82ab11945212','initial','{"kind":"primary"}','https://i.ebayimg.com/é.png',NULL,'unresolved');
CREATE TABLE ledger_capacity(attempt_id TEXT PRIMARY KEY REFERENCES ledger_attempts(attempt_id),bytes INTEGER NOT NULL CHECK(bytes>=67108864),state TEXT NOT NULL CHECK(state IN ('reserved','running','unknown','released')),observation_json TEXT NOT NULL);
INSERT INTO "ledger_capacity" VALUES('40625371a6772444f50de0d4f56ff678',67108864,'reserved','{"observed_at":1000,"private_free":2147483648,"public_free":2147483648,"quota_limit":5368709120,"quota_used":1073741824,"tmp_free":2147483648}');
CREATE TABLE ledger_receipt_conflicts (
 attempt_id TEXT PRIMARY KEY NOT NULL CHECK(typeof(attempt_id)='text' AND instr(attempt_id,char(0))=0 AND length(CAST(attempt_id AS BLOB))=32 AND attempt_id NOT GLOB '*[^0-9a-f]*') REFERENCES ledger_receipts(attempt_id),
 conflicting_sha256 TEXT NOT NULL CHECK(typeof(conflicting_sha256)='text' AND instr(conflicting_sha256,char(0))=0 AND length(CAST(conflicting_sha256 AS BLOB))=64 AND conflicting_sha256 NOT GLOB '*[^0-9a-f]*')
) WITHOUT ROWID;
CREATE TABLE ledger_receipts (
 attempt_id TEXT PRIMARY KEY NOT NULL CHECK(typeof(attempt_id)='text' AND instr(attempt_id,char(0))=0 AND length(CAST(attempt_id AS BLOB))=32 AND attempt_id NOT GLOB '*[^0-9a-f]*') REFERENCES ledger_attempts(attempt_id),
 receipt_sha256 TEXT NOT NULL CHECK(typeof(receipt_sha256)='text' AND instr(receipt_sha256,char(0))=0 AND length(CAST(receipt_sha256 AS BLOB))=64 AND receipt_sha256 NOT GLOB '*[^0-9a-f]*'),
 canonical_packet BLOB NOT NULL CHECK(typeof(canonical_packet)='blob' AND length(canonical_packet) BETWEEN 1 AND 4096)
) WITHOUT ROWID;
CREATE TABLE ledger_settlements(attempt_id TEXT PRIMARY KEY REFERENCES ledger_attempts(attempt_id),evidence_json TEXT NOT NULL,evidence_sha256 TEXT NOT NULL,quota_json TEXT NOT NULL);
CREATE TABLE ledger_snapshots (
 product_id INTEGER NOT NULL CHECK(typeof(product_id)='integer' AND product_id>0),
 revision INTEGER NOT NULL CHECK(typeof(revision)='integer' AND revision>0),
 snapshot_sha256 TEXT NOT NULL CHECK(typeof(snapshot_sha256)='text' AND instr(snapshot_sha256,char(0))=0 AND length(CAST(snapshot_sha256 AS BLOB))=64 AND snapshot_sha256 NOT GLOB '*[^0-9a-f]*'),
 UNIQUE(product_id,revision),
 PRIMARY KEY(product_id,revision,snapshot_sha256)
) WITHOUT ROWID;
INSERT INTO "ledger_snapshots" VALUES(1,1,'655db4253e01d6d20060505a2deaa3fd69ac3790ab189fae4e46a59720ed46df');
CREATE TABLE products (
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
    free_shipping INTEGER NOT NULL DEFAULT 0, -- 1 = always free shipping
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
INSERT INTO "products" VALUES(1,NULL,NULL,'synthetic',NULL,1.0,NULL,NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'https://i.ebayimg.com/é.png',NULL,NULL,'manual',1,0,NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-09 01:25:12','2026-11-09 01:25:12');
CREATE INDEX idx_products_category ON products(category);
CREATE INDEX idx_products_ebay_item_id ON products(ebay_item_id);
CREATE INDEX idx_products_ebay_store_cat1 ON products(ebay_store_cat1_id);
CREATE INDEX idx_products_ebay_store_cat2 ON products(ebay_store_cat2_id);
CREATE INDEX idx_products_ebay_store_cat3 ON products(ebay_store_cat3_id);
CREATE INDEX idx_products_is_active ON products(is_active);
CREATE INDEX idx_products_manufacturer ON products(manufacturer);
CREATE INDEX idx_products_show_on_website ON products(show_on_website);
CREATE INDEX idx_products_sku ON products(sku);
CREATE INDEX idx_products_source ON products(source);
CREATE TRIGGER image_product_dirty_insert AFTER INSERT ON products BEGIN
 SELECT CASE WHEN NEW.id < 1 THEN RAISE(ABORT,'positive product identity required') END;
 INSERT INTO image_product_dirty(product_id,revision,deleted) VALUES(NEW.id,1,0)
 ON CONFLICT(product_id) DO UPDATE SET revision=revision+1,deleted=0;
END;
CREATE TRIGGER image_product_dirty_update AFTER UPDATE OF image_url,images,is_active,show_on_website ON products
 WHEN OLD.image_url IS NOT NEW.image_url OR OLD.images IS NOT NEW.images OR OLD.is_active IS NOT NEW.is_active OR OLD.show_on_website IS NOT NEW.show_on_website BEGIN
 INSERT INTO image_product_dirty(product_id,revision,deleted) VALUES(NEW.id,1,0)
 ON CONFLICT(product_id) DO UPDATE SET revision=revision+1,deleted=0;
END;
CREATE TRIGGER image_product_dirty_delete AFTER DELETE ON products BEGIN
 INSERT INTO image_product_dirty(product_id,revision,deleted) VALUES(OLD.id,1,1)
 ON CONFLICT(product_id) DO UPDATE SET revision=revision+1,deleted=1;
END;
CREATE TRIGGER image_product_dirty_insert_guard BEFORE INSERT ON products BEGIN
 SELECT CASE WHEN EXISTS(SELECT 1 FROM products WHERE id=NEW.id)
 OR (NEW.ebay_item_id IS NOT NULL AND EXISTS(SELECT 1 FROM products WHERE ebay_item_id=NEW.ebay_item_id))
 THEN RAISE(ABORT,'conflicting product insert requires explicit update') END;
END;
CREATE TRIGGER image_product_dirty_update_guard BEFORE UPDATE ON products BEGIN
 SELECT CASE WHEN OLD.id IS NOT NEW.id THEN RAISE(ABORT,'product identity is immutable') END;
 SELECT CASE WHEN NEW.ebay_item_id IS NOT NULL AND EXISTS(SELECT 1 FROM products WHERE ebay_item_id=NEW.ebay_item_id AND id<>OLD.id)
 THEN RAISE(ABORT,'conflicting product identity update') END;
END;
CREATE TRIGGER ledger_attempt_initial BEFORE INSERT ON ledger_attempts BEGIN
 SELECT CASE WHEN EXISTS(SELECT 1 FROM ledger_attempts WHERE attempt_id=NEW.attempt_id OR operation_key=NEW.operation_key) THEN RAISE(ABORT,'attempt identity already registered') END;
 SELECT CASE WHEN NEW.effect_state<>'unresolved' THEN RAISE(ABORT,'initial effect unresolved') END;
 SELECT CASE WHEN NEW.phase<>'registered' THEN RAISE(ABORT,'initial phase must be registered') END;
END;
CREATE TRIGGER ledger_attempt_immutable BEFORE UPDATE ON ledger_attempts BEGIN
 SELECT CASE WHEN NEW.attempt_id IS NOT OLD.attempt_id OR NEW.product_id IS NOT OLD.product_id OR NEW.revision IS NOT OLD.revision OR NEW.snapshot_sha256 IS NOT OLD.snapshot_sha256 OR NEW.owner_token IS NOT OLD.owner_token OR NEW.fence IS NOT OLD.fence OR NEW.operation_key IS NOT OLD.operation_key OR NEW.intent IS NOT OLD.intent OR NEW.source_url IS NOT OLD.source_url OR NEW.source_selector IS NOT OLD.source_selector OR NEW.receipt_preimage_sha256 IS NOT OLD.receipt_preimage_sha256 THEN RAISE(ABORT,'attempt identity is immutable') END;
 SELECT CASE WHEN NEW.effect_state IS NOT OLD.effect_state AND NOT (OLD.effect_state='unresolved' AND NEW.effect_state='settled' AND OLD.phase='terminal_recorded' AND NEW.phase='terminal_recorded') THEN RAISE(ABORT,'settlement transition denied') END;
 SELECT CASE WHEN NOT (
  NEW.phase=OLD.phase OR
  (OLD.phase='registered' AND NEW.phase IN ('release_intent','execution_unknown')) OR
  (OLD.phase='release_intent' AND NEW.phase IN ('execution_unknown','terminal_recorded')) OR
  (OLD.phase='execution_unknown' AND NEW.phase='terminal_recorded') OR
  (OLD.phase='terminal_recorded' AND NEW.phase='receipt_conflict')
 ) THEN RAISE(ABORT,'invalid attempt transition') END;
 SELECT CASE WHEN NEW.phase='terminal_recorded' AND NOT EXISTS(SELECT 1 FROM ledger_receipts WHERE attempt_id=OLD.attempt_id) THEN RAISE(ABORT,'durable receipt required') END;
 SELECT CASE WHEN NEW.phase='receipt_conflict' AND NOT EXISTS(SELECT 1 FROM ledger_receipt_conflicts WHERE attempt_id=OLD.attempt_id) THEN RAISE(ABORT,'conflict evidence required') END;
END;
CREATE TRIGGER ledger_attempt_no_delete BEFORE DELETE ON ledger_attempts BEGIN SELECT RAISE(ABORT,'attempt history retained'); END;
CREATE TRIGGER ledger_snapshot_no_replace BEFORE INSERT ON ledger_snapshots BEGIN SELECT CASE WHEN EXISTS(SELECT 1 FROM ledger_snapshots WHERE product_id=NEW.product_id AND revision=NEW.revision) THEN RAISE(ABORT,'snapshot identity already registered') END; END;
CREATE TRIGGER ledger_snapshot_no_update BEFORE UPDATE ON ledger_snapshots BEGIN SELECT RAISE(ABORT,'snapshot identity retained'); END;
CREATE TRIGGER ledger_snapshot_no_delete BEFORE DELETE ON ledger_snapshots BEGIN SELECT RAISE(ABORT,'snapshot identity retained'); END;
CREATE TRIGGER ledger_receipt_admission BEFORE INSERT ON ledger_receipts BEGIN
 SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM ledger_attempts WHERE attempt_id=NEW.attempt_id AND phase IN ('release_intent','execution_unknown')) THEN RAISE(ABORT,'attempt not awaiting terminal') END;
END;
CREATE TRIGGER ledger_receipt_recorded AFTER INSERT ON ledger_receipts BEGIN
 UPDATE ledger_attempts SET phase='terminal_recorded' WHERE attempt_id=NEW.attempt_id;
END;
CREATE TRIGGER ledger_receipt_no_update BEFORE UPDATE ON ledger_receipts BEGIN SELECT RAISE(ABORT,'receipt immutable'); END;
CREATE TRIGGER ledger_receipt_no_delete BEFORE DELETE ON ledger_receipts BEGIN SELECT RAISE(ABORT,'receipt retained'); END;
CREATE TRIGGER ledger_conflict_admission BEFORE INSERT ON ledger_receipt_conflicts BEGIN
 SELECT CASE WHEN EXISTS(SELECT 1 FROM ledger_receipt_conflicts WHERE attempt_id=NEW.attempt_id) THEN RAISE(ABORT,'conflict already retained') END;
 SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM ledger_receipts WHERE attempt_id=NEW.attempt_id AND receipt_sha256<>NEW.conflicting_sha256) THEN RAISE(ABORT,'distinct existing receipt required') END;
END;
CREATE TRIGGER ledger_conflict_recorded AFTER INSERT ON ledger_receipt_conflicts BEGIN
 UPDATE ledger_attempts SET phase='receipt_conflict' WHERE attempt_id=NEW.attempt_id;
END;
CREATE TRIGGER ledger_conflict_no_update BEFORE UPDATE ON ledger_receipt_conflicts BEGIN SELECT RAISE(ABORT,'conflict retained'); END;
CREATE TRIGGER ledger_conflict_no_delete BEFORE DELETE ON ledger_receipt_conflicts BEGIN SELECT RAISE(ABORT,'conflict retained'); END;
CREATE TRIGGER ledger_capacity_identity BEFORE UPDATE ON ledger_capacity BEGIN SELECT CASE WHEN NEW.attempt_id IS NOT OLD.attempt_id OR NEW.bytes IS NOT OLD.bytes OR NEW.observation_json IS NOT OLD.observation_json OR OLD.state='released' THEN RAISE(ABORT,'capacity identity retained') END; END;
CREATE TRIGGER ledger_capacity_release BEFORE UPDATE OF state ON ledger_capacity WHEN NEW.state='released' BEGIN SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM ledger_settlements WHERE attempt_id=OLD.attempt_id) THEN RAISE(ABORT,'settlement evidence required') END; END;
CREATE TRIGGER ledger_capacity_no_delete BEFORE DELETE ON ledger_capacity BEGIN SELECT RAISE(ABORT,'capacity history retained'); END;
CREATE TRIGGER ledger_acquisitions_no_update BEFORE UPDATE ON ledger_acquisitions BEGIN SELECT RAISE(ABORT,'proof retained'); END;
CREATE TRIGGER ledger_acquisitions_no_delete BEFORE DELETE ON ledger_acquisitions BEGIN SELECT RAISE(ABORT,'proof retained'); END;
CREATE TRIGGER ledger_settlements_no_update BEFORE UPDATE ON ledger_settlements BEGIN SELECT RAISE(ABORT,'settlement retained'); END;
CREATE TRIGGER ledger_settlements_no_delete BEFORE DELETE ON ledger_settlements BEGIN SELECT RAISE(ABORT,'settlement retained'); END;
DELETE FROM "sqlite_sequence";
INSERT INTO "sqlite_sequence" VALUES('products',1);
COMMIT;
