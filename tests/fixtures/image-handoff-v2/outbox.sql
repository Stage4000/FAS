-- INACTIVE candidate. Explicit offline-reviewed migration only, never request-time DDL.
-- Keep every row, including acknowledged/deleted products, to prevent revision reuse.
CREATE TABLE main.image_product_dirty (
 product_id INTEGER PRIMARY KEY CHECK(product_id > 0),
 revision INTEGER NOT NULL CHECK(typeof(revision)='integer' AND revision > 0),
 deleted INTEGER NOT NULL CHECK(deleted IN (0,1)),
 acknowledged_revision INTEGER NOT NULL DEFAULT 0
   CHECK(typeof(acknowledged_revision)='integer' AND acknowledged_revision >= 0 AND acknowledged_revision <= revision)
);
CREATE TRIGGER main.image_product_dirty_insert AFTER INSERT ON products BEGIN
 SELECT CASE WHEN NEW.id < 1 THEN RAISE(ABORT,'positive product identity required') END;
 INSERT INTO image_product_dirty(product_id,revision,deleted) VALUES(NEW.id,1,0)
 ON CONFLICT(product_id) DO UPDATE SET revision=revision+1,deleted=0;
END;
CREATE TRIGGER main.image_product_dirty_update AFTER UPDATE OF image_url,images ON products
 WHEN OLD.image_url IS NOT NEW.image_url OR OLD.images IS NOT NEW.images BEGIN
 INSERT INTO image_product_dirty(product_id,revision,deleted) VALUES(NEW.id,1,0)
 ON CONFLICT(product_id) DO UPDATE SET revision=revision+1,deleted=0;
END;
CREATE TRIGGER main.image_product_dirty_delete AFTER DELETE ON products BEGIN
 INSERT INTO image_product_dirty(product_id,revision,deleted) VALUES(OLD.id,1,1)
 ON CONFLICT(product_id) DO UPDATE SET revision=revision+1,deleted=1;
END;

-- INACTIVE alternative: rejects REPLACE/upsert identity conflicts, never changes PRAGMAs.
-- Requires verified unique constraints ONLY id and ebay_item_id and no unknown triggers.
CREATE TRIGGER main.image_product_dirty_insert_guard BEFORE INSERT ON products BEGIN
 SELECT CASE WHEN EXISTS(SELECT 1 FROM products WHERE id=NEW.id)
 OR (NEW.ebay_item_id IS NOT NULL AND EXISTS(SELECT 1 FROM products WHERE ebay_item_id=NEW.ebay_item_id))
 THEN RAISE(ABORT,'conflicting product insert requires explicit update') END;
END;
CREATE TRIGGER main.image_product_dirty_update_guard BEFORE UPDATE ON products BEGIN
 SELECT CASE WHEN OLD.id IS NOT NEW.id THEN RAISE(ABORT,'product identity is immutable') END;
 SELECT CASE WHEN NEW.ebay_item_id IS NOT NULL AND EXISTS(SELECT 1 FROM products WHERE ebay_item_id=NEW.ebay_item_id AND id<>OLD.id)
 THEN RAISE(ABORT,'conflicting product identity update') END;
END;
