# Scoped zoom navigation acceptance

This is a new, separate gate for the exact CSS-only candidate over main
`a1e761f1c0a9c475d258d4ea7f4d5da54daaceb7`, tree
`373e0021212cd79739e28eef0c03ed342c8726e8`. It does not update, normalize,
disable or claim compatibility with historical keyboard/admin acceptance gates.
Their source-identity guards may reject this new candidate. Those old browser
suites are not integrated into this scoped job and are not claimed as passing.

Run only through `.github/workflows/zoom-browser.yml` on the exact reviewed
candidate. There is no local namespace/browser fallback. Pure guard fixtures
are safe offline: `PYTHONDONTWRITEBYTECODE=1 python3 tests/zoom-browser/guard-test.py`.

The hosted job verifies the baseline ancestor/tree, exact changed path/status
set, exact runtime before/after Git blobs, reviewed harness blobs and all
materialized checkout bytes/modes, including assume-unchanged/skip-worktree
files. Missing files must be tracked sparse exclusions. It rejects dirty/index,
untracked, symlink and arbitrary runtime changes. `guard.py` and `sources.json`
are the reviewed trust root excluded from recursive self-pinning. External
package manifest hashes cover them; changes require independent review.

Known official dependencies install before isolation. The unchanged existing
asset preparer caches allowlisted public presentation assets. Browser/PHP then
run unprivileged inside a fresh kernel namespace with loopback only, no routes
or credentials, and no fallback. PHP provider transport functions are disabled;
config, SQLite, image files, provider category stub, seller/tall multiline banner rows and
clean-URL router are synthetic. Runtime PHP/CSS/JS otherwise comes from the
verified candidate checkout, not from the author's local pricing snapshot.
Baseline replaces only the pinned CSS with its exact Git preimage. Each variant
uses fresh private state; PHP opcode-cache receipts verify the same server PID.

Headed Chromium under Xvfb receives OS keyboard Ctrl+plus eight times. The
native400 gate requires the 1212x796 content viewport at100% to become303x199
with DPR ratio4 and visualViewport.scale1. No viewport emulation, CSS zoom or
CDP page-scale substitute is accepted. If Chromium/Xvfb cannot produce this,
the job fails and native400 remains unverified.

The baseline must reproduce its oversized sticky nav and specifically clipped
or obscured bottom control. Candidate tests use real Tab/Shift+Tab, pointer
opening, Enter, wheel and real dismiss controls. They never call element.focus,
scrollIntoView or mutate layout to rescue the candidate. Requested theme setup
uses localStorage and real reload. Includes both themes/motion preferences,
banner present/dismissed, pointer/keyboard open after nonzero scroll, immediate
post-open geometry before traversal, all nine links/buttons with full outline
pixel/contrast checks, page exit/reentry, repeated toggles, Home navigation/back,
expanded skip and same-document expanded catalog fragment navigation with nonzero-scroll assertions, and actual window991/992/991
resize while expanded plus normal-zoom theme-control outline captures at100% around the991.98px breakpoint. Noninteger physical window widths
are not claimed. Viewport screenshots use CSS pixel scale for the inherited
outline-pixel oracle; receipt records still prove native DPR4.

Each focused screenshot settles fonts/animations plus three matching host-timed
geometry samples and checks geometry again after capture. Navigation controls
require their full6px outline extent unclipped/unobscured and actual painted
contrast. Page focus uses settled geometry/screenshots and unobscured bounds,
not the navbar-specific3px style oracle. Process descendants are bounded and
terminated on failure; temporary synthetic state is removed. Evidence artifacts
contain screenshots, geometry/pixel receipts, source/isolation, PHP cache and
browser cleanup receipts, and errors. No database/session/config artifact is
uploaded.

Authoring checks do not establish browser acceptance. As authored, browser,
native zoom, visual results, deployment, production/Plesk behavior, Safari,
Firefox, mobile browser chrome and human screen-reader operation are unrun.
A pass only establishes the exact scoped Chromium synthetic acceptance.

The main matrix opens after nonzero wheel scroll. Dedicated banner-link keyboard
reentry and all menu controls at initial scroll0 are not comprehensive coverage
claims. Tall-banner skip navigation explicitly rejects a scroll0-clamped result.
