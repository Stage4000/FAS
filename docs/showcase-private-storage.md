# Private SQLite factory (dormant)

ShowcasePrivateStorage::open requires an existing canonical absolute local database path and a nonempty explicit list of ALL trusted public document/alias roots. Public roots must already exist and resolve as directories. Windows accepts drive-letter absolute paths only, not drive-relative, root-relative, UNC or URI paths. Database symlinks, linked ancestors, traversal/alias spelling and files under resolved public roots fail closed. Paths are server configuration, never request input.

PDO opens READWRITE without CREATE and fails closed if the platform lacks flags. There is no schema initialization, migration, chmod or journal-mode change. Foreign keys, FULL synchronization and bounded busy timeout are connection settings. Authoritative exact schema validation remains the existing repository constructor. Pre-provisioning the database is intentionally a separate explicit operation.

This is a placement check, not a filesystem security boundary: provisioning must ensure private ownership/ACLs on the file, journal/WAL/SHM siblings and every parent directory, and prevent untrusted concurrent replacement or hardlinks. Windows junction detection relies on canonical resolution plus link checks; this does not audit reparse-point ACLs. Existing hot-journal recovery may be performed by SQLite while opening an existing database; no promise is made against SQLite recovery effects. Do not use on untrusted DB files or live files for tests.

27 synthetic path/schema checks passed, including successful OS symlink creation and rejection. A removed public-root containment predicate was detected by the regression suite. OS permission-denial is not claimed tested on this Windows executor. No production file was opened. Full integration/deployment remains held.

