-- Source-only explicit companion migration; no automatic application.
CREATE TABLE image_optimization_activations (
 activation_id TEXT PRIMARY KEY NOT NULL,
 job_id INTEGER NOT NULL,
 lease_token TEXT NOT NULL,
 catalog_binding TEXT NOT NULL,
 target_name TEXT NOT NULL,
 before_bytes BLOB,
 before_sha256 TEXT,
 after_bytes BLOB NOT NULL,
 after_sha256 TEXT NOT NULL,
 variants_json TEXT NOT NULL,
 state TEXT NOT NULL CHECK(state IN ('prepared','completed','aborted','conflict')),
 created_at INTEGER NOT NULL,
 resolved_at INTEGER,
 CHECK(length(after_bytes) BETWEEN 1 AND 16384),
 CHECK(before_bytes IS NULL OR length(before_bytes) BETWEEN 1 AND 16384)
);
CREATE UNIQUE INDEX image_activation_open_job ON image_optimization_activations(job_id) WHERE state IN ('prepared','conflict');
CREATE UNIQUE INDEX image_activation_open_target ON image_optimization_activations(target_name) WHERE state IN ('prepared','conflict');
