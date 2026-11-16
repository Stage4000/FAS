# Dormant intake controller

ShowcaseIntakeController composes reviewed session forms, request validation and rendering without routes, sessions or global reads. Supply a locked dedicated session bucket and native server/post/files values. On GET issue only after strict enabled and admission callbacks; on POST resolve only and preserve original form identity. Request-level gate must independently enforce resource admission. No production controller wiring, authentication, rate limiting, policies, emails or coupons are provided.

The request object's immutable consentText/consentVersion accessors are the sole consent source. Controller binds each displayed CSRF token to current consent version and wording via HMAC keyed by the stored random session secret. Changed consent causes old POST rejection rather than silently accepting new terms. Form identity remains stable for unchanged-policy exact retries. Nothing personal enters the session pool.

Emit returned status, fixed headers and body only after handle returns. Generic errors contain no submitted fields or internal exception messages. Error pages deliberately do not re-render or echo data: browser Back retains the original form; expiry/conflict calls for reload. Domain conflicts are generic because both retry mismatch and consent-catalog conflicts share that type. Request failures never render a committed receipt. Full pool or unexpected operational errors return503 without token allocation.

This is a framework-free candidate only. Real HTTP fixture tests use loopback, ephemeral key, isolated database/session/upload storage and synthetic data; never deploy tests. Release privacy, session locking, feature enablement, resource admission and production acceptance gates remain unchanged.
