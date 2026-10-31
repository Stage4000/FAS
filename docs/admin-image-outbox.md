# Default-off admin image enqueue integration (local candidate)

`admin/products.php` now constructs its Product model with the existing atomic catalog image outbox only when the server process environment `FAS_ADMIN_IMAGE_OUTBOX` is exactly `1`. Unset, empty, or `0` disables it. Other values fail closed with generic HTTP 503. Request parameters/cookies cannot enable it. There is no UI switch.

Enabled mode requires the existing CLI-installed, strictly verified v2 queue and activation schemas on the same PDO connection. The HTTP path never migrates or repairs schemas. Schema/configuration failure occurs before Product initialization or image upload receive. Returning the flag to `0` restores disabled operation without altering jobs/schema. No production configuration has been changed.

This is **enqueue only**: admin creates, image edits/uploads, gallery removal, and visibility transitions use existing Product/outbox transactions. Text/price-only changes and identical retries do not enqueue. Failed CAS or enqueue rolls back the catalog/outbox transaction. Generic recoverable 503 handling covers create, bulk visibility and visibility AJAX errors as well as the existing update error path. No SQL/exception text is returned by the new catches.

## Deliberate boundaries

- Only this admin entrypoint is wired. eBay sync, other Product construction sites, workers, fetching, scheduler and backfill remain unwired.
- Jobs are pending intent, not successful optimization. Local-upload jobs are not accepted by the current eBay-only preparation stage; no consumer is enabled here.
- Original upload bytes remain unchanged. An original already received before a later DB failure can remain unreferenced, as before this change. This is not filesystem/catalog atomicity, and does not delete originals automatically.
- No receipt renewal, provenance grant, public derivative generation or activation occurs.
- Existing real codec, target-host durability/load and deployment review gates still apply. This change does not authorize turning on the environment flag in production.

## Local verification

`tests/catalog-image-runtime-test.php` exercises strict configuration and absent/v1/v2 schema behavior. The separate retained HTTP harness uses the actual admin file, Product/outbox, ownership and immutable-upload helpers with synthetic authentication, shipping/warehouse fixtures and a disposable SQLite database. It tests actual multipart→original file→catalog→pending job and failure paths. It does not establish production authentication, browser JavaScript or real-codec acceptance.
