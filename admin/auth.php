<?php
/**
 * Admin Authentication System
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/admin-accounts.php';

// Apply the site-configured timezone as early as possible
require_once __DIR__ . '/../src/utils/Timezone.php';
\FAS\Utils\Timezone::apply();

class AdminAuth
{
    private const ANALYTICS_ADMIN_COOKIE = 'fas_admin_analytics';
    private const ANALYTICS_ADMIN_COOKIE_TTL = 2592000;

    private $db;
    public string $lastError = '';
    
    public function __construct($db = null)
    {
        if ($db) {
            $this->db = $db;
        } else {
            require_once __DIR__ . '/../src/config/Database.php';
            $this->db = \FAS\Config\Database::getInstance()->getConnection();
        }
        fas_admin_accounts_init($this->db);
    }
    
    /**
     * Check if admin is logged in
     */
    public function isLoggedIn()
    {
        if (($_SESSION['admin_logged_in'] ?? false) !== true) return false;
        $admin = fas_admin_account($this->db, (int)($_SESSION['admin_id'] ?? 0));
        $token = $_SESSION['admin_session_token'] ?? '';
        if (!$admin || !$admin['is_active'] || $admin['role'] !== 'admin' || !is_string($token)
            || !hash_equals(fas_admin_session_token($admin), $token)) {
            unset($_SESSION['security_reauth_at']);
            return false;
        }
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_email'] = $admin['email'];
        return true;
    }
    
    /**
     * Login admin
     */
    public function login($username, $password)
    {
        $this->lastError = '';
        $username = is_string($username) && strlen($username)<=255 ? $username : '';
        $password = is_string($password) ? $password : '';
        $ip = fas_security_ip()['ip'];
        $limit = fas_security_check(['login_ip'=>$ip,'login_pair'=>$ip.'|'.$username,'login_account'=>$username]);
        if (!$limit['allowed']) {
            fas_security_headers($limit);
            $this->lastError = fas_security_message($limit);
            return false;
        }
        $stmt = $this->db->prepare("SELECT u.*, COALESCE(s.session_version,1) AS session_version FROM admin_users u
            LEFT JOIN admin_account_security s ON s.admin_id=u.id WHERE username = ? AND is_active = 1 AND role='admin'");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $valid = strlen($password) <= 4096 && password_verify($password, $admin['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if ($admin && $valid) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_session_token'] = fas_admin_session_token($admin);
            $this->issueAnalyticsAdminCookie();
            
            // Update last login
            $stmt = $this->db->prepare("UPDATE admin_users SET last_login = datetime('now') WHERE id = ?");
            $stmt->execute([$admin['id']]);
            unset($_SESSION['security_reauth_at']);
            fas_security_event('login', 'login_success', (int)$admin['id']);
            
            return true;
        }
        
        fas_security_event('login', 'login_failed');
        return false;
    }

    /**
     * Logout admin
     */
    public function logout()
    {
        $this->clearAnalyticsAdminCookie();
        $_SESSION = [];
        session_destroy();
    }
    
    /**
     * Change password
     */
    public function changePassword($adminId, $currentPassword, $newPassword)
    {
        if ((int)$adminId !== (int)($_SESSION['admin_id'] ?? 0) || !$this->isLoggedIn()) return false;
        if (!fas_admin_password_valid($newPassword)) {
            $this->lastError = 'Use a password between 12 and 72 bytes.';
            return false;
        }
        if ($this->verifyCurrentPassword((int)$adminId, $currentPassword)) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                // Recheck after acquiring the writer lock: another admin may have revoked access.
                if (!$this->isLoggedIn()) {
                    $this->db->exec('ROLLBACK');
                    $this->lastError = 'Your access changed. Sign in again.';
                    return false;
                }
                $stmt = $this->db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
                $result = $stmt->execute([$newHash, $adminId]);
                $token = fas_admin_session_token(fas_admin_account($this->db, (int)$adminId));
                $this->db->exec('COMMIT');
            } catch (Throwable $e) {
                $this->db->exec('ROLLBACK');
                throw $e;
            }
            $_SESSION['admin_session_token'] = $token;
            session_regenerate_id(true);
            unset($_SESSION['security_reauth_at']);
            fas_security_event('password', 'password_changed', (int)$adminId);
            return $result;
        }
        
        return false;
    }
    
    public function requireActiveAdmin(): array
    {
        $this->requireLogin();
        $stmt = $this->db->prepare("SELECT id,username,role FROM admin_users WHERE id=? AND is_active=1 AND role='admin'");
        $stmt->execute([(int)($_SESSION['admin_id'] ?? 0)]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$admin) { http_response_code(403); exit('An active administrator account is required.'); }
        return $admin;
    }

    public function verifyCurrentPassword(int $adminId, $password): bool
    {
        $this->lastError = '';
        $ip = fas_security_ip()['ip'];
        $limit = fas_security_check(['reauth_ip'=>$ip,'reauth_account'=>(string)$adminId], false, true);
        if (!$limit['allowed']) {
            fas_security_headers($limit); $this->lastError = fas_security_message($limit); return false;
        }
        $stmt = $this->db->prepare("SELECT password_hash FROM admin_users WHERE id=? AND is_active=1 AND role='admin'");
        $stmt->execute([$adminId]);
        $hash = $stmt->fetchColumn();
        $ok = $hash && is_string($password) && strlen($password)<=4096 && password_verify($password,$hash);
        fas_security_event('reauth',$ok?'reauth_success':'reauth_failed',$adminId);
        return (bool)$ok;
    }

    /** Shared by site and email settings; callers authorize and validate CSRF first. */
    public function allowSettingsChange(int $adminId): bool
    {
        $this->lastError = '';
        $limit = fas_security_check([
            'settings_account'=>(string)$adminId,
            'settings_ip'=>fas_security_ip()['ip'],
        ], false, true);
        if (!$limit['allowed']) {
            fas_security_headers($limit);
            $this->lastError = fas_security_message($limit);
            return false;
        }
        return true;
    }

    /**
     * Create initial admin user
     */
    public function createInitialAdmin($username, $email, $password)
    {
        // Check if any admin exists
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM admin_users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] == 0) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("
                INSERT INTO admin_users (username, email, password_hash, full_name, role) 
                VALUES (?, ?, ?, 'Administrator', 'admin')
            ");
            return $stmt->execute([$username, $email, $passwordHash]);
        }
        
        return false;
    }
    
    /**
     * Require login
     */
    public function requireLogin()
    {
        if (!$this->isLoggedIn()) {
            if (($_SESSION['admin_logged_in'] ?? false) === true) {
                $this->clearAnalyticsAdminCookie();
                http_response_code(403);
                header('Cache-Control: private, no-store');
                exit('Your administrator access has expired or changed. <a href="/admin/login.php">Sign in again</a>.');
            }
            header('Location: login.php');
            exit;
        }

        $this->issueAnalyticsAdminCookie();
    }

    private function issueAnalyticsAdminCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        $adminId = (string) ($_SESSION['admin_id'] ?? '');
        $username = (string) ($_SESSION['admin_username'] ?? '');
        if ($adminId === '' && $username === '') {
            return;
        }

        $expiresAt = time() + self::ANALYTICS_ADMIN_COOKIE_TTL;
        $payload = $this->base64UrlEncode(json_encode([
            'admin_id' => $adminId,
            'admin_username' => $username,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $payload, $this->analyticsCookieSecret());
        $value = $payload . '.' . $signature;

        setcookie(self::ANALYTICS_ADMIN_COOKIE, $value, [
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::ANALYTICS_ADMIN_COOKIE] = $value;
    }

    private function clearAnalyticsAdminCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::ANALYTICS_ADMIN_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::ANALYTICS_ADMIN_COOKIE]);
    }

    private function analyticsCookieSecret(): string
    {
        $configPath = __DIR__ . '/../src/config/config.php';
        $config = file_exists($configPath) ? require $configPath : [];
        $salt = is_array($config) ? (string) ($config['security']['admin_password_salt'] ?? '') : '';
        if ($salt === '') {
            $salt = __DIR__ . '|' . (string) ($_SERVER['HTTP_HOST'] ?? 'flipandstrip');
        }

        return hash('sha256', $salt . '|analytics-admin-cookie');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['HTTP_CF_VISITOR'] ?? '') !== '' && strpos((string) $_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false);
    }
}
