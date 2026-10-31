# New product upload identity (source candidate)

Primary and additional admin uploads now share `ImmutableProductUpload::receive`.
It requires PHP's actual HTTP-upload provenance, reads at most20MiB+1, validates
supported image headers/dimensions up to20 megapixels, and chooses the extension
from the detected format—not the client filename or claimed MIME. JPEG, PNG,
GIF and WebP original bytes are retained unchanged; no synchronous encoding occurs.
This is header validation, not full codec decoding or malware scanning.

New paths are `/gallery/uploads/immutable/sha256-<content-hash>-<random-upload-nonce>.<format>`.
A complete staged file is linked into its final name without overwriting an
existing target. Each upload gets its own random identity, even for identical bytes, so cleanup of an older product cannot delete a not-yet-saved new upload. An accidental exact-name collision is rehashed before reuse; conflicting
bytes, symlinks and non-files are refused and preserved. Temporary staging files
are removed. Unsupported hard-link publication fails rather than falling back to
an overwrite. Existing legacy paths and product image ownership are not migrated.

Both admin upload branches show upload failures, including PHP size/partial/write
errors, before allowing the catalog save. Optional NO_FILE is not an error.
An upload may precede a later failed catalog save and remain unreferenced; this
increment does not delete possible customer originals or invent cleanup authority.
Existing Product reference checks are unchanged; global shared-original deduplication is deliberately not introduced.

## Trust and integration limits

“Immutable” describes this helper's no-replacement convention, not filesystem
isolation against another process with write access to the directory. File
permissions and hash names are not cryptographic evidence that current bytes
remain unchanged. Returned hash/size/dimensions are ingest evidence only.

No public renderer change, trusted metadata receipt, queue, worker, remote download,
receipt renewal or backfill is enabled here. The existing public fallback and
receipt verification remain unchanged. A future durable outbox must bind successful
catalog saves to image intents and revalidate catalog/byte identity before activation.
Do not use the filename alone to bypass verification.

The separate snapshot-on-every-public-request experiment was not adopted: a warm
local30-image45MiB synthetic benchmark increased median lookup time from0.493ms
to200.360ms and peak memory from2MiB toapproximately22MiB. Those are local fixture
results, not production measurements or performance promises.

## Verification

Synthetic fixtures cover byte retention, per-upload deletion isolation, exact-name collision verification, conflict/symlink/directory
refusal, byte/pixel limits, original upload provenance rejection, both real admin
error-handling blocks and no encoding. A disposable loopback PHP multipart test
exercises a successful real upload, actual-format extension selection and distinct
upload identities. Full admin browser/auth/catalog integration, concurrent filesystem
adversaries, real codec quality, production filesystem support and staging rollout
remain separate acceptance gates. No production files or database were changed.
