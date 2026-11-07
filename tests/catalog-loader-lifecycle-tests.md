# Catalog AJAX loader lifecycle regression

Run from the repository root with Node.js, without installing packages:

```sh
node tests/catalog-loader-lifecycle-test.cjs
```

An optional first argument selects a different `products.php` fixture. By default the test reads the current checkout's page. It extracts only the loader declaration/function between unique, ordered markers; full PHP and unrelated page scripts never execute. The test prints a JSON summary and writes no files. Failures produce a nonzero exit.

An explicit `beforeExit` guard fails unfinished async work, including a promise that never settles but leaves no active Node handles. A 10-second unreferenced watchdog also terminates an unfinished suite if other handles keep the event loop alive and timers can run. The summary includes source/slice/harness hashes, bounded failure samples, and undetected mutation names. The VM timeout covers declaration evaluation only, not subsequent loader calls. JavaScript timers cannot preempt synchronous CPU loops or microtask starvation; those require CI's existing outer process timeout. This is not hard real-time or OS containment.

## CI integration

The existing `tests/run-synthetic-suite.py` automatically discovers top-level `tests/*-test.cjs` and invokes Node with a 180-second per-test limit. The existing Synthetic product regressions workflow already includes `tests/**` and `products.php` in its main-branch push paths. No runner, workflow, permission, account or deployment change is needed. The workflow has path filters: this is recurring coverage on matching changes, not a promise that unrelated docs-only commits trigger it.

## Tested boundary

The 31 finite scenarios exercise actual source with synthetic deferred fetch/JSON promises, inert abort controllers, and recording DOM/helper stubs. They cover superseded completions/errors, current failure fallback, optional response fields, animation cleanup, and all six three-way completion orders at both response and JSON-body stages. Requests are deliberately allowed to finish after fixture abort, so success/error ownership checks are tested independently of real transport cancellation.

Five in-memory mutations demonstrate sensitivity. On the reviewed baseline they fail 14, 4, 13, 7 and 1 scenarios respectively: success ownership, stale-error ownership, previous-request abort, navigation fallback and animation-class cleanup. Those numbers are test outcomes, not customer incidents. Mutations do not alter source files. Their target strings must remain unique; intentional source refactoring may require updating these controls along with the test.

This repository-native form adapts the accepted November 7 source package for current-checkout source resolution, extraction guards, stdout diagnostics and explicit async-suite completion. It retains the same 31 scenario bodies and five mutation suites. Independent native-integration review found that an unresolved await could otherwise end silently with exit 0; the completion guard corrects that harness defect, not a production loader defect. Historical full-page/slice hashes are not execution preconditions, so unrelated page edits do not unnecessarily break this recurring regression. The source/harness hashes are emitted for evidence. The separately authored seven reviewer controls and four pinned-archive extraction checks remain in the historical verification package; they are not additional claimed native CI cases.

## Limits

No defect was found in the reviewed loader. The VM is not a security sandbox: source is trusted reviewed repository code and dependencies are known stubs. This is not browser/HTML, history/popstate, focus/accessibility, real abort/network, metadata/handler implementation, server, database, provider or deployment acceptance. A current helper failure may occur after partial DOM updates and trigger normal navigation; no atomic rollback is asserted. Current AbortError is intentionally suppressed by the source.

The full hosted synthetic aggregate must pass on the exact published commit before CI integration is called verified. Local targeted success is not a full aggregate result.
