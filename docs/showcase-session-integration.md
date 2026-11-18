# Session form lifecycle (dormant integration candidate)

Use ShowcaseSessionForms only with a dedicated server-owned session array while holding the PHP session lock. Initialize an empty bucket only on an admitted GET; a missing bucket on POST must reject, not create one. The module does not start sessions, open routes, or access globals.

GET, after feature enablement and rate admission: construct with explicit TTL (1..86400 seconds), capacity (1..20), and trusted integer clock; issue() yields form_id and csrf_token for ShowcaseForm::intake. Full pools reject new issuance rather than evicting a form with a potentially committed retry. Only issuance prunes expired entries.

POST: require an existing session bucket, call resolve($post['form_id'] ?? null), then pass returned csrf_token and form_id as ShowcaseRequest::submit expected tokens. The request boundary independently compares submitted CSRF. Never use user input as expected tokens. Preserve the entry unchanged on success/failure for exact repository retries until its original expiry. At expiry, reissue only via GET; database idempotency remains intact. Store neither submitted fields nor receipts/photos in the bucket.

Keep the session lock through token resolution and submission if logout/reset/identity changes must invalidate in-flight operations. Authentication/session regeneration, secure cookies, rate limits, admission, current consent policy, and exception-to-HTTP mapping remain controller responsibilities. Clear this dedicated pool on account/session identity changes. Clock must be a stable server clock; resolve rejects future-issued forms. TTL changes do not retroactively shorten existing issued expiries; explicitly clear pool if policy revokes outstanding forms.

43 synthetic unit checks and 16 real HTTP session checks supplement 709 unchanged baseline checks. HTTP uses two independent cookie jars and covers two tabs, cross-session rejection, exact expiry, retained retry lookup, and no POST allocation. Capacity reductions refuse new forms without revoking existing ones; original issued expiry remains in force. Production/controller integration remains separate.
