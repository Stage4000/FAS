# Customer photo showcase foundation

Source-only, inactive building blocks for a future moderated customer-photo
showcase. Neither class is wired into an application route or included by a
production entrypoint. This is not a finished upload feature.

## Photo normalization

`src/showcase/ShowcasePhoto.php` accepts already-read JPEG/PNG bytes. It checks
6 MiB input, 12 megapixels and 6000 pixels per side before decoding, rejects PNG
animation, applies JPEG EXIF orientations 1-8 and regenerates the pixels as JPEG.
The derivative has no original EXIF/GPS, comments, PNG text or file trailer. A
transparent PNG is flattened on white. Output is capped at 8 MiB. The method
returns bytes and their exact SHA-256, size, MIME and dimensions, with no file,
network, database or request side effects. GD and EXIF are required.

These are technical defaults, not production capacity approval. The output is a
normalized derivative rather than an archival original. PNG EXIF orientation is
not applied. Unsupported phone formats need separate conversion and acceptance
work. Pixel/input limits are not a process-memory or concurrency sandbox and GD
is not a malware scanner.

## Private pending-review data

`src/showcase/ShowcaseSubmission.php` validates a display name, contact email,
single-line caption and explicit Boolean permission. It prepares an immutable
`pending_review` record containing the derivative identity and the server's consent
text hash/version. Request fields cannot choose approval status, consent wording,
paths or incentive terms. The server's version and wording must be reviewed and
supplied by trusted application code, not copied from a request.

The record is not yet saved. A hash alone is not a consent archive: the exact
versioned wording and authoritative acceptance timestamp must be durably retained
by the future persistence layer. The value object establishes neither authenticated
identity nor legal sufficiency. Its contact email is private; never render the
whole record publicly. All text remains plain data and must be escaped at each
HTML/attribute output sink. No public projection or approve/publish method exists.

## Verification

Run offline with PHP CLI, GD and EXIF:

```
php tests/showcase-foundation-test.php
php tests/showcase-boundary-test.php
```

The existing synthetic runner discovers both `*-test.php` files. The boundary
suite reuses the foundation suite before its extra cases. Count unique assertions
separately from repeated suite executions. Fixtures contain synthetic pixels and
`example.invalid` addresses; no application config, real customer data or provider
is loaded.

Local Windows PHP 8.5.5 verification: 188 foundation assertions and five additional
boundary assertions, 193 combined. Four PHP syntax checks pass. Coverage includes
all orientation pixel positions, JPEG/PNG metadata and trailer removal, indexed
and full-alpha transparency, input/pixel/edge limits, rejected animation/malformed
images, big-endian EXIF, actual 12 MP rotation, repeated parser-failure cleanup,
strict consent/text types, ignored self-approval fields and restored caller
buffers/error handlers. The PHP 7.4 language target and production PHP/image-codec
stack have not been executed here. CI remains a separate gate.

## Remaining integration gates

1. Review exact customer permission wording, privacy notices, public-name behavior,
   original-photo retention and withdrawal/deletion policy.
2. Implement private immutable storage and durable submission records with a
   verified capacity/backup budget, retry identity and an atomic or explicitly
   recoverable file/DB handoff. A success response must mean durable acceptance.
3. Add bounded HTTP-upload provenance/error handling, request/body ceilings,
   rate/spam controls, applicable CSRF protection and resource-limited processing.
4. Add authenticated moderation, stale-update protection and audit history.
   Approval must bind the reviewed image and consent version. Public serving must
   exclude pending/rejected/withdrawn items and use fixed MIME, `nosniff` and safe
   cache withdrawal behavior. No permissions or security settings change here.
5. Build accessible submission, error recovery, receipt and gallery UI; verify
   actual mobile/desktop, browser and novice workflows.
6. Resolve coupon eligibility, issue timing, expiry, limits and stacking before
   issuing the intended 5% incentive. Resolve prize and entrant rules before any
   monthly draw. Neither feature is implemented or activated in this increment.
7. Verify the final submission URL before creating and physically testing a flyer
   QR. No URL is reserved or advertised here.

No live uploads, emails, coupons, winner selections, schema changes, activation or
deployment are part of this source increment.
