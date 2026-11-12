# Manual PDF byte-range delivery candidate

This is an isolated, undeployed server change for the manual book reader. It does
not change PDF.js flags, release/publication settings, hosting configuration, the
database schema, or the existing **50 MiB** intake ceiling. It includes no actual
manuals or production accounts.

## Server contract

The existing public `/manuals/file.php?id=…` and authenticated private
`/admin/manuals-file.php?id=…` routes retain their authorization, release and rights
checks before entering delivery. Every new request is checked again. Revocation
or withdrawal blocks subsequent requests; bytes already received cannot be
recalled, and an already authorized response is not continuously reauthorized.

The application contract applies to the normalized value exposed by PHP as
`$_SERVER['HTTP_RANGE']`, after the web server/SAPI has parsed the request.
For an authorized, intact, nonempty PDF of N bytes:

- GET without Range returns **200**, Content-Length N, and the exact full original.
- GET with one satisfiable byte range returns **206**, Content-Range
  `bytes START-END/N`, Content-Length `END-START+1`, and exactly that inclusive slice.
- Closed (`bytes=10-29`), open-ended (`bytes=10-`), and suffix (`bytes=-20`) ranges
  are supported. An end beyond EOF clamps to EOF. An oversized suffix selects the
  whole file but still returns 206. Decimal comparisons avoid integer overflow.
- Invalid syntax, unsupported units, a start at/beyond EOF, reversed bounds, a
  zero suffix, more than one range, or a Range field over 256 bytes returns
  **416**, Content-Range `bytes */N`, Content-Length 0, and no body. Repeated Range
  fields combined by the server are rejected as multipart, never partially used.
- HEAD ignores Range, including an invalid Range, per RFC 9110: **200**, the full
  Content-Length N, no Content-Range, and no body.
- No ETag or Last-Modified validator is advertised by this implementation. If an
  If-Range field is present, the server conservatively ignores Range and returns
  the full 200 representation. No stale conditional partial response is guessed.
- Other methods remain **405** with `Allow: GET, HEAD`, after the existing route
  gates. Denied authorization/publication requests retain their normal 302, 403,
  or 404 and disclose no range headers or PDF bytes.

### Duplicate physical fields and the SAPI boundary

PHP does not necessarily expose the original number of physical Range fields.
A server/SAPI can combine duplicates with a comma or retain only one value.
The application rejects the combined multipart value with 416. If the SAPI has
already discarded another field and exposes one valid range, the unchanged
application returns 206 for exactly that authorized slice. It cannot detect or
reject a physical field it never received. This does not widen authorization.
Explicit comma-separated multipart input remains an unconditional 416.

The synthetic HTTP test now observes PHP's Range value on the **same request**
that immediately includes the unchanged public endpoint. Both field orders are
checked. Only exact first-field retention, last-field retention or ordered comma
combination are recognized. Missing, rewritten or unexpected observations fail.
The retained single range must match its exact bytes, Content-Range,
Content-Length and every safety header. Combined input must return an empty 416
with the exact unsatisfied range/length and the same safety headers. A fixed-enum
receipt records the PHP version, normalization kind, observed status/length and
verification booleans without arbitrary headers, bodies or cookies.

The hosted PHP 8.3.6 run failed the earlier unconditional duplicate-field 416
assertion, but did not retain its actual response. Its precise normalization is
therefore still unobserved. Disposable local probes of the available PHP 8.3.36
and 8.4.24 CLI servers both combine the two fields, preserving their order. These
later versions do not prove what PHP 8.3.6 or a production proxy will do.

**Deployment gate:** verify the actual reverse proxy/web server/SAPI chain's
handling of duplicate physical fields before enabling ranges. If rejecting every
duplicate physical Range field is required, enforce and test that rule upstream
before fields are collapsed. Application-only rejection cannot guarantee it.
Do not infer raw-header guarantees from the synthetic PHP built-in server. No
proxy, host setting or runtime code was changed by this test-contract correction.

