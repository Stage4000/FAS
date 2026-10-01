# Rate limiting and Security dashboard

The Security page is at /admin/security.php. Only active administrators can access it. Changing enforcement, rules, temporary blocks, counter clearing, and restoring defaults require CSRF protection and password confirmation within the last ten minutes.

## Deployment

Deploy all security helpers, endpoint integrations, admin views, and checkout/analytics JavaScript together. Preserve the existing site configuration, inventory database, and SEO changes.

1. Set FAS_SECURITY_DB_PATH to an absolute SQLite filename outside every publicly served directory. The default is fas-private/security.sqlite beside the application directory. PHP and CLI maintenance must share this path and have write access. Use a local filesystem shared by all workers on the **single origin**; multiple origins need a shared limiter implementation first.
2. Run the initialization command below using the PHP worker's environment. New installations observe limits until explicit activation. Confirm the database and its directory cannot be downloaded. New directories/files use 0700/0600 on Unix; verify ownership and Windows ACLs as applicable. Existing parent permissions are not automatically changed.
3. Open Security → Overview from two different connections and compare detected visitor addresses with those connections. Forwarded headers are accepted only from the pinned [Cloudflare IP ranges](https://www.cloudflare.com/ips/) or FAS_TRUSTED_PROXY_CIDRS (comma-separated CIDRs).
4. If nginx already restores the visitor into REMOTE_ADDR, leave the custom proxy variable unset. If PHP sees a local reverse proxy, trust only that proxy's exact address and configure it to **strip untrusted client headers and set verified Cloudflare headers itself**. Do not trust arbitrary X-Forwarded-For or configure broad private networks merely to make the diagnostic change.
5. Verify normal login, contact validation, shipping, cart availability, and payment recovery with safe test traffic. Use sandbox payments, not real charges. Confirm browsing, sitemap/feed routes, authenticated sync, and verified webhook processing remain unaffected.
6. After these checks, open Security → Overview, verify your password, confirm the checks on this server, and select **Start enforcing limits**. This enables rules set to Enforce and clears observation counters. The server command remains available when the admin panel is unavailable.

    php scripts/security-maintenance.php init
    php scripts/security-maintenance.php activate --verified

Application limits do not replace origin access controls or network-level protection. Keep PHP/web-server request size limits configured: application code cannot prevent PHP from parsing a multipart body before execution.

## Operations

    php scripts/security-maintenance.php check
    php scripts/security-maintenance.php prune
    php scripts/security-maintenance.php unblock 203.0.113.25
    php scripts/security-maintenance.php reset-rules
    php scripts/security-maintenance.php observe

Run prune periodically using the server scheduler. Each execution removes at most 1,000 expired rows per table; request-time cleanup also runs in bounded batches. Activity is retained for 30 days and capped at 100,000 entries. Repeated denials are grouped by IP, rule, outcome, and minute.

Unblock removes the selected IP's manual block and associated counters. Reset-rules restores built-in policies and clears all counters; neither erases activity. Security → Overview also offers **Return to observation** after password confirmation. That switch and the `observe` command both clear counters and record an audit event. Rules set to Observe never block requests, even with global enforcement on. Established admin sessions remain accessible while login is throttled.

Rules use burst capacity plus continuous refill, not fixed-window request counts. Default values live in SecurityStore::defaults() and appear on the Rules page. Editing a rule clears its counters. Manual blocks last 15 minutes, one hour, or 24 hours and exclude payment recovery and authenticated security management.

### Admin settings protection

Site configuration and email delivery settings share two budgets: a burst of 10 attempts per admin account and 30 per visitor IP, each refilling over 300 seconds. Account limits persist across sessions and connections; the IP budget also spans accounts. Both pages require an active administrator and valid CSRF token before consuming the budget. Invalid settings submissions consume a token; invalid CSRF submissions do not. Password confirmation on email settings continues to use the separate password-verification budgets.

Throttled saves return an HTML error with HTTP 429 and Retry-After before any settings are written. Limiter storage failures fail closed with 503. Reading settings and accessing the Security page remain available; manual IP blocks do not lock an authenticated administrator out of these controls. Successful site configuration saves record the administrator ID with a settings_changed event, without recording configuration values.

The Security overview displays the current sign-in, password verification, and settings policies, with effective enforcement or observation status. New policies appear automatically in Rules and Activity filters. Existing installations retain their activation state; this change does not activate production enforcement.

### Traffic watch and chat activity

Security → Overview shows site sessions from the last 15 minutes beside the preceding 15 minutes, grouped by country when location is available. A **Review burst** badge appears only when one location has at least 6 recent sessions from at least 3 distinct server-derived addresses and at least 3 times its preceding-window count. It is a review cue, not a country block. The table also shows sessions with bot signals, Tawk widget loads, widget opens, and chats started. A six-interval history covers the last 90 minutes so an operator can see whether a spike is new, persistent, or fading. Its final interval is incomplete until 15 minutes have passed. Follow **Review visitor sessions** for the underlying session details.

The widget events use Tawk's [onLoad, onChatMaximized, and onChatStarted callbacks](https://developer.tawk.to/jsapi/) and first-party analytics. They begin only after the updated footer and analytics JavaScript are deployed. Counts are browser-reported, deduplicated by site session, and can be lower than Tawk's visitor dashboard when analytics is blocked or a chat is opened directly. Site sessions are a broader signal: [Tawk counts visits to pages with its widget as visitors](https://help.tawk.to/article/understanding-visitor-monitoring-and-chat-sessions), even without a chat.

Location and Cloudflare bot headers are accepted only when the immediate connection is a pinned Cloudflare address or a specifically trusted proxy that supplies a verified visitor IP. Otherwise analytics uses the server's remote address and, when enabled, its IP-location lookup. A missing or unverified location is shown as unknown. Confirm proxy configuration and analytics storage before relying on a surge badge. Neither the new monitoring nor a country code changes enforcement or blocks visitors.

## Responses and recovery

- Throttled actions return 429 and an integer Retry-After, retaining each endpoint's existing response fields. Repeated denials do not extend a restriction.
- Storage failures return 503 for new logins, submissions, and costly actions. Telemetry is dropped with 204; established admin sessions and payment recovery retain their existing validation.
- Apple Pay retains server-side ownership, pricing, stock, idempotency, and 20-attempt/session/hour safeguards. Already-completed results do not consume the owned-attempt downstream-call budget.
- PayPal finalization preserves payment references in the current tab across reloads. Retry submits the same finalization request, never a new payment. Cart availability failures preserve cart contents.
- Legacy completion acquires SQLite's writer lock and rechecks completion inside the transaction before changing stock. Concurrent retries therefore cannot deduct the same order twice; provider verification remains a separate issue.
- Analytics honors cooldowns and caps its queue without feeding expected throttling into the error collector.

The existing legacy PayPal verification defect documented in docs/apple-pay.md remains unresolved. Rate limiting and recovery UI do not verify those payments.

## Validation status — 2026-09-30

- [x] Atomic limiter, proxy-address handling, restrictions, retention, and storage failure behavior tested locally.
- [x] Admin authorization, CSRF, password reauthentication, rules, blocks, and HTTP limits tested locally.
- [x] Payment mocks and client backoff/recovery tests passed without live charges.
- [x] Security overview, rules, restrictions, and filtered activity checked in the browser at desktop/mobile sizes and in both themes.
- [ ] Production storage, Cloudflare/nginx address resolution, deployment, and enforcement activation.

Production verification remains pending. Local evidence does not establish live email delivery, real wallet behavior, or production enforcement.

Local test commands:

    php tests/security-test.php
    python tests/security-http-test.py
    node tests/security-client-test.cjs
    php tests/applepay-test.php
    node tests/applepay-client-test.cjs
    php tests/catalog-test.php
    php tests/seo-discovery-test.php
    php tests/seo-redirect-test.php
    node tests/catalog-navigation-test.cjs
    php tests/growth-test.php
    node tests/growth-client-test.cjs

The HTTP fixture creates its own synthetic inventory and disables PHP mail. Its 67 assertions include concurrent legacy completion through three PHP workers against one SQLite database, with one stock deduction. Results are recorded in audit/security-local-http.json; it does not call production services. The September 30 browser verification additionally covered login, reauthentication, rule save, temporary block/unblock, and activity filters. No console errors were observed during those Security-page checks.

### Follow-up validation — 2026-10-01

- Passed 86 limiter assertions, 66 isolated HTTP assertions, and 11 client backoff/recovery assertions.
- Verified shared settings budgets, denial before configuration writes, active-admin authorization, CSRF behavior, successful-save audit events, and the shared password-verification budget across Security and Change Password.
- Inspected the updated overview at 1440px and 390px, including light and dark desktop views. At 390px the page had no horizontal overflow. The new settings rule remained disabled before reauthentication; no console errors were observed during that check.
- Production deployment, IP resolution checks, and activation remain pending.

### Dashboard enforcement switch — 2026-10-01

- Security → Overview now has a password-protected enforcement switch. Activation requires confirmation that private storage, visitor IP detection, and checkout recovery were checked on the target server; both directions clear counters and record the administrator in Activity.
- `php tests/security-test.php` passed 94 assertions. `python tests/security-enforcement-http-test.py` passed 20 isolated HTTP assertions, including 429 responses for repeated admin logins only while enforcement is on. The existing `python tests/security-http-test.py` passed 66 isolated HTTP assertions.
- The Overview control rendered in the local browser in dark mode. Password confirmation exposed the rollback button; at a mobile viewport, the page had no horizontal overflow. These checks use disposable local databases. Production activation and checks remain pending.

### Traffic watch validation — 2026-10-01

- `php tests/analytics-session-test.php` passed 19 assertions, including forged versus trusted proxy headers. `php tests/security-traffic-test.php` passed 7 synthetic burst and widget-count assertions. `node tests/security-client-test.cjs` passed 11 client assertions.
- Production Tawk callbacks, analytics storage, visitor location, and bot signals still require live verification after deployment.

### Traffic history follow-up — 2026-10-01

- The Security overview now shows six rolling 15-minute windows for site sessions, widget-loaded sessions, and bot signals. The current and previous columns match the same windows used for the burst cue.
- `php tests/security-traffic-test.php` passed 11 assertions, covering interval boundaries, widget-session deduplication, and excluding administrator sessions. The HTTP fixture now initializes the full analytics schema and passed 67 assertions, including the overview burst/history render.
- The history rendered in light and dark themes at desktop and mobile widths in an isolated browser fixture, with no page overflow. Production Tawk events and location accuracy remain unverified.
