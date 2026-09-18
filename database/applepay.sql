-- Additive migration only. Do not run init-sqlite.php against production.
CREATE TABLE IF NOT EXISTS applepay_attempts (
    id TEXT PRIMARY KEY,
    owner_hash TEXT NOT NULL,
    request_hash TEXT NOT NULL,
    environment TEXT NOT NULL CHECK(environment IN ('live','sandbox')),
    order_id INTEGER NOT NULL UNIQUE REFERENCES orders(id),
    payload TEXT NOT NULL,
    paypal_order_id TEXT UNIQUE,
    merchant_id TEXT,
    capture_id TEXT UNIQUE,
    capture_requested_at INTEGER,
    status TEXT NOT NULL DEFAULT 'creating',
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_applepay_recovery ON applepay_attempts(status, updated_at);
-- Wallet orders cannot be finalized through the legacy, browser-trusting endpoint.
-- The PHP guard also returns a useful API error before that endpoint writes anything.
