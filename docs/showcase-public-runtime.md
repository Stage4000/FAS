# Dormant public runtime composition

`ShowcasePublicRuntime::compose(config, enabled)` composes the existing gallery/photo controller from an existing private SQLite database. It requires exact explicit database/public_roots/consent_version/consent_hash configuration, no active session and literal true publication gate. No bootstrap, consent values, provisioning or enablement ships. The photo path is the fixed shipped `/showcase-photo.php` wrapper.

`ShowcasePrivateStorage::openReadOnly` reuses the same canonical-path/schema protection as private admin storage but opens SQLite with READONLY (no CREATE), also setting query_only. Its repository authorization always denies administrative operations. Driver readonly protects persistent data even if query_only is disabled accidentally. This is not an OS sandbox: trusted bootstrap paths and all served roots/aliases must still be configured correctly; SQLite WAL sidecars and host permissions need actual runtime verification. The general readwrite opener retains its current contract.

Tests cover real existing SQLite, read-only write rejection, unchanged private data, public projection, exact consent, withdrawal/revocation and active-session rejection. Existing transport test covers wrappers; this run does not claim actual TLS, browser, production, or hosted CI acceptance. Release/consent/retention/terms/provisioning holds remain.
