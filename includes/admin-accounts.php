<?php
declare(strict_types=1);

/** Additive SQLite tables; existing admin accounts and passwords are preserved. */
function fas_admin_accounts_init(PDO $db): void
{
    $db->exec('PRAGMA busy_timeout=3000');
    $db->exec("CREATE TABLE IF NOT EXISTS admin_account_security (
        admin_id INTEGER PRIMARY KEY REFERENCES admin_users(id), session_version INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS admin_account_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER NOT NULL, target_id INTEGER NOT NULL,
        action TEXT NOT NULL, occurred_at TEXT NOT NULL DEFAULT (datetime('now'))
    );");
}

function fas_admin_account(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT u.*, COALESCE(s.session_version,1) AS session_version
        FROM admin_users u LEFT JOIN admin_account_security s ON s.admin_id=u.id WHERE u.id=?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function fas_admin_session_token(array $account): string
{
    return hash('sha256', $account['id'].'|'.$account['password_hash'].'|'.$account['session_version']);
}

function fas_admin_password_valid($password): bool
{
    // PASSWORD_DEFAULT currently uses bcrypt, which truncates after 72 bytes.
    return is_string($password) && strlen($password) >= 12 && strlen($password) <= 72 && strpos($password, "\0") === false;
}

function fas_admin_revoke_sessions(PDO $db, int $id): void
{
    $stmt = $db->prepare('INSERT INTO admin_account_security(admin_id,session_version) VALUES(?,2)
        ON CONFLICT(admin_id) DO UPDATE SET session_version=session_version+1');
    $stmt->execute([$id]);
}

/** Serialize authorization, duplicate checks, last-admin checks, writes and audit. */
function fas_admin_account_change(PDO $db, int $actorId, string $sessionToken, string $action, array $data): int
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $actor = fas_admin_account($db, $actorId);
        if (!$actor || !$actor['is_active'] || $actor['role'] !== 'admin'
            || !hash_equals(fas_admin_session_token($actor), $sessionToken)) {
            throw new InvalidArgumentException('Your access changed. Sign in again before managing administrators.');
        }
        if (!in_array($action, ['create','update','reset_password','deactivate','activate'], true)) {
            throw new InvalidArgumentException('Unknown administrator action.');
        }
        $id = $data['id'] ?? '';
        if ($action !== 'create' && (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id < 1)) {
            throw new InvalidArgumentException('Choose a valid administrator.');
        }
        $targetId = $action === 'create' ? 0 : (int)$id;
        $target = $targetId ? fas_admin_account($db, $targetId) : null;
        if ($action !== 'create' && (!$target || $target['role'] !== 'admin')) {
            throw new InvalidArgumentException('Administrator not found.');
        }
        if (in_array($action, ['create','update'], true)) {
            $username = is_string($data['username'] ?? null) ? trim($data['username']) : '';
            $email = is_string($data['email'] ?? null) ? trim($data['email']) : '';
            $name = is_string($data['full_name'] ?? null) ? trim($data['full_name']) : '';
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{2,63}\z/', $username)) {
                throw new InvalidArgumentException('Use 3–64 letters, numbers, dots, underscores or hyphens for the username, starting with a letter or number.');
            }
            if (strlen($email)>254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($name)>100) {
                throw new InvalidArgumentException('Enter a valid email address and a name up to 100 characters.');
            }
            $stmt = $db->prepare('SELECT id FROM admin_users WHERE id<>? AND (lower(username)=lower(?) OR lower(email)=lower(?)) LIMIT 1');
            $stmt->execute([$targetId,$username,$email]);
            if ($stmt->fetchColumn()) throw new InvalidArgumentException('That username or email address is already in use.');
        }
        if (in_array($action, ['create','reset_password'], true)) {
            $password = $data['new_password'] ?? null;
            if (!fas_admin_password_valid($password)) throw new InvalidArgumentException('Use a password between 12 and 72 bytes.');
            if ($password !== ($data['confirm_password'] ?? null)) throw new InvalidArgumentException('The passwords do not match.');
            $hash = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($action === 'create') {
            $stmt = $db->prepare("INSERT INTO admin_users(username,email,full_name,password_hash,role,is_active) VALUES(?,?,?,?,'admin',1)");
            $stmt->execute([$username,$email,$name,$hash]);
            $targetId = (int)$db->lastInsertId();
        } elseif ($action === 'update') {
            $stmt = $db->prepare("UPDATE admin_users SET username=?,email=?,full_name=?,updated_at=datetime('now') WHERE id=?");
            $stmt->execute([$username,$email,$name,$targetId]);
        } elseif ($action === 'reset_password') {
            if ($targetId === $actorId) throw new InvalidArgumentException('Use Change Password to change your own password.');
            $stmt = $db->prepare("UPDATE admin_users SET password_hash=?,updated_at=datetime('now') WHERE id=?");
            $stmt->execute([$hash,$targetId]);
            fas_admin_revoke_sessions($db,$targetId);
        } else {
            if ($action === 'deactivate') {
                $count = (int)$db->query("SELECT COUNT(*) FROM admin_users WHERE is_active=1 AND role='admin'")->fetchColumn();
                if ($target['is_active'] && $count <= 1) throw new InvalidArgumentException('At least one active administrator must remain.');
                if ($targetId === $actorId) throw new InvalidArgumentException('You cannot deactivate your own account.');
            }
            $stmt = $db->prepare("UPDATE admin_users SET is_active=?,updated_at=datetime('now') WHERE id=?");
            $stmt->execute([$action === 'activate' ? 1 : 0,$targetId]);
            // Reactivation must never restore a session issued before deactivation.
            fas_admin_revoke_sessions($db,$targetId);
        }
        $stmt = $db->prepare('INSERT INTO admin_account_events(actor_id,target_id,action) VALUES(?,?,?)');
        $stmt->execute([$actorId,$targetId,$action]);
        $db->exec('COMMIT');
        return $targetId;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}