The 200, 206 and 416 delivery responses carry:

```text
Accept-Ranges: bytes
Content-Type: application/pdf
Content-Disposition: attachment; filename="manual.pdf"
Cache-Control: private, no-store
X-Content-Type-Options: nosniff
Content-Security-Policy: sandbox; default-src 'none'
X-Robots-Tag: noindex, nofollow
Referrer-Policy: no-referrer
```

PHP output buffers are cleared and PHP zlib compression is disabled before
delivery. **Deployment must separately confirm that Apache/Nginx/proxies/CDNs do
not compress or transform these responses, rewrite their lengths, cache them,
or intercept 416 responses.** Application code cannot control every upstream
output filter. No hosting setting was changed by this candidate.

## Integrity and bounded streaming

`ManualStorage::openVerified()` retains the private-root, symlink, containment and
regular-file checks. It checks descriptor identity/length, hashes the complete
opened file with SHA-256, and rewinds that same descriptor for streaming. A path
replacement after verification cannot substitute a different file into the
already open response. The caller owns closing the returned descriptor.
`verifiedPath()` remains API-compatible and performs the same complete check.

**Every full, partial, HEAD and range-error response still hashes the entire
original first.** A changed byte outside the requested slice makes delivery fail
closed with 503, not a partial PDF or a length-disclosing 416. This is correctness
groundwork, not the final large-file performance solution: repeated tiny ranges
still repeat full-file disk reads and hashing. Do not remove full verification
or raise intake limits as part of reader integration.

Delivery seeks once and reads at most 64 KiB per iteration. A full or open-ended
request may send the entire original, but PHP does not load that original into
one buffer. It sends no bytes beyond the selected length. If the underlying file
or connection fails after headers, the response is stopped/logged; error text is
never appended to a PDF response. Native read notices and warnings become a
controlled exception through a narrowly scoped handler that is restored before
the write callback and on every exit; PHP reporting settings are unchanged.
The declared Content-Length allows a client
to detect incomplete content. Private administrator session locks are released
after authorization and before hashing or streaming. Immutable-storage access
controls are still required; this is not a defense against arbitrary concurrent
in-place writes by a compromised host.

## Verification and reproduction

Run with the supported PHP binary and Python/reportlab available:

```text
FAS_TEST_PHP=/path/to/php python tests/manuals/run-range-tests.py
```

The runner creates a new temporary synthetic fixture, starts PHP only on
127.0.0.1, uses a private fixture session/security store, disables outbound
URL-open/process/mail functions in the HTTP server, and terminates that server
in a finally block. PHP output buffering and zlib compression begin enabled
to exercise identity-byte delivery. It leaves its logs/results in the printed
temporary evidence directory. Fixture login/probe helpers must never be deployed.

Completed locally against PHP 8.4.24:

- 73 pure range/descriptor checks, including an exhaustive small-file bounds
  oracle, PHP_INT_MAX arithmetic, 64 KiB copy bounds, short-stream errors,
  same-length corruption, a stable-descriptor/path-replacement regression, and
  native I/O failure/short-read output and error-handler restoration checks.
- All 44 pre-existing backend checks.
- 103 range HTTP checks, including exact bodies/headers, full-file reconstruction,
  HEAD, If-Range, malformed/multipart ranges, same-request normalization of both
  duplicate-field orders, active session closure,
  release holds, rights restriction/unverified status, explicit withdrawal,
  account deactivation, token revocation and outside-slice tampering.
- All 51 pre-existing synthetic HTTP checks.
- PHP syntax and Python syntax checks on the changed/additive code.

The fixture layout is an adapter, not a full storefront integration. No browser
was launched, no PDF.js range mode was enabled, and no proxy/deployment/browser
acceptance is claimed. Integrate these seven files by review, not by deploying
the copied source snapshot wholesale.
