# Manual storage under restrictive open_basedir

This follow-up is a narrow correction after the byte-range delivery checkpoint.
It changes only the ManualStorage constructor and adds an isolated regression
test. No production configuration, public flags, database, storage contents,
PDF.js settings or PHP access restrictions were changed.

## Correction

The old constructor checked every configured storage ancestor with `is_link()`.
With open_basedir limited to a private fixture/application directory, checks of
its parents emitted PHP warnings even though the actual storage was allowed.
With display_errors enabled, these warnings could corrupt a PDF response.

The storage configuration must now use its canonical absolute filesystem path.
After resolving the root, the constructor compares its configured spelling to
the canonical path. A trailing separator is allowed. Relative paths, dot/dot-dot
segments, internal repeated separators, symlink aliases and stream-wrapper
spellings fail closed. The resolved private directory must still be writable,
and it cannot equal, contain or sit inside the application web root.

The constructor no longer probes inaccessible ancestors. A temporary error
handler surrounds only its directory-resolution/type/writability checks and
converts filesystem warnings, including open_basedir denials, into a controlled
exception. The caller's error handler is restored in a finally block on success
and failure. No global warning suppression, error-reporting change, `@` operator
or open_basedir expansion is used.

This does not replace the operator's duty to keep storage outside every hosted
root, alias and public mount. The opened-descriptor identity and full SHA-256
checks from the earlier range checkpoint are unchanged.

## Reproduction

```text
FAS_TEST_PHP=/path/to/php python tests/manuals/open-basedir-test.py
```

The test copies synthetic fixture data into a fresh temporary directory and
confines open_basedir to that exact directory. It runs with display_errors=1,
log_errors=1 and all-error reporting. allow_url_fopen remains disabled. The
server disables mail, curl execution, process execution and outbound socket
functions using the browser harness's restriction list. Session and upload
temporary directories stay inside the existing allowed directory; the allowed
boundary is never broadened. The local server is terminated after the run.

Verified on PHP 8.4.24:

- 42 constructor cases cover valid paths, trailing separators, direct/nested and
  outside-target symlinks, outside paths, missing paths, relative/aliased/dot
  spellings, root containment, embedded NUL and stream-wrapper spellings.
  Every case runs both normally and with a caller error handler to check its
  restoration. open_basedir and error_reporting stay unchanged.
- All 51 existing HTTP checks pass and leave a completely empty PHP error log.
- All 103 range HTTP checks pass. Their six deliberately corrupted-file
  requests log only the expected application integrity errors, with no PHP
  filesystem or headers-already-sent warnings.
- Invalid storage settings return a clean controlled 503 body with no PDF,
  warning output or range metadata, even with display_errors enabled.
- The new harness reports 52 top-level checks, including those HTTP-suite and
  log assertions. The earlier range checkpoint fails the very first canonical
  constructor case because its ancestor probes emit warnings.
- All 64 range/descriptor and 44 backend checks remain green after the change.

No browser or production acceptance is claimed by this test. The synthetic
login/probe scripts and fixture accounts must never be deployed.
