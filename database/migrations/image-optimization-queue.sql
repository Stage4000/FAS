-- Explicit maintenance migration; never run on ordinary requests.
CREATE TABLE image_optimization_jobs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 event_key TEXT NOT NULL,
 work_key TEXT NOT NULL,
 product_id INTEGER NOT NULL,
 intent_json TEXT NOT NULL,
 state TEXT NOT NULL CHECK(state IN ('pending','leased','retry','done','superseded','quarantined')),
 attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 5),
 available_at INTEGER NOT NULL,
 lease_token TEXT,
 lease_until INTEGER,
 failure TEXT,
 created_at INTEGER NOT NULL,
 UNIQUE(event_key,work_key)
);
CREATE INDEX image_jobs_due ON image_optimization_jobs(state,available_at,lease_until,id);
