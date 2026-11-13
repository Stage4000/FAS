# Pending navigation during automatic geometry redraws

## Local-only checkpoint

This packet is based on draft PR 173 at
`8c4bb3047e51bdf415fe19511d00206834cce263`, tree
`6de7a0d1fefb7774c42d3cbe2706614e78862042`. Its reader baseline SHA-256 is
`f5a768dd2c3884ba768417594021736c90cfbe64afe37d2af2231c26727d09ec`.
Copied runtime and test inputs were checked against that complete tree inventory.
The existing green PR is unchanged. This is a locally integrated, unpublished
candidate; actual hosted acceptance remains outstanding. No real manuals or new
PDF fixtures were used.

## Reproduced defect

The existing canvas-lifecycle fixture independently reproduced both paths:

1. Begin navigating from cover page 1 to pages 2–3, with the outgoing effect
   still pending.
2. Deliver a changed stage width and execute its resize debounce, or deliver
   `fullscreenchange`.
3. The baseline cancels the effect and commits page/input/hash 1, losing the
   requested destination. A separately delayed raster reproduces the same loss.

`page` means the last successfully committed page, so the previous automatic
`draw(page)` superseded the uncommitted request with that older page. This
deterministic reproduction does not establish which event caused the earlier
hosted motion-test timeout. Its precise triggering event remains unproven.

## Narrow correction

Each draw owns one bounded pending intent containing its epoch token, clamped
destination and `updateHash` policy. Only automatic resize/fullscreen redraws
read that intent, at callback execution time. They redraw without a new turn
animation and without resetting an unsubmitted page-number edit.

Intent is cleared at successful page commit or the current owner's settlement,
including failure/cancellation. An older draw's `finally` cannot clear a newer
owner's intent. Permanent disposal clears it; a persisted departure keeps the
resumable draw. A later geometry event falls back to the committed page, so a
failed destination is not silently retried.

Initial deep links and pending history navigation retain `updateHash=false`.
A newer explicit request replaces that policy with its own. Once committed,
automatic redraw keeps its existing committed-page/hash behavior. Zoom, fit,
layout and repeated turn commands retain their existing semantics; this patch
does not reinterpret them as automatic resize operations.

## Verification and limits

The amended existing canvas-lifecycle fixture adds 170 assertions, including
pending raster/outgoing/incoming phases for both automatic events, stale-owner
settlement, delayed callback priority, failed automatic retries, render failures,
edited input, exact odd-page narrowing, initial deep links, history policy and
permanent/persisted departure. Its 120ms debounce is executed deterministically,
not replaced by timing sleeps. Existing motion/canvas assertions remain in place.

Six focused suites pass 519 assertions: canvas/motion/resize 432, input race 6,
render failure 10, lifecycle 10, outline integration 19 and page resources 42.
Both edited JavaScript files pass syntax checks. Five negative controls are
rejected: unchanged baseline, discarded hash policy, unconditional stale-owner
cleanup, retained failed intent and intent captured before the debounce fires.

These are DOM/PDF/native-animation stubs, not browser or memory/performance
acceptance. Existing hosted settled-resize/fullscreen cases do not prove the new
pending-flow behavior. The core correction passed independent review.
Cache/source-pin integration and pending-flow browser assertions are included;
independent final integration review and an authorized draft upload with targeted
hosted acceptance remain separate gates.
No browser was launched, no source was published, and no hosting, merge,
workflow execution, fixture-security or production configuration action was taken.


## Local integration

Loader and template use the matching `20261113b` cache token. The complete
immutable repository inventory remains the policy base; the finite correction
set gains only this document path. Source pins are refreshed for exactly the
changed files. Existing verifier functions, historical inventories, workflow
execution/permissions/dependencies and namespace isolation remain unchanged.
The three existing workflows gain only the exact new document dependency and,
where necessary, a matching path trigger. Actual-browser pending geometry cases
reuse the original PDF; their preparation is not proof they passed in a browser.

No source publication, hosted CI rerun, local browser launch, merge, hosting,
deployment, actual manual import or public activation occurred. Green PR 173
remains at the baseline above. A new packet-specific authorization is needed for
draft upload and isolated CI; existing merge/deployment holds are preserved.
