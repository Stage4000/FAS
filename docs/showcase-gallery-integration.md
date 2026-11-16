# Dormant moderation views and approved-only gallery projection

This is the next **local source candidate**, layered on the independently reviewed
request/form checkpoint. It is not a live feature or a deployed controller. No
production schema, routes, configuration, email or coupon system is changed.
It supersedes the earlier integration note only for the read-model/view progress
and verification described here; that note's controller and release gates remain.

## Added capability

`ShowcaseRepository` now provides:

- `moderationQueue(actorId, status, limit, before)`: current administrator-only
  review list with explicit `list` authorization, state filtering, a 1–50-row limit
  and an optional strict `accepted_at`/`id` keyset cursor. Newer inserts do not shift
  an offset underneath older pages. Contact email and binary photos are excluded.
- `moderationAudit(id, actorId)`: current authorized review of the bounded decision
  history. The existing state machine allows at most two decisions per submission;
  the read is capped at three rows. A future expanded state machine must revisit
  this bound rather than silently hiding older decisions.
- `approvedGallery(publicationPolicy, limit)`: a bounded public projection of only
  approved candidates which also pass a required explicit server publication
  policy. The policy must enforce feature enablement and the approved public
  consent version/hash. The only output fields are ID, display name and caption.
  It examines at most `limit` candidates, so a policy-filtered page can be short.
  The public gallery currently shows this bounded newest selection, not an archive
  pagination/search system.
- `approvedPhoto(id, publicationPolicy)`: the corresponding policy-gated normalized
  JPEG response value. It verifies stored byte/hash integrity and returns fixed
  JPEG MIME, `nosniff`, private/no-store, no-cache and exact length headers.

The publication callback is trusted server code, not a request field or callback
selected by the client. It gets identity/state/consent metadata, never email,
caption, display name, raw image or the full consent text. Only literal `true`
allows publication. Exceptions and truthy substitutes fail closed. Approval alone
does **not** enable either public method. No default-allow callback or live public
route is installed.

Each public response rechecks the same version, photo identity, consent identity,
consent text and approved state in a final database read after the policy callback.
Withdrawal is absent from the next listing and subsequent image requests. No web
system can retract bytes already received or an in-flight response created before
withdrawal; do not claim otherwise. Controllers and any proxy/CDN must respect the
no-store policy. Do not add conditional/long-lived caching without a separately
reviewed revocation design.

`ShowcaseViews` renders escaped fragments for a private queue with state and older
page navigation, private detail/permission review with exact snapshot and CSRF
fields, bounded audit history, and a public figure gallery. It allows only valid
review-state actions; withdrawn records have no decision form. Public rendering
requires exactly the public projection fields and rejects a private-row mixup.
No browser-side JavaScript, global CSS, shared asset or new dependency is added.

## Required application wiring

The application must still connect these dormant pieces to its current framework,
page shell and validated session/account authority. In particular:

1. Extend the trusted repository authorization callback to recognize `list` in
   addition to `review`/`moderate`, checking the current active administrator role
   and session token on every call. Do not infer authority from an integer actor
   ID or cache a prior successful login. Private detail, audit, image and queue
   endpoints all require the same current authority.
2. For a queue cursor, pass only the validated last row's timestamp and ID. All
   cursor/query values are untrusted and still undergo repository validation.
   The rendered older link may lead to an empty last page; state navigation remains
   available. Do not put private contact or session tokens in URLs.
3. Serve private review images only through an authenticated handler calling
   `readForModeration`; never expose a filesystem or public upload-directory path.
   The fixture's private-image path demonstrates the boundary but is not a
   production route. Use private/no-store, fixed JPEG MIME and nosniff headers.
4. Emit gallery responses only from an explicitly enabled server policy using the
   owner-reviewed consent/privacy contract. Supply `approvedGallery()` results,
   not moderation rows, to the gallery view. Map unavailability to a generic 404
   without exposing status, private contact, paths or raw exceptions.
5. After the photo method returns, emit exactly its safe headers and bytes. Apply
   private/no-store headers to the gallery HTML as well so withdrawal is not masked
   by a cached page. Review any proxy/CDN behavior before enabling it.
6. All displayed text is escaped, but this is still a fragment, not a styled,
   complete document. Actual mobile/desktop, keyboard, loading/error recovery,
   accessibility and novice browser workflows remain unverified. In particular,
   integrate safe fixed error pages for failed or stale moderation rather than
   exposing exception messages.

## Verification

Run the earlier three PHP suites, the new gallery suite, the real HTTP fixture and
the eleven negative controls from this source root:

```text
php tests/showcase-boundary-test.php
php tests/showcase-repository-test.php
php tests/showcase-request-test.php
php tests/showcase-gallery-test.php
python tests/showcase-http-test.py
python tests/showcase-gallery-guards.py
```

Current counts: 193 foundation/boundary + 206 persistence + 153 request/form + 98
gallery/view + 59 real HTTP = **709 unique assertions**, plus eleven isolated
negative controls. The guard suite deliberately changes only temporary copies to
prove detection of missing authorization, truthy publication, email leakage,
cacheable photos, missing queue filters, unsafe escaping and unapproved serving. Four further controls remove the consent-version or catalog-hash pin independently on both gallery and photo responses.
It verifies original bytes are unchanged and reruns the positive suite afterward.
Repeated positive runs are not additional unique assertions.

The loopback HTTP fixture additionally exercises all new private/public surfaces,
revoked access, approval with publication disabled, enabled exact derivative
serving, and withdrawal from both listing and subsequent image requests. All data
is synthetic; no browser or external service is used. PHP 8.5.5 is the tested
runtime. Production PHP/codecs, exact-source repository CI and actual browser
acceptance remain separate gates. Independent review of the corrected local increment passed: the reviewer independently reran all 709 assertions, 11 mutation controls and 12 PHP lints and found no actionable regression. This does not clear the application or production release gates.

## Remaining feature work and release gates

Actual application controller/session/rate/resource wiring and deliberately
provisioned private storage are still required. The one-per-email 5% coupon and
monthly draw are not implemented or issued. Owner consent/privacy/retention and
withdrawal decisions, coupon timing/eligibility/expiry/usage/stacking, final intake
URL and prize/entrant rules remain open. The branded QR must target that verified
live URL and pass print/phone checks. Nothing here retries a rejected repository
upload or authorizes publishing, deployment or customer communication.
