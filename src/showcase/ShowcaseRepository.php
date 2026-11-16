<?php
declare(strict_types=1);

namespace FAS\Showcase;

/**
 * Inactive SQLite storage primitive. No application config, routes or public output.
 * The caller must provide a PRIVATE database and trusted authorization code.
 * Budgets cover retained submissions and derivative bytes, not SQLite disk usage.
 */
final class ShowcaseRepository
{
    private const SCHEMA = [
        'showcase_consent_versions' => "CREATE TABLE showcase_consent_versions (
            version TEXT PRIMARY KEY NOT NULL,
            text_sha256 TEXT NOT NULL CHECK(length(text_sha256) = 64),
            consent_text TEXT NOT NULL
        )",
        'showcase_submissions' => "CREATE TABLE showcase_submissions (
            id TEXT PRIMARY KEY NOT NULL CHECK(length(id) = 32),
            retry_key_sha256 TEXT UNIQUE NOT NULL CHECK(length(retry_key_sha256) = 64),
            payload_sha256 TEXT NOT NULL CHECK(length(payload_sha256) = 64),
            display_name TEXT NOT NULL,
            contact_email TEXT NOT NULL,
            caption TEXT NOT NULL,
            consent_version TEXT NOT NULL REFERENCES showcase_consent_versions(version),
            consent_text_sha256 TEXT NOT NULL CHECK(length(consent_text_sha256) = 64),
            accepted_at TEXT NOT NULL,
            photo_sha256 TEXT NOT NULL CHECK(length(photo_sha256) = 64),
            photo_bytes INTEGER NOT NULL CHECK(photo_bytes > 0 AND photo_bytes <= 8388608),
            photo_width INTEGER NOT NULL CHECK(photo_width > 0 AND photo_width <= 6000),
            photo_height INTEGER NOT NULL CHECK(photo_height > 0 AND photo_height <= 6000),
            photo_jpeg BLOB NOT NULL CHECK(typeof(photo_jpeg) = 'blob' AND length(photo_jpeg) = photo_bytes),
            status TEXT NOT NULL CHECK(status IN ('pending_review','approved','rejected','withdrawn')),
            version INTEGER NOT NULL CHECK(version > 0),
            CHECK(photo_width * photo_height <= 12000000)
        )",
        'showcase_moderation_audit' => "CREATE TABLE showcase_moderation_audit (
            submission_id TEXT NOT NULL REFERENCES showcase_submissions(id),
            version INTEGER NOT NULL CHECK(version > 1),
            actor_id INTEGER NOT NULL CHECK(actor_id > 0),
            from_status TEXT NOT NULL,
            to_status TEXT NOT NULL,
            photo_sha256 TEXT NOT NULL,
            consent_text_sha256 TEXT NOT NULL,
            reason TEXT NOT NULL,
            occurred_at TEXT NOT NULL,
            PRIMARY KEY(submission_id, version)
        )",
    ];

    private $db;
    private $authorize;
    private $maxSubmissions;
    private $maxPhotoBytes;

    /**
     * $authorize(int actorId, string action, array snapshot): bool is trusted code.
     * It must check current active-admin authority and must not mutate the shared
     * connection/transaction or perform external effects. It is not request input.
     */
    public function __construct(\PDO $db, callable $authorize, int $maxSubmissions, int $maxPhotoBytes)
    {
        self::checkConnection($db);
        if ($maxSubmissions < 1 || $maxSubmissions > 1000000 || $maxPhotoBytes < 1) {
            throw new \InvalidArgumentException('Explicit positive storage budgets are required.');
        }
        self::checkSchema($db);
        $this->db = $db;
        $this->authorize = $authorize;
        $this->maxSubmissions = $maxSubmissions;
        $this->maxPhotoBytes = $maxPhotoBytes;
    }

    /** Explicit, additive initialization only. Never called by the constructor. */
    public static function initializeSchema(\PDO $db): void
    {
        self::checkConnection($db);
        self::transaction($db, static function () use ($db): void {
            $present = $db->query("SELECT name FROM main.sqlite_master WHERE name IN
                ('showcase_consent_versions','showcase_submissions','showcase_moderation_audit')")->fetchAll(\PDO::FETCH_COLUMN);
            if ($present) {
                self::checkSchema($db); // Idempotent only for the exact complete schema.
                return;
            }
            foreach (self::SCHEMA as $sql) {
                $db->exec(preg_replace('/^CREATE TABLE /', 'CREATE TABLE main.', $sql));
            }
            self::checkSchema($db);
        });
    }

    /**
     * Atomically store normalized pixels, exact consent wording and private data.
     * Retry keys are opaque, globally unique server-issued identities for this
     * showcase database, never an email address. Return value contains no PII.
     */
    public function accept(ShowcaseSubmission $submission, string $consentText, string $retryKey): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $retryKey)) {
            throw new \InvalidArgumentException('A valid opaque retry identity is required.');
        }
        $record = $submission->privateRecord();
        if (!hash_equals($record['consent_text_sha256'], hash('sha256', $consentText))) {
            throw new \InvalidArgumentException('Consent wording does not match the accepted version.');
        }
        $photo = $submission->photo();
        $metadata = $photo->metadata();
        if ($metadata !== $record['photo']) {
            throw new \UnexpectedValueException('The derivative identity does not match the submission.');
        }
        $payloadHash = hash('sha256', json_encode([$record, $consentText], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $retryKey);
        self::checkConnection($this->db);
        return self::transaction($this->db, function () use ($record, $metadata, $photo, $consentText, $payloadHash, $keyHash): array {
            self::checkSchema($this->db);
            $find = $this->db->prepare('SELECT id,status,version,accepted_at,payload_sha256 FROM main.showcase_submissions WHERE retry_key_sha256=?');
            $find->execute([$keyHash]);
            $existing = $find->fetch(\PDO::FETCH_ASSOC);
            if ($existing) {
                if (!hash_equals($existing['payload_sha256'], $payloadHash)) {
                    throw new \DomainException('Retry identity conflicts with an existing submission.');
                }
                $saved = $this->find($existing['id']);
                $this->readPhoto($saved);
                return self::receipt($saved);
            }
            $usage = $this->db->query('SELECT COUNT(*) AS entries, COALESCE(SUM(photo_bytes),0) AS bytes FROM main.showcase_submissions')->fetch(\PDO::FETCH_ASSOC);
            if (!is_int($usage['entries']) || !is_int($usage['bytes']) || $usage['bytes'] < 0
                || $usage['entries'] >= $this->maxSubmissions || $usage['bytes'] > $this->maxPhotoBytes
                || $metadata['bytes'] > $this->maxPhotoBytes - $usage['bytes']) {
                throw new \OverflowException('The configured showcase storage budget is full.');
            }
            $consent = $this->db->prepare('SELECT text_sha256,consent_text FROM main.showcase_consent_versions WHERE version=?');
            $consent->execute([$record['consent_version']]);
            $oldConsent = $consent->fetch(\PDO::FETCH_ASSOC);
            if ($oldConsent && ($oldConsent['consent_text'] !== $consentText || !hash_equals($oldConsent['text_sha256'], $record['consent_text_sha256']))) {
                throw new \DomainException('A consent version cannot be reused with different wording.');
            }
            if (!$oldConsent) {
                $this->db->prepare('INSERT INTO main.showcase_consent_versions(version,text_sha256,consent_text) VALUES(?,?,?)')
                    ->execute([$record['consent_version'], $record['consent_text_sha256'], $consentText]);
            }
            $id = bin2hex(random_bytes(16));
            $acceptedAt = gmdate('Y-m-d\TH:i:s\Z');
            $insert = $this->db->prepare("INSERT INTO main.showcase_submissions
                (id,retry_key_sha256,payload_sha256,display_name,contact_email,caption,consent_version,consent_text_sha256,
                 accepted_at,photo_sha256,photo_bytes,photo_width,photo_height,photo_jpeg,status,version)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending_review',1)");
            $values = [$id, $keyHash, $payloadHash, $record['display_name'], $record['contact_email'], $record['caption'],
                $record['consent_version'], $record['consent_text_sha256'], $acceptedAt, $metadata['sha256'],
                $metadata['bytes'], $metadata['width'], $metadata['height']];
            foreach ($values as $i => $value) {
                $insert->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $insert->bindValue(14, $photo->bytes(), \PDO::PARAM_LOB);
            $insert->execute();
            return ['id' => $id, 'status' => 'pending_review', 'version' => 1, 'accepted_at' => $acceptedAt];
        });
    }

    /** Private admin review data, not a public projection or serving API. */
    public function readForModeration(string $id, int $actorId): array
    {
        self::checkId($id, $actorId);
        self::checkConnection($this->db);
        self::checkSchema($this->db);
        $row = $this->find($id);
        $this->requireAuthorization($actorId, 'review', $row);
        $row['photo_jpeg'] = $this->readPhoto($row);
        return $row;
    }

    /** Approval is a review state only. No public serving or incentive is enabled. */
    public function moderate(string $id, int $actorId, int $expectedVersion, string $photoHash, string $consentHash, string $toStatus, string $reason): array
    {
        self::checkId($id, $actorId);
        if ($expectedVersion < 1 || $expectedVersion === PHP_INT_MAX
            || !preg_match('/\A[a-f0-9]{64}\z/D', $photoHash) || !preg_match('/\A[a-f0-9]{64}\z/D', $consentHash)
            || !in_array($toStatus, ['approved', 'rejected', 'withdrawn'], true)
            || trim($reason) === '' || strlen($reason) > 2000 || preg_match('//u', $reason) !== 1
            || preg_match('/[\x00-\x1F\x7F]/u', $reason)) {
            throw new \InvalidArgumentException('A valid review snapshot, decision and reason are required.');
        }
        self::checkConnection($this->db);
        return self::transaction($this->db, function () use ($id, $actorId, $expectedVersion, $photoHash, $consentHash, $toStatus, $reason): array {
            self::checkSchema($this->db);
            $row = $this->find($id, $toStatus !== 'withdrawn');
            $this->requireAuthorization($actorId, 'moderate', $row);
            if ($toStatus !== 'withdrawn') {
                $this->readPhoto($row); // Verify actual reviewed pixels while holding the writer lock.
            }
            if ($row['version'] !== $expectedVersion || !hash_equals($row['photo_sha256'], $photoHash)
                || !hash_equals($row['consent_text_sha256'], $consentHash)) {
                throw new \DomainException('The reviewed submission has changed. Reload it before deciding.');
            }
            $allowed = ['pending_review' => ['approved','rejected','withdrawn'], 'approved' => ['withdrawn'], 'rejected' => ['withdrawn'], 'withdrawn' => []];
            if (!in_array($toStatus, $allowed[$row['status']] ?? [], true)) {
                throw new \DomainException('That review-state transition is not allowed.');
            }
            $update = $this->db->prepare('UPDATE main.showcase_submissions SET status=?,version=version+1 WHERE id=? AND version=? AND status=? AND photo_sha256=? AND consent_text_sha256=?');
            $update->execute([$toStatus, $id, $expectedVersion, $row['status'], $photoHash, $consentHash]);
            if ($update->rowCount() !== 1) {
                throw new \DomainException('The reviewed submission changed during the decision.');
            }
            $this->db->prepare('INSERT INTO main.showcase_moderation_audit(submission_id,version,actor_id,from_status,to_status,photo_sha256,consent_text_sha256,reason,occurred_at) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $expectedVersion + 1, $actorId, $row['status'], $toStatus, $photoHash, $consentHash, trim($reason), gmdate('Y-m-d\TH:i:s\Z')]);
            $row['version']++;
            $row['status'] = $toStatus;
            return self::receipt($row);
        });
    }

    /** Private bounded queue. Authorization must explicitly support the list action. */
    public function moderationQueue(int $actorId, string $status = 'pending_review', int $limit = 20, ?array $before = null): array
    {
        if ($actorId < 1 || !in_array($status, ['pending_review','approved','rejected','withdrawn'], true) || $limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException('A valid administrator, review state and page limit are required.');
        }
        if ($before !== null) {
            $keys = array_keys($before); sort($keys);
            if ($keys !== ['accepted_at','id'] || !is_string($before['id']) || !is_string($before['accepted_at'])
                || !preg_match('/\A[a-f0-9]{32}\z/D', $before['id'])
                || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $before['accepted_at'])) {
                throw new \InvalidArgumentException('A valid review-queue cursor is required.');
            }
        }
        self::checkConnection($this->db);
        self::checkSchema($this->db);
        if (($this->authorize)($actorId, 'list', []) !== true) {
            throw new \RuntimeException('Current administrator authorization is required.');
        }
        $where = $before === null ? '' : ' AND (accepted_at < ? OR (accepted_at = ? AND id < ?))';
        $query = $this->db->prepare('SELECT id,display_name,caption,accepted_at,status,version FROM main.showcase_submissions WHERE status=?' . $where . ' ORDER BY accepted_at DESC,id DESC LIMIT ?');
        $query->bindValue(1, $status, \PDO::PARAM_STR);
        if ($before !== null) {
            $query->bindValue(2, $before['accepted_at'], \PDO::PARAM_STR);
            $query->bindValue(3, $before['accepted_at'], \PDO::PARAM_STR);
            $query->bindValue(4, $before['id'], \PDO::PARAM_STR);
        }
        $query->bindValue($before === null ? 2 : 5, $limit, \PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function moderationAudit(string $id, int $actorId): array
    {
        self::checkId($id, $actorId);
        self::checkConnection($this->db);
        self::checkSchema($this->db);
        $row = $this->find($id, false);
        $this->requireAuthorization($actorId, 'review', $row);
        $query = $this->db->prepare('SELECT version,actor_id,from_status,to_status,reason,occurred_at FROM main.showcase_moderation_audit WHERE submission_id=? ORDER BY version LIMIT 3');
        $query->execute([$id]);
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Dormant, bounded public projection. Approval alone is never publication.
     * The required trusted policy(snapshot): bool must separately enforce feature
     * enablement and the current approved public consent policy. It receives no PII
     * and must have no side effects. There is no default allow/public route.
     * Limit bounds examined candidates; disallowed candidates can leave a short list.
     */
    public function approvedGallery(callable $publicationPolicy, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 50) { throw new \InvalidArgumentException('A gallery limit from 1 to 50 is required.'); }
        self::checkConnection($this->db);
        self::checkSchema($this->db);
        $query = $this->db->prepare("SELECT id FROM main.showcase_submissions WHERE status='approved' ORDER BY accepted_at DESC,id DESC LIMIT ?");
        $query->bindValue(1, $limit, \PDO::PARAM_INT);
        $query->execute();
        $ids = $query->fetchAll(\PDO::FETCH_COLUMN);
        $items = [];
        foreach ($ids as $id) {
            $row = $this->find($id);
            if ($row['status'] !== 'approved' || !self::publicationAllowed($publicationPolicy, $row)) { continue; }
            // Recheck the exact public snapshot after the policy callback. No email,
            // consent wording, moderation reason or private storage metadata escapes.
            $current = $this->publicSnapshot($row, false);
            if ($current === null) { continue; }
            $items[] = ['id' => $current['id'], 'display_name' => $current['display_name'], 'caption' => $current['caption']];
        }
        return $items;
    }

    /** A response value only; caller emits it after successful authorization. */
    public function approvedPhoto(string $id, callable $publicationPolicy): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) { throw new \InvalidArgumentException('A valid photo identity is required.'); }
        self::checkConnection($this->db);
        self::checkSchema($this->db);
        $row = $this->find($id);
        if ($row['status'] !== 'approved' || !self::publicationAllowed($publicationPolicy, $row)) {
            throw new \OutOfBoundsException('Photo not available.');
        }
        $current = $this->publicSnapshot($row, true);
        if ($current === null) { throw new \OutOfBoundsException('Photo not available.'); }
        $bytes = $current['photo_jpeg'];
        if (!is_string($bytes) || strlen($bytes) !== $current['photo_bytes'] || !hash_equals($current['photo_sha256'], hash('sha256', $bytes))) {
            throw new \UnexpectedValueException('Stored derivative integrity check failed.');
        }
        return ['headers' => ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache', 'Content-Length' => (string)strlen($bytes)], 'body' => $bytes];
    }

    private static function publicationAllowed(callable $policy, array $row): bool
    {
        return $policy(['id' => $row['id'], 'status' => $row['status'], 'version' => $row['version'],
            'photo_sha256' => $row['photo_sha256'], 'consent_version' => $row['consent_version'],
            'consent_text_sha256' => $row['consent_text_sha256']]) === true;
    }

    /** A single final read verifies the approval state and consent at response creation. */
    private function publicSnapshot(array $row, bool $withPhoto): ?array
    {
        $columns = $withPhoto ? 's.photo_jpeg,s.photo_bytes,s.photo_sha256' : 's.id,s.display_name,s.caption';
        $query = $this->db->prepare('SELECT ' . $columns . " FROM main.showcase_submissions s JOIN main.showcase_consent_versions c ON c.version=s.consent_version
            WHERE s.id=? AND s.status='approved' AND s.version=? AND s.photo_sha256=? AND s.consent_text_sha256=? AND s.consent_version=? AND c.text_sha256=? AND c.consent_text=?");
        $query->execute([$row['id'], $row['version'], $row['photo_sha256'], $row['consent_text_sha256'], $row['consent_version'], $row['consent_text_sha256'], $row['consent_text']]);
        $current = $query->fetch(\PDO::FETCH_ASSOC);
        return $current === false ? null : $current;
    }

    private function find(string $id, bool $verifyConsent = true): array
    {
        $select = $this->db->prepare('SELECT s.id,s.display_name,s.contact_email,s.caption,s.consent_version,s.consent_text_sha256,
            c.consent_text,s.accepted_at,s.photo_sha256,s.photo_bytes,s.photo_width,s.photo_height,s.status,s.version
            FROM main.showcase_submissions s JOIN main.showcase_consent_versions c ON c.version=s.consent_version WHERE s.id=?');
        $select->execute([$id]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { throw new \OutOfBoundsException('Submission not found.'); }
        if ($verifyConsent && !hash_equals($row['consent_text_sha256'], hash('sha256', $row['consent_text']))) {
            throw new \UnexpectedValueException('Stored consent integrity check failed.');
        }
        return $row;
    }

    private function requireAuthorization(int $actorId, string $action, array $row): void
    {
        $snapshot = ['id' => $row['id'], 'status' => $row['status'], 'version' => $row['version'],
            'photo_sha256' => $row['photo_sha256'], 'consent_text_sha256' => $row['consent_text_sha256']];
        if (($this->authorize)($actorId, $action, $snapshot) !== true) {
            throw new \RuntimeException('Current administrator authorization is required.');
        }
    }

    private function readPhoto(array $row): string
    {
        $select = $this->db->prepare('SELECT photo_jpeg FROM main.showcase_submissions WHERE id=?');
        $select->execute([$row['id']]);
        $bytes = $select->fetchColumn();
        if (!is_string($bytes) || strlen($bytes) !== $row['photo_bytes'] || !hash_equals($row['photo_sha256'], hash('sha256', $bytes))) {
            throw new \UnexpectedValueException('Stored derivative integrity check failed.');
        }
        return $bytes;
    }

    private static function receipt(array $row): array
    {
        return ['id' => $row['id'], 'status' => $row['status'], 'version' => $row['version'], 'accepted_at' => $row['accepted_at']];
    }

    private static function checkId(string $id, int $actorId): void
    {
        if ($actorId < 1 || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw new \InvalidArgumentException('A valid submission and server-side actor are required.');
        }
    }

    private static function checkConnection(\PDO $db): void
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || $db->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION
            || $db->getAttribute(\PDO::ATTR_STRINGIFY_FETCHES) !== false
            || $db->getAttribute(\PDO::ATTR_CASE) !== \PDO::CASE_NATURAL
            || $db->getAttribute(\PDO::ATTR_ORACLE_NULLS) !== \PDO::NULL_NATURAL
            || (int)$db->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new \LogicException('SQLite, native fetch types, natural names/nulls, exception mode and foreign keys must already be configured.');
        }
        if ($db->inTransaction()) {
            throw new \LogicException('Showcase operations require ownership of their transaction.');
        }
        $journal = strtolower((string)$db->query('PRAGMA main.journal_mode')->fetchColumn());
        $persistent = false;
        foreach ($db->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC) as $database) {
            if ($database['name'] === 'main') { $persistent = $database['file'] !== ''; }
        }
        if ($journal === 'off' || !in_array($journal, ['delete','truncate','persist','memory','wal'], true)
            || ($persistent && ($journal === 'memory' || (int)$db->query('PRAGMA main.synchronous')->fetchColumn() < 2))) {
            throw new \LogicException('Rollback-capable journaling and durable persistent-database synchronization are required.');
        }
    }

    private static function checkSchema(\PDO $db): void
    {
        foreach (self::SCHEMA as $name => $sql) {
            $query = $db->prepare('SELECT type,sql FROM main.sqlite_master WHERE name=?');
            $query->execute([$name]);
            $actual = $query->fetch(\PDO::FETCH_ASSOC);
            if (!$actual || $actual['type'] !== 'table' || trim($actual['sql']) !== trim($sql)) {
                throw new \LogicException('The complete expected showcase schema is required.');
            }
        }
        foreach (['main', 'temp'] as $schema) {
            $triggers = $db->query("SELECT COUNT(*) FROM " . $schema . ".sqlite_master WHERE type='trigger' AND tbl_name COLLATE NOCASE IN
                ('showcase_consent_versions','showcase_submissions','showcase_moderation_audit')")->fetchColumn();
            if ((int)$triggers !== 0) {
                throw new \LogicException('Unexpected showcase schema triggers are not supported.');
            }
        }
    }

    private static function transaction(\PDO $db, callable $operation)
    {
        $owned = false;
        try {
            $db->exec('BEGIN IMMEDIATE');
            $owned = true;
            $result = $operation();
            $db->exec('COMMIT');
            $owned = false;
            return $result;
        } catch (\Throwable $error) {
            if ($owned) {
                try { $db->exec('ROLLBACK'); } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException('Showcase transaction failed and rollback could not be verified.', 0, $error);
                }
            }
            throw $error;
        }
    }
}
