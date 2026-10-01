# Rate limiting and Security dashboard

The Security page is at /admin/security.php. Only active administrators can access it. Rules, temporary blocks, counter clearing, and restoring defaults require CSRF protection and password confirmation within the last ten minutes.

## Deployment

Deploy all security helpers, endpoint integrations, admin views, and checkout/analytics JavaScript together. Preserve the existing site configuration, inventory database, and SEO changes.

1. Set FAS_SECURITY_DB_PATH to an absolute SQLite filename outside every publicly served directory. The default is fas-private/security.sqlite beside the application directory. PHP and CLI maintenance must share this path and have write access. Use a local filesystem shared by all workers on the **single origin**; multiple origins need a shared limiter implementation first.
2. Run the initialization command below using the PHP worker's environment. New installations observe limits until explicit activation. Confirm the database and its directory cannot be downloaded. New directories/files use 0700/0600 on Unix; verify ownership and Windows ACLs as applicable. Existing parent permissions are not automatically changed.
3. Open Security → Overview from two different connections and compare detected visitor addresses with those connections. Forwarded headers are accepted only from the pinned [Cloudflare IP ranges](https://www.cloudflare.com/ips/) or FAS_TRUSTED_PROXY_CIDRS (comma-separated CIDRs).
4. If nginx already restores the visitor into REMOTE_ADDR, leave the custom proxy variable unset. If PHP sees a local reverse proxy, trust only that proxy's exact address and configure it to **strip untrusted client headers and set verified Cloudflare headers itself**. Do not trust arbitrary X-Forwarded-For or configure broad private networks merely to make the diagnostic change.
5. Verify normal login, contact validation, shipping, cart availability, and payment recovery with safe test traffic. Use sandbox payments, not real charges. Confirm browsing, sitemap/feed routes, authenticated sync, and verified webhook processing remain unaffected.
6. Run activation only after these checks. This enables the enforced defaults and clears observation counters.

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

Unblock removes the selected IP's manual block and associated counters. Reset-rules restores built-in policies and clears all counters; neither erases activity. Observe provides an emergency rollback for enforcement, with an audit event. Established admin sessions remain accessible while login is throttled.

Rules use burst capacity plus continuous refill, not fixed-window request counts. Default values live in SecurityStore::defaults() and appear on the Rules page. Editing a rule clears its counters. Manual blocks last 15 minutes, one hour, or 24 hours and exclude payment recovery and authenticated security management.

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

The HTTP fixture creates its own synthetic inventory and disables PHP mail. Its 52 assertions include concurrent legacy completion through three PHP workers against one SQLite database, with one stock deduction. Results are recorded in audit/security-local-http.json; it does not call production services. Browser verification additionally covered login, reauthentication, rule save, temporary block/unblock, and activity filters. No console errors were observed during those Security-page checks.
