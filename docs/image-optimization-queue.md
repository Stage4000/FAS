# Image outbox foundation — not active automation

Baseline FAS 047d6c656570ac1818ba2dc71c87b026b9595fcc `src/config/Database.php` uses PDO SQLite exclusively. This implementation explicitly rejects other drivers and non-exception PDO mode. No production configuration or database was read. The SQL is a maintenance artifact, not request-time schema creation; it has no `IF NOT EXISTS` that might silently accept an incompatible existing schema. Do not apply it until backup, schema-version guard and coordinated Product integration are reviewed.

`enqueueInTransaction` requires the same PDO transaction as the successful catalog write and rereads the resulting row from that connection. This is not an after-commit best-effort hook. Commit makes catalog plus jobs visible together; rollback removes both. A server-generated 128-bit event key identifies a single write/import/explicit refresh event. Reuse that event key on retries; a new refresh gets a new key even when URLs remain unchanged. Event identity must not come from a public client. Complete ordered source set/activity/visibility binding comes from ImageOptimizationPlan.

Claims are bounded to one item and five attempts, with 1–300 second leases and random fencing tokens. Expired owners cannot update outcomes. Expired fifth attempts and malformed stored JSON quarantine. Retry decisions use the existing planner. A NULL claim can mean a quarantined malformed item, not necessarily an empty queue; future batch logic must remain bounded. Two-connection SQLite tests cover serialized consumers/reclaim, not a production load test.

`current` rereads authoritative catalog and stored intent; it is only a read-only check, NOT an atomic permission to activate a receipt. `finish(done)` records a worker outcome, NOT evidence that any derivative was published. Caller-provided claim attempts/intent never control retry policy or current-catalog checks; stored values do.

## Required next integration, deliberately absent

- Coordinate create/update/updateImages/delete and eBay sync with ownership protection on the same PDO. Existing surrounding transactions must be preserved; no nested commit or silent fallback. Introduce a reviewed installed-schema guard and activation setting before changing catalog save behavior.
- A staged generator must emit verified artifacts without publishing its receipt. Existing ImportedImage::import publishes internally, so blindly calling it would race a changed catalog or expired lease. It is not wired here.
- Final activation needs a reviewed catalog/lease fence, receipt-last publication, uncertain-result reconciliation, bounded resources and cleanup/rollback that cannot remove another worker's output. Do not hold a database write lock during downloads/encoding.
- No fetching, provenance creation/renewal, CLI worker, scheduler, admin activation, bulk backfill, or public-renderer trust is implemented in this slice. Preserve original fallback until these acceptance gates pass.

Local evidence: real disposable SQLite file with independent PDO connections; no live data, external network or host service changes.

## Private staging adapter (source only)

`ImageOptimizationStage` now prepares a supplied, already-verified eBay manifest through unchanged `ImportedImage::import`, directed only at a new random 0700 directory beneath an owner-private staging root outside the configured application root. It obtains the authoritative stored intent from the queue, not the caller's claim payload, checks catalog/lease before and after, verifies staged receipt identity, unchanged verification timestamps, image dimensions/hash/bytes and derivative inventory. No downloading or authorization/provenance creation occurs. Failed preparations remove only their newly created private workspace; successful workspace retention/expiry must be managed by the future worker.

The staging root must be operator-configured and must not be served by any virtual host; filesystem separation from one application root cannot prove all web-server mappings. Same-UID hostile filesystem writers and path-replacement races are not addressed by PHP path checks. No public activation, queue completion or automatic execution is wired. Real GD/EXIF execution is unrun here; private-stage tests use an importer contract double with a real tiny WebP fixture, and prove verification/rejection behavior, not encoder acceptance.
