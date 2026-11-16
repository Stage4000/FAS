# Dormant admin history privacy correction

The v17 native Chromium admin regression reached logout, then timed out waiting for a load event on back navigation. Instrumented reproduction found a restored, complete review document containing the synthetic private contact email, with no network request. Cache-Control private, no-store did not prevent this browser back/forward-cache restoration.

Successful private HTML responses now contain one fixed inline lifecycle guard. On pagehide it replaces the body with a neutral text node before freezing. On persisted pageshow it reloads the current URL, so existing current authorization runs again. The CSP permits only the exact script SHA256; no unsafe-inline, external script, client authorization, actor, token, storage or policy change is introduced. Photo bytes/length and empty 303 responses are unchanged.

The browser regression waits for history commit rather than a load event which BFCache restoration need not emit. An early pageshow observer detects contact data before the guard can reload; the original no-contact assertion, reload/photo denial and mobile assertions remain. Independent removal of only pagehide scrubbing makes this observer assertion fail, even with reload and its matching CSP hash retained. This is a privacy fix, not merely a timeout adjustment.

This mitigation requires JavaScript and supported pagehide/pageshow behavior. It is not acceptance of JavaScript-disabled browsers or all browser engines. Do not enable production review until the supported-browser policy, exact entry wrappers, actual TLS/session/login integration and lifecycle behavior are accepted on the target. The existing plain loopback browser fixture uses the exact pinned AdminAuth class with synthetic dependencies and bypasses the production bootstrap.

The complete dormant candidate is reconciled additively against FAS main 491ef60d2f6d640675fbd7d6f854bfbba57c63f5. Existing main files, pricing/checkout, admin authentication, storage/admission, workflows and enablement are preserved. No runtime bootstrap/config/secrets/provisioning or launch terms ship. Older embedded MANIFEST files are historical incremental receipts; the final candidate manifest identifies the current packet.

## Exact-source CI

The isolated synthetic runner has no browser dependency; the browser regression is named `tests/showcase-admin-browser-check.py` and runs explicitly in its own additive workflow. That workflow installs pinned Playwright 1.62.0 and Chromium, then runs the unchanged behavioral assertions with only loopback networking. Existing workflow and source guards are unchanged. The process-concurrency test uses PHP_BINARY when explicitly supplied, otherwise the PHP CLI on PATH, rather than a previous executor's private bin path.
