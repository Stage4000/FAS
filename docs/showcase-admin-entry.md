# Dormant admin entry routes

Four fixed wrappers now call one buffered entry: `/admin/showcase-queue.php`, `showcase-detail.php`, `showcase-photo.php`, and `showcase-moderate.php`. Absent `FAS_SHOWCASE_ADMIN_BOOTSTRAP` returns 404 without starting a session or touching the database. No runtime bootstrap ships in this packet; these files are not installed on production.

The environment value must identify an exact canonical PHP file outside this application document root, with no symlink component. Deployment must separately ensure it is outside every served alias/public root and writable only by trusted operators. Only native server `HTTPS=on|1` is accepted, before requiring runtime. Forwarded headers do not enable it.

The private bootstrap supplies either null (closed) or `['controller' => ShowcaseAdminController, 'csrf' => current server-side token]`. It must initialize the existing application's current admin authentication, native locked session and repository sharing the same current authorization bridge. It must set strict-mode, cookie-only, Secure, HttpOnly, and Lax/Strict options BEFORE session_start. Entry verifies these options but cannot undo any unsafe cookie already emitted by an incorrectly written bootstrap. Never echo/flush runtime output, initialize unrelated accounts, trust caller actor/CSRF, or reuse the intake session. Controller paths must match these wrappers.

Returned body is emitted only after successful native session persistence. Exceptions produce generic 503; any active session is aborted in cleanup. Bootstrap output is discarded, incidental Location removed, and all responses are no-store/nosniff with restricted CSP. Controller-generated JPEG headers and 303 review redirects remain intact.

59 actual loopback PHP tests cover disabled/missing/linked/invalid/null/error runtimes, insecure request and forwarding spoof rejection, bad session/CSRF rejection, output/redirect suppression, unauthenticated denial, fixed queue/detail/JPEG/moderation wrappers, native cookie flags and stale decisions. Server HTTPS state is simulated: this is NOT real TLS, browser or production acceptance.

The initial native Chromium run was blocked in the local environment at process singleton socket creation. A later authorized [isolated browser run](https://github.com/Stage4000/FAS/actions/runs/43974511114) passed the **Admin lifecycle, logout privacy and mobile regression** step at exact PR #185 head `cd6cc4cd51cb9ac97ea0224c2e6a37e4a3c36ae5`, before source integration in `a30dd4b8c465d45697d5b94be9f7b08c64ff2ec4`. This verifies the synthetic browser fixture, not the production TLS, session, storage, or customer launch.

Release still requires actual trusted bootstrap, live account/storage/session integration, theme, privacy/incentive decisions, hosted source/CI approval and real TLS/browser/production acceptance. This source-only change does not authorize or enable release.

## Review-page document envelope
Queue, detail and moderation HTML errors have HTML5 doctype, language, UTF-8,
title, viewport and one main landmark. Photo responses are excluded to preserve
binary bytes and length. Successful moderation's 303 response remains empty with
its original Location. No theme assets, authentication or session behavior changes.
Native HTTP fixtures cover closed/error/success forms and conflict pages. Real
TLS, browser/theme, accessibility device and production acceptance remain gated.
