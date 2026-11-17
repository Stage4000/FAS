# Customer showcase: dormant request and form boundary

## Status and provenance

This is a local, unpublished source increment, not a completed or deployed feature.
It adds two runtime classes, three test files and this integration note on top of
the seven-file reviewed persistence package. Five original files remain byte for
byte unchanged. Independent review identified an integration bug: the textarea
allowed ordinary Enter/paragraphs but the foundation validator rejected every
newline. The narrow correction in `ShowcaseSubmission` canonicalizes caption
CRLF/CR to LF, permits LF only in caption, and keeps other controls and multiline
name/email prohibited. Its foundation test retains the negative case using TAB
for caption; new unit and HTTP regressions cover normalization and retry identity.
These are the only two changed baseline files. The recovered
package SHA-256 is
`ae4e1c567f20b02f989eeb41fa27faeaf8f461102d2b0dbc5d7d77f9c12ac14c`.
The earlier foundation commit and CI result do **not** validate this increment.
No existing route, application database, configuration, asset or authorization
implementation was edited. There is no automatic schema initialization.
This note supersedes the historical single-line-caption statement in
`docs/customer-showcase-foundation.md`; that earlier document is preserved as
baseline evidence, not the final caption contract.

The source introduces:

- `ShowcaseRequest`: a native PHP multipart intake boundary, strict request-size,
  upload-provenance, CSRF and session/form retry binding, plus moderation POST
  parsing which delegates current actor authorization and state/audit transactions
  to `ShowcaseRepository`.
- `ShowcaseForm`: an escaped, labelled submission-form fragment with bounded old
  input, explicit unchecked consent, photo-reselection guidance and fixed error
  messages; a minimal committed-receipt fragment that never promises publishing
  or a coupon. It is deliberately not a standalone public page.

Neither class creates a route, starts a session, selects a database, chooses legal
wording, sends email, creates a coupon, draws a winner or publishes a photo.

## Controller contract required before activation

1. Keep the feature disabled until the release gates below are satisfied. Supply
   an explicit admission callback. It must enforce enabled state, rate/spam
   controls and resource admission. Only literal `true` admits a request; errors,
   false and truthy substitutes fail closed. For moderation it must also enforce
   the current active administrator session before invoking the repository.
2. Use the actual `$_SERVER`, `$_POST` and `$_FILES` values. Do not construct them
   from JSON or accept local file paths. `is_uploaded_file()` is mandatory, and
   client names, full paths, extensions and MIME declarations are not trusted.
   Only the regenerated JPEG is retained. PHP manages its temporary upload file.
3. Configure web-server/PHP body, upload, time and process/concurrency limits
   **before** exposing the route. This code runs after PHP has received and parsed
   the request, so the admission callback cannot prevent incoming bytes from
   being buffered/spooled. The 6 MiB upload and 12 MP decode limits do not make an
   image decoder a memory/process sandbox. Request length is required and bounded
   to 6 MiB + 64 KiB; unsupported/unknown-length bodies fail closed.
4. Start a secure session in the controller using the site's established cookie
   and CSRF protections. Generate tokens with a cryptographic source, e.g.
   `bin2hex(random_bytes(32))`. The expected CSRF token and expected retry/form
   identity must come from the server session, not be copied from request input.
   Store outstanding form identities in a bounded, expiring per-session collection.
   A submitted `form_id` can select an existing server record only after a strict
   bounded lookup within that same session. It is never itself authorization.
5. Preserve the form identity across validation errors and successful lost-response
   retries. The same normalized payload returns the same durable record, including
   later moderation state; changed details under the same identity conflict. A new
   form must get a new identity. If cookie/session retention is lost, do not promise
   recovery or exactly-once delivery across sessions. A client must reselect its
   photo after an error; browsers cannot safely repopulate file controls.
6. Resolve reviewed consent text/version from trusted server configuration. Neither
   is accepted from the form. The exact text is displayed and stored with its hash.
   The fixture wording is only synthetic test text and is not customer consent.
