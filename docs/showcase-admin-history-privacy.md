# Dormant admin history privacy correction

The v17 native Chromium admin regression reached logout, then timed out waiting for a load event on back navigation. Instrumented reproduction found a restored, complete review document containing the synthetic private contact email, with no network request. Cache-Control private, no-store did not prevent this browser back/forward-cache restoration.

Successful private HTML responses now contain one fixed inline lifecycle guard. On pagehide it replaces the body with a neutral text node before freezing. On persisted pageshow it reloads the current URL, so existing current authorization runs again. The CSP permits only the exact script SHA256; no unsafe-inline, external script, client authorization, actor, token, storage or policy change is introduced. Photo bytes/length and empty 303 responses are unchanged.

The browser regression waits for history commit rather than a load event which BFCache restoration need not emit. An early pageshow observer detects contact data before the guard can reload; the original no-contact assertion, reload/photo denial and mobile assertions remain. Independent removal of only pagehide scrubbing makes this observer assertion fail, even with reload and its matching CSP hash retained. This is a privacy fix, not merely a timeout adjustment.

This mitigation requires JavaScript and supported pagehide/pageshow behavior. It is not acceptance of JavaScript-disabled browsers or all browser engines. Do not enable production review until the supported-browser policy, exact entry wrappers, actual TLS/session/login integration and lifecycle behavior are accepted on the target. The existing plain loopback browser fixture uses the exact pinned AdminAuth class with synthetic dependencies and bypasses the production bootstrap.

The complete dormant candidate is reconciled additively against FAS main 491ef60d2f6d640675fbd7d6f854bfbba57c63f5. Existing main files, pricing/checkout, admin authentication, storage/admission, workflows and enablement are preserved. No runtime bootstrap/config/secrets/provisioning or launch terms ship. Older embedded MANIFEST files are historical incremental receipts; the final candidate manifest identifies the current packet.

## Exact-source CI

The isolated synthetic runner has no browser dependency; the browser regression is named `tests/showcase-admin-browser-check.py` and runs explicitly in its own additive workflow. That workflow installs pinned Playwright 1.62.0 and Chromium, then runs the unchanged behavioral assertions with only loopback networking. Existing workflow and source guards are unchanged. The process-concurrency test uses PHP_BINARY when explicitly supplied, otherwise the PHP CLI on PATH, rather than a previous executor's private bin path.

## Hosted compatibility corrections

The initial draft head passed the dedicated24-assertion browser workflow but failed206/208 aggregate suites and both existing browser runners. Their artifacts confirmed both browser failures were the immutable root-tree gate, before UI execution. The gate now admits a finite91-path exact Git-blob map, additive statuses and source-manifest pins; historical inventories and tree/dirty/index/untracked/sparse checks remain. Manual browser sparse checkout explicitly materializes every new pinned path; no path-wide source exemption is introduced.

Ubuntu PHP8.3 and Debian PHP8.2 do not reliably expose SQL-started SQLite transactions through PDO bookkeeping. Repository connection validation now attempts an empty BEGIN DEFERRED only after PDO reports no transaction. Failed BEGIN rejects without rollback; only a successful owned probe is rolled back. Real writer transactions still use BEGIN IMMEDIATE and existing ownership flags. Tests inspect SQLite engine nesting, preserve caller transactions and competing writers, and reject failed probe/rollback paths.

The initial7MiB POST-overflow fixture ended with RemoteDisconnected on hosted CI, but that transport failure did not reproduce using the same Ubuntu PHP8.3 packages and production INI locally. The bounded replacement uses a separate native PHP1K POST ceiling with a valid PNG multipart larger than1K, requires400 plus proof of the effective ceiling, actual content length and empty native POST/FILES arrays, and verifies unchanged storage. The exact6MiB accepted and +1 file-upload rejection tests remain. Disconnects are never counted as acceptance. This verifies native overflow rejection; actual production7M transport behavior remains a target-runtime acceptance gate.

### Confirmed overflow harness cause

The hosted startup disconnect was independently reproduced locally by enabling Ubuntu Xdebug develop mode: native POST-size startup warning caused process exit-11 before the router could respond. The same package/runtime passes with Xdebug absent; PCOV alone also passes. Synthetic server children now explicitly disable Xdebug instrumentation through both startup setting and environment precedence, while retaining PHP warnings, post_max_size and application checks. No production setting is changed. The original7MiB+1 request is restored as an additional required400 test, alongside the bounded1K case and unchanged6MiB/+1 cases. No disconnect, crash, retry or skip counts as acceptance.

The managed-browser history test also waits for complete fresh guarded documents between back/forward operations. It does not advance while the persisted scrubbed body is awaiting its reauthorization reload; all existing privacy/observer/denial/mobile assertions remain.
