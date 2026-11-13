# Reader motion interruption: isolated core correction

This isolated integration packet incorporates the reviewed reader correction and
its existing canvas-lifecycle regression tests. Loader/template cache tokens,
exact source pins, finite repository-policy paths and sparse CI dependencies
are updated together. The hosted browser test is adapted for the corrected
button semantics and active-animation interruption. It has not been published
or executed in a browser. Green PR 173 remains unchanged; no manual content,
public activation or host settings change.

## Reproduced baseline

The parent-pinned reader at PR head
`ec70833971d81e47d279f57cd89b33504113a5f1` has Git blob
`edc5ffdf906516f6fb062df02c51de8b9ca3d220` and SHA256
`08a679ca913cd6b6530bf8af28b022f4455b01fe3a016760f0d53138ce781139`.
Its bytes and all reused regression inputs were checked against the immutable
b99 repository inventory, where these paths lie outside the subsequent finite
guard-compatibility changes.

The existing canvas-lifecycle VM/DOM/PDF fixture, extended with deferred
animation promises and media-query events, reproduces three failures:

- Selecting animation off does not cancel an already-running animation.
- Clicking under OS reduced motion can label animation “on” while it remains off.
- An OS reduce on/off cycle discards a manually selected off preference.

## Preference and ownership rules

Manual off and OS reduction are independent. Effective off is their logical OR.
Selecting off or receiving an OS reduction event synchronously cancels active
page animation and clears its transforms. The existing cancellation-aware draw
continues normally; no render epoch, raster cancellation or page ownership rule
is changed. An obsolete draw still cannot commit over a newer navigation.

While the OS forces reduced motion, the button says
“Page animation off (system preference)”, has `aria-pressed="true"` and
`aria-disabled="true"`, and ignores activation. It remains natively focusable:
there is no forced focus movement or disabled-focused-control removal. Such
activation does not silently alter the saved manual choice. When OS reduction
clears, the prior manual preference resumes. The choice remains in-memory for
this reader, not persisted across reloads.

Rapid off/on changes cancel the current effect permanently. If the same draw
then reaches a new incoming phase with motion allowed again, that new phase may
animate; an already-interrupted incoming phase is not replayed. Permanent
reader departure keeps later preference and navigation events inert. A
persisted bfcache departure retains the resumable reader and its motion controls.

## Deterministic evidence and remaining integration

Run the amended existing fixture with:

```sh
node --unhandled-rejections=strict tests/manuals/reader-canvas-lifecycle-test.cjs
```

Its 262 assertions include the original 18 canvas-lifetime checks and 244 motion
checks: outgoing/incoming cancellation through both controls, focus and truthful
labels, repeated/rapid changes, manual off preservation, pending rasterization,
newer navigation ownership, settled page/input/hash/text state, departure and
no spurious render-error message. A watchdog prevents an unresolved cancellation
promise from silently passing. `--probe-motion` prints only the three synthetic
baseline/candidate observations. Single forced-off activations are checked before
clearing OS reduction for both the initially allowed and manual-off choices;
double activation alone could hide an incorrect preference toggle. Removing
only the OS-reduction condition from the click guard fails each single-activation
case independently in temporary mutant copies. The correct runtime is unchanged
by this regression strengthening.

Nine other relevant unchanged suites pass: state/source contract, bounded cache,
render failure, page-input race, lifecycle, search cancellation, cooperative
search yielding, search focus and in-flight text deduplication. These are
controls using deterministic DOM/PDF stubs, not actual browser animation,
accessibility, bfcache, heap or performance acceptance.

## Integration boundary

The current PR baseline is `ec70833971d81e47d279f57cd89b33504113a5f1`.
Only six exact motion-related paths are added to the repository policy's finite
correction set; all other original source identities remain immutable. The
existing immutable main and b99 inventory, root-tree verification, dirty-tree,
index, path/mode and source checks are preserved. Sparse checkout additions and
workflow triggers are exact test/document paths, not broader access or execution
permissions. Loader and template use the same new `20261113a` cache token.

The original hosted case `03-real-turn-animation-and-reduced-motion` clicked the
now correctly aria-disabled control and would wait forever for it to become
enabled. Its adaptation must assert the truthful disabled/pressed label and
retained focus, and deliberately exercise the inert handler without bypassing
production behavior. The added native-animation controls pause real browser
animations at known turn phases, so preference interruption is not a race with
the normal short animation duration; ordinary unpaused animation coverage stays
in place. The existing synthetic PDF is reused.

Independent source review and exact-head hosted acceptance are required before
calling this integration release-ready. Deterministic source/DOM/PDF controls
are not browser animation, layout, focus or performance proof. There has been
no local browser launch and no retry of a denied operation. Publication requires
separate authorization; merge/deployment and actual manual import remain held.
