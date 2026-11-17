# Dormant gallery transport

Two additive fixed wrappers (`showcase-gallery.php`, `showcase-photo.php`) compose the reviewed public controller. They are source only; no production route is installed. With no `FAS_SHOWCASE_PUBLIC_BOOTSTRAP` environment setting they return a no-store 404 without opening storage or sessions. Explicit server HTTPS is mandatory before evaluating the bootstrap; forwarded request headers are not trusted.

The private canonical, non-symlink PHP bootstrap must live outside the source web root and returns null or a reviewed `ShowcasePublicController`. It must provide its own separate strict publication gate and exact reviewed consent identity. No bootstrap, policy value, schema, write capability or enablement ships with these wrappers. No account/session is needed for approved public reads. This is not a sandbox: the trusted bootstrap must not flush output, start or close sessions, mutate storage or perform other side effects. The wrapper refuses an active session and aborts it if introduced inadvertently. Private output, cookies, redirects and incidental bootstrap headers are discarded. It then emits only controlled response headers and bytes. Infrastructure must add required HSTS/other server security headers after this PHP response; header_remove intentionally removes all PHP bootstrap headers. Proxy/CDN behavior still requires verification.

The test uses only a temporary SQLite database, synthetic photo/consent and loopback PHP server. HTTPS server state is simulated. It covers disabled/missing/noncanonical/symlink/exception/invalid/session bootstrap, strict publication closure, no output/header/cookie leakage, approved escaped gallery, exact JPEG length/type, invalid queries, method restrictions and forwarded TLS spoofing. This is not browser, actual TLS, hosted CI or production acceptance. Existing privacy, consent, retention, incentive and release gates remain.

## Gallery document envelope (cloud integration)
Gallery responses, including closed/error states, now supply HTML5 doctype,
English language, UTF-8, a descriptive page title, mobile viewport and one main
landmark. The escaped controller fragment remains unchanged. No scripts, style
resources or theme behavior are introduced. The photo endpoint is deliberately
excluded so its JPEG bytes, content type and content length remain unchanged.
Native HTTP fixtures cover these boundaries; this is not TLS, browser, assistive
technology, deployed theme or release acceptance.
