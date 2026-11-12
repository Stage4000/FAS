# Read-only local manuals intake inventory

This optional Python 3 standard-library CLI inventories an explicitly supplied **local directory**. It does not fetch Drive links, contact a network, import manuals, modify storage/schema/configuration, delete files, approve rights, or publish content. It writes JSON only to stdout; the operator may redirect output. No deferred client files were scanned during development.

```sh
python3 scripts/manuals-intake-inventory.py /authorized/local/directory
python3 scripts/manuals-intake-inventory.py /authorized/local/directory --max-entries 10000 --max-depth 16 > inventory.json
python3 tests/manuals/intake-inventory-test.py
```

## Safety and bounds

Supported only on POSIX platforms exposing directory-relative open/stat, descriptor scandir, O_NOFOLLOW, O_DIRECTORY and O_NONBLOCK. Unsupported systems fail closed with JSON and exit 2. Every root path component is opened without following symlinks; `..` root components are rejected. Files and child directories are opened relative to pinned directory descriptors. Symlinks and special files, including FIFOs, are excluded. Regular descriptors are checked before reads, preventing stat/open substitutions from being treated as files. No write-capable source descriptors are used. Reads may update filesystem access timestamps according to OS policy.

Default traversal budget is 10,000 directory entries total, including directories, symlinks and special files. A budget is reserved globally during enumeration, before recursion, so buffered parent entries cannot multiply it. At most one extra entry is probed per visited directory to discover exhaustion. Default maximum descent is 16, counting the root as depth zero. Limits may be configured to 1–100,000 entries and depth 0–64. A cutoff makes the report partial; it is not a complete inventory. Report arrays are sorted by relative path; the subset retained at an exhausted enumeration budget may depend on filesystem enumeration order.

Only case-insensitive `.pdf` regular files with initial lengths 1 through 50 MiB are hashed. Each hash reads at most `min(initial_size + 1, 50 MiB + 1)` bytes, including a growth-detection byte. There is no whole-file buffer. Device, inode, type/mode, size, modification and change timestamps are compared at initial/opened/after-read/path identity checkpoints. Detected changes or read errors exclude that candidate and mark the report incomplete. Directory identity and metadata are rechecked after traversal. This is a bounded observation, not an atomic filesystem snapshot or protection against a privileged actor manipulating filesystem state.

## Interpretation

- `candidate_count` and `candidate_bytes`: observed stable files beginning with a PDF version header, counted once **per path**.
- `unique_candidate_bytes`: one file length per exact SHA256/length group.
- `duplicate_candidate_bytes`: candidate minus unique logical bytes; a planning estimate only.
- `duplicate_groups`: exact-content candidate groups with relative paths, SHA256 and bytes per file.
- `regular_bytes_observed`: sum of initial observed regular-file lengths, including exclusions.
- `excluded_counts` / `excluded_bytes`: video, non-PDF, empty, oversized, bad-header, unreadable, changed and symlink/special categories as observed. Symlink/special sizes are not included. Excluded sizes are initial metadata estimates, not verified read lengths; an unreadable path may have unknown size and appear only in errors.
- `complete` / `errors`: whether traversal and verification finished without errors or limits. Empty error path means root-level error. Absolute paths and raw exception messages are not emitted. Filenames are escaped as JSON.

**Header/hash checks do not validate PDF structure, MIME, malware safety, page compatibility, or distribution rights.** A fake/truncated PDF with a matching header can be a candidate. Hidden PDF files with non-PDF extensions are classified as non-PDF rather than sniffed. Video classification uses common filename extensions, not MIME. The current importer **does not deduplicate**: these numbers do not promise imported-storage savings or authorize deletion. Byte totals are logical file-path sizes, not allocated disk blocks, compression ratios, or physical storage consumption; hard-linked paths may already share blocks. Any future intake must still use the real import validation, security gates, owner scope and rights review.

Exit 0 means the bounded inventory completed, even if ordinary non-PDF/video/invalid-header files were excluded. Exit 2 means partial/unsupported/unreadable/changed input or invalid command arguments. No fixed CPU/wall-clock deadline is claimed. Large authorized trees can require substantial total hashing work within the explicit per-file and entry limits.

## Synthetic verification

Tests use temporary synthetic bytes only: nested/case-insensitive paths; exact duplicates and hard links; videos/non-PDF/empty/bad headers; sparse oversized files; bounded growth/truncation/path replacement; symlink ancestry; PDF-to-symlink/FIFO and directory-to-symlink races; global enumeration/depth budgets; denied reads; JSON escaping and CLI status. They do not validate real manuals or claim production intake readiness.
