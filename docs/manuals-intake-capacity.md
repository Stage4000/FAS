# Candidate-library capacity and sequential batch planning

This read-only tool consumes a previously authorized inventory JSON through stdin. It never opens paths listed in the report, reads original PDFs, fetches links, connects to a database, changes hosting, imports, compresses, deletes or publishes manuals. All development inputs are synthetic. Actual client intake remains deferred.

```sh
python3 scripts/manuals-intake-capacity.py \
  --capacity-bytes 10000000000 --used-bytes 3000000000 \
  --reserve-bytes 1000000000 --upload-limit-bytes 2097152 \
  --batch-bytes 268435456 < inventory.json > capacity-plan.json
```

These example values are assumptions, not verified FAS capacity, current usage or PHP limits. Decimal 10 GB is 10,000,000,000 bytes, not 10 GiB. Supply the actual effective upload limit: the importer ceiling is 50 MiB, but the host may allow less. Capacity must describe the relevant storage budget, not an unrelated advertised plan. All options and output byte counts are integer bytes.

## What it calculates

Every verified inventory candidate counts as one new stored original. Identical hashes, hard links and duplicate groups **do not reduce this total**, because the current importer does not deduplicate stored originals. No unsafe duplicate-reuse prototype is involved.

The proposed order is descending size, then path for deterministic ties. For serial processing, each file's estimate is `existing usage + earlier stored originals + 2 × current file size`: one destination copy and one full temporary upload copy are assumed on the same capacity budget. The peak is the largest of those values and initial usage. A descending order minimizes that simple model's temporary-copy peak; this is not a parallel scheduler. Transfer batches group consecutive files by the chosen byte budget without implying multiple files can be uploaded together. A file bigger than the batch limit is explicitly blocked rather than silently splitting a PDF.

The operator's required reserve is added to the modeled peak. Reserve must cover filesystem block allocation, database and logs, unrelated site growth, repeated/failed requests and concurrent work. The tool cannot measure or guarantee those costs. If temporary files are on another device, this same-budget model may overestimate the site's quota use; do not subtract storage without verifying the real arrangement. Capacity and inventory can become stale before any eventual import.

`candidate_plan_fits` is true only for a complete inventory whose **candidate subset** fits the supplied model and whose candidates fit both effective upload and batch limits. It is not whole-library or import readiness. Excluded counts remain explicit: videos, oversized PDFs, non-PDFs and other exclusions are not scheduled, even when this field is true. Incomplete inventories always return false and exit 2, while still giving estimates for observed candidates. Excluded/unobserved files may need separate owner decisions. Candidate header/hash checks do not establish full PDF validity, rights or compatibility.

`additional_stored_bytes`, `final_used_bytes`, `peak_used_bytes`, `required_capacity_with_reserve` and `capacity_shortfall_bytes` make the storage boundary visible. `blocked_files` names candidate limit issues; `batches` preserves the advisory serial order. Neither field authorizes any action.

## Input and execution bounds

JSON input is limited to 32 MiB and 100,000 records, with relative path labels up to 4,096 characters. Paths are labels only and are never opened. Malformed JSON, invalid versions/limits/digests/paths, duplicate path labels, inconsistent candidate totals and inconsistent completeness are rejected without echoing raw parser messages. Booleans are not integer limits. Unknown record status values are rejected. The inventory is structurally checked, not authenticated or re-hashed; a dishonest report can still contain dishonest observations.

The program uses Python's standard library, has no network operations and emits JSON to stdout. Exit 0 means the observed candidate plan fits supplied assumptions; exit 2 means invalid input or a non-fitting/partial plan. No fixed wall-clock deadline is claimed. Source tests use metadata and temporary synthetic JSON only, including exact capacity boundaries, two-copy peaks, all-duplicate inputs, partial inventories, effective host limits, malformed/oversized input and exhaustive four-file order comparisons.
