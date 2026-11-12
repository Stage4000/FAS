# Exact manuals compatibility with historical repository gates

This source-only correction retains the storefront keyboard and authenticated
admin tests, runtime identities, security checks, isolation and cleanup. It does
not authorize merge, deployment, private-manual import or production changes.

## Immutable source boundary

The connector-read snapshot `b99c463f31210413562efdef3e729383c30436a8`
(tree `b4bd3e1baa9980ed97cc5198290dcec514e8c7c4`) adds exactly 309
regular files to main `6993209ba92dbe77c36e3a61ba7146bf2d2db236`
(tree `8fdf7f25f130ddbda16d7b27ecc90fb6c7699345`). All 513 main
entries are unchanged. `reviewed-repository.json` records both complete maps;
the helper reconstructs both Git tree identities offline. Its embedded original
manuals manifest must match the immutable b99 manifest blob, binding that
manifest's original source keys, pins, scope and statuses too.

Only the literal `COMPATIBILITY` names can differ from b99. No directory,
extension, changed-path wildcard, environment override or arbitrary exclusion
is accepted. Original runtime, assets and tests outside those named guard-only
files must retain their exact b99 blob and mode. Missing entries, extra entries,
nonregular entries, dirty index/working bytes, untracked (including ignored)
files and hidden skip-worktree/assume-unchanged edits fail closed. The committed
`HEAD^{tree}` must also equal the reconstructed candidate root hash. Comparing
only recursively listed blobs would miss empty subtrees and normalized tree
encoding; committed root/nested empty trees and noncanonical tree encodings are
covered by actual metadata-object negative controls.

The existing manuals source manifest remains the independently reviewed policy
root, with its established self-hash exclusion. Its exact key set, source pin
set, baseline, scope and changed-status map are checked. Every finite
compatibility source except that manifest is pinned by it; the manifest itself
must be a regular committed file with identical materialized bytes. This is a
one-way trust design, not a mutually recursive hash scheme. It does not prove
integrity against arbitrary edits to the policy, helper or workflow themselves.
Their exact release bytes require independent review and an external release
hash receipt, as before.

The compatibility candidate changes 11 files against b99: five existing-main
guard/workflow files, two files already added by the manuals PR, and four new
test/documentation files. It has 826 tree entries and 318 changed paths against
main (313 additions, five modifications). Thus 513 unchanged main entries refers
to the original b99 snapshot, not this candidate. All application runtime/assets
remain byte-identical; only the five named main guard/workflow files change.

Both legacy workflow event filters explicitly include the shared policy/helper
paths. Their sparse rules are checked with Git's own pattern matcher. Python
bytecode generation is disabled for the keyboard runner so importing the new
guard does not create untracked cache files; untracked files are still rejected.

## Historical guard integration

The keyboard guard first validates the complete candidate tree and any
materialized bytes, then accepts that exact overlay while retaining its original
runtime normalization and fixture controls. Its old synthetic tree tests still
exercise the historical policy without an overlay. The catalog identity test
continues checking the old overlay entries and uses reviewed current pins only
for the explicitly changed guard-only files.

Both historical maps also predate three changes already merged on main:
`docs/reviewed-product-facts.md`, `src/utils/ReviewedProductFacts.php`, and
`tests/rotor-reviewed-facts-test.php`. Their exact immutable main postimages are
included, and admin verifies the two old preimages as well. None is edited here.

The admin guard first validates the same complete candidate. It still requires
its historical baseline `81dc308e6a7830f31ea52260a7122cee4c6d5222`,
its exact tree, ancestry, the original 12-path catalog delta and runtime
before/after pins. Only the verified finite overlay extends that delta. Its
original synthetic 12-path guard tests remain unchanged.

Admin checkout increases from 20 to a bounded 60 commits. Immutable GitHub
comparison reports 37 commits from its baseline to b99, leaving room for this
compatibility commit. If later history exceeds that bound, the existing ancestry
check still fails; no network fetch or fallback happens inside isolation.
Keyboard checkout now explicitly selects the PR head rather than a merge ref.
It requires no ancestor object because both complete inventory trees are
reconstructed locally. Sparse patterns materialize every required policy/helper
file; absent non-consumed runtime files require honest sparse-index flags and
still have their committed identities verified. Manuals' original source guard
and source-pin materialization requirements are unchanged.

## Evidence and remaining gate

`python3 tests/manuals/browser/repository-compatibility-test.py` exercises pure
policy and disposable, no-remote Git fixtures. Missing blob objects are allowed
only while constructing those metadata fixtures; they are never executed or
presented as full runtime/browser evidence. Existing legacy guard tests continue
to run. No local browser, PHP server or external network is used by these tests.

The hosted keyboard, admin and manuals jobs must all rerun on the exact reviewed
candidate. These offline checks do not prove browser behavior or a production
host/deployment boundary. Previously green manuals reader/shell evidence remains
for its earlier exact head, not for an unexecuted compatibility commit.
