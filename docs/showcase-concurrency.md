# Host-local concurrency admission

Dormant `ShowcaseConcurrency::execute(callable)` wraps the entire admitted operation, including upload decoding and persistence. It holds a nonblocking exclusive flock until callback return/exception; there is no TTL or expiring lease. Busy capacity throws before invoking the operation. A trusted rate gate must return literal true while the lease is held. This module is NOT a rate limiter; provision a real rate gate separately.

Preprovision 1..32 canonical absolute regular files, each containing exactly `FAS-SHOWCASE-SLOT-v1\n`, in an OS-protected private directory outside all document roots/aliases. Supply every public root explicitly. Files open r+b without creation or content changes. No automatic provisioning, unlink, chmod, or schema work. Duplicate inode identities, malformed markers, missing files, symlinks/linked ancestors, public placement and locking failures fail closed. All pool files are validated even if an earlier slot is free.

All application workers on a host must share the same immutable pool. Never rename/delete/recreate a slot while workers run, because inode changes invalidate flock coordination. Protect files and parent paths with owner-controlled permissions; race-resistant protection against hostile directory writers is not supplied. Use a local filesystem whose flock behavior is validated, not network-mounted locks. Multi-host deployment requires independently budgeted per-host pools or a separate distributed solution. Process termination releases kernel locks; spawned processes must not inherit open slot descriptors. Do not spawn background work inside the callback.

Wrap POST `controller->handle(...)` or the entire request-submit operation, not merely a boolean request gate that returns before decoding. Keep GET issuance outside upload concurrency. Map capacity/rate exceptions to generic unavailable/throttled responses without exposing internal paths. Authentication, feature enablement, transport upload limits and private storage prerequisites remain separate.

New synthetic tests cover a distinct process holding a slot, second available slot, repeated busy rejection while held, release, exception cleanup, strict rate results, malformed/missing/public/symlink paths and duplicate slots. No deployment/browser/live sessions touched.

Every worker must use the same full configured slot set (not subsets or dynamically resized pools). Callbacks must be synchronous: return only when decoding/storage is finished. Futures/generators or delegated background operations would release admission prematurely and must not be used.

Composition sketch (trusted construction and generic outer error mapping omitted):

```php
if (($server['REQUEST_METHOD'] ?? '') === 'POST') {
    $response = $admission->execute(
        static fn() => $controller->handle($server, $post, $files)
    );
} else {
    $response = $controller->handle($server, $post, $files);
}
```

The entire `handle` executes under the lease, not just its gate. The response is emitted afterward. This module supplies no cross-host/NFS guarantees and does not implement the required rate policy.

Cloud worker recovery regression additionally runs two real PHP workers through this class against the same two-slot pool. Repeated third-worker requests must return busy without running either the rate gate or operation. After terminating one holder, a replacement enters while the surviving holder continues blocking excess capacity; normal completion also releases slots. Slot marker bytes remain unchanged. This validates this cloud host's local process/kernel behavior only, not production/NFS/multi-host acceptance.
