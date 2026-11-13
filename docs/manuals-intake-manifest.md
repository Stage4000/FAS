# Bulk draft metadata preparation

This CLI joins a previously authorized inventory with owner-reviewed CSV metadata. It produces a **review manifest**, not catalog records. It does not open inventory paths, read or re-hash original PDFs, access a catalog, fetch links, copy files, import, compress, delete or publish anything. Actual client intake remains deferred.

Send one UTF-8 JSON object on stdin with `inventory` (the inventory report object) and `metadata_csv` (the entire CSV string):

```sh
php scripts/manuals-intake-manifest.php < metadata-input.json > draft-review.json
```

The CSV must contain exactly these unique columns, in any order:

```csv
path,title,manufacturer,model,years,language,description
Maker/manual.pdf,Workshop reference,Maker,Model ZX,2020,English,Owner-provided description
```

Use exact inventory-relative path labels. Do not assume a folder name proves a manufacturer, and do not infer model/year coverage from a filename. There is no automatic name normalization or fuzzy matching: ambiguity must be resolved before preparing the reviewed metadata. Blank language becomes English; change it explicitly when another language applies. Title and manufacturer are required. Commas, quotes and embedded newlines follow standard doubled-quote CSV syntax; malformed or unterminated quoting is rejected. UTF-8 BOM on the header is supported. Empty records are ignored but count toward the record bound. Error row numbers identify CSV records, not physical lines within a quoted description.

Metadata validation calls the existing `ManualLibrary::validate(..., false)` directly, so the same Unicode lengths, required fields and control-character rules apply. No copy of those rules is maintained here. CSV rights, source references or publication columns are not accepted. Every prepared row has `rights=unverified`, empty rights evidence/source reference, and `publication_requested=false`. It cannot clear rights or request publication. Later storage and publication still require their normal validation, active administrator and explicit owner release.

## Review output

`drafts` are sorted by exact path label and contain the inventory-observed path/bytes/SHA256 plus normalized draft metadata. Those identity fields are **not current file verification, private storage keys, or a replay-safe import token**. An eventual importer must revalidate bytes and all normal gates; no importer consumes this manifest in this change.

Unknown or excluded paths, duplicate path rows, invalid metadata, wrong column counts and missing mappings appear in `errors`. Some valid rows may still be shown for review, but `ready_for_review` stays false if any error exists or the inventory is incomplete. No partial report authorizes partial import. Duplicate content in separate paths stays separate; this tool neither reuses originals nor claims duplicate-storage savings.

`ready_for_review=true` means the observed **candidate subset** has complete, valid draft metadata, not that the entire library is ready or approved for import. Excluded videos, non-PDFs, oversized files and other records are counted separately and never scheduled. Empty candidate sets can yield an empty review-ready manifest. The result is not a storage-capacity forecast, PDF structural/malware validation, rights decision or production acceptance.

The manifest is private working data. CSV values are literal text, never evaluated as spreadsheet formulas or HTML. Consumers must escape metadata for their output context; exporting it to a spreadsheet may need formula-injection safeguards. Do not send real manifests to third parties without authorization.

## Bounds and prerequisites

The CLI accepts at most 32 MiB of JSON, depth 32; CSV is limited to 16 MiB and 100,000 data records, and inventory to 100,000 file records. It requires the application's PHP 8.1+ runtime and existing mbstring validation support. The only temporary stream contains the supplied CSV, not originals. Duplicate JSON object keys, including escaped spellings of the same key, are rejected rather than silently overriding completeness. Input/limits are structurally checked, not authenticated; stale or dishonest inventory observations remain possible. CLI failures use a generic JSON error rather than echoing raw parser input. Exit 0 means ready for metadata review; exit 2 means an invalid/partial review. No fixed wall-clock guarantee is claimed.

Synthetic tests cover quoted Unicode metadata, full existing validator boundaries, missing/duplicate/excluded mappings, partial inventories, malformed quotes, traversal-like labels, numeric labels, immutable input semantics and CLI failures. No real client files or blocked storage prototypes are used.