7. Use a deliberately initialized, private, capacity-budgeted SQLite destination
   and the repository's existing durable transaction checks. An HTTP success or
   `ShowcaseForm::receipt()` is allowed only after `submit()` returns. Handle lock,
   capacity and storage failures without claiming acceptance or duplicating the
   request under a fresh identity. Show fixed errors rather than raw exceptions,
   SQL, filesystem paths or personal data. Do not log email/photo/form tokens.
8. For moderation, resolve `actorId` from the verified server session and supply
   the current row's ID, integer version, derivative hash and consent hash. The
   repository rechecks authorization inside its transaction; do not pass a cached
   `true` callback. The local source auth pattern uses `AdminAuth::isLoggedIn()`
   against current role/active status/session token, not just an `admin_id` value.
   Its actual integration and current repository revision still need review.
   Never initialize application auth/config/database from these unit tests.
9. Add `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`
   **after session startup**, so default session cache headers do not replace the
   intended policy. Add the site's response/content/security headers in its actual
   controller. This fragment does not emit headers or a complete document.
10. Render the fragment with the site's page shell. Supply a trusted same-origin
    root-relative action path and the exact reviewed permission text. Only the
    fixed `invalid_input`, `expired`, `conflict` and `unavailable` error codes are
    accepted. Old name/email/caption are escaped; consent remains unchecked after
    an error. Apply the final privacy notice and withdrawal/contact behavior.

## Offline verification

Run from the source root with installed PHP GD, EXIF and PDO_SQLite and Python 3:

```text
php tests/showcase-boundary-test.php
php tests/showcase-repository-test.php
php tests/showcase-request-test.php
python tests/showcase-http-test.py
```

The boundary test includes the foundation suite: 193 combined assertions, not 193
plus 188. The persistence test has 206 assertions. The new request/form unit suite
has 153 assertions; the real HTTP harness has 46. Combined unique checks: 598.
Ten PHP files passed lint on Windows PHP 8.5.5. PHP 7.4, the production codec/server
stack, full repository aggregate and exact-commit remote CI have not run.

The HTTP harness starts its own hidden loopback-only PHP process with an ephemeral
fixture key. It uses only a fresh temporary synthetic SQLite database, generated
pixels and `example.invalid` email addresses. No browser, production config or
external request is used. It terminates its own process and removes only its own
verified temporary directory even on failure. The fixture router refuses ordinary
web-server execution and requests without the ephemeral key. It must not be
deployed as an application route.

Coverage includes actual PHP multipart provenance, exact 6 MiB and oversized POST
handling, missing/forged uploads, malicious filenames/MIME, cross-session CSRF/form
tokens, duplicate and conflicting retries, stale decisions, revoked actors, bound
photo/consent identities, withdrawal and audit counts. Unit tests also cover strict
types, unsupported fields, fail-closed callback behavior, hostile form text, fixed
errors and minimal receipts. A real-browser layout/accessibility/novice-flow pass
has not occurred; structural strings and HTTP are not a substitute for it.

## Still required for the requested complete feature

- Owner-reviewed consent/privacy/retention and withdrawal policy; final intake URL.
- Actual disabled-by-default application controllers, session/rate/resource wiring,
  deliberately provisioned private storage/backup budget and deploy review.
- Authenticated moderation queue/detail UI and audit presentation; safe public
  approved-only gallery/image projection with withdrawal/cache behavior.
- One 5% coupon per email with approved issue timing, eligibility, expiry, usage
  and stacking rules; integration with the real coupon system and idempotency.
- Monthly draw implementation with owner-selected prize and approved entrant rules;
  no draw or winner notice before those are resolved.
- Branded final QR regenerated for the verified live intake URL, physical print and
  phone tests, browser acceptance and exact source CI/release verification.

The prior repository upload denial is not retried by this work. This artifact is a
source/test checkpoint; it is not evidence of a repository commit or live change.
