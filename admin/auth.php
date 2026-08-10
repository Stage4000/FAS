<?php
/**
 * Admin Authentication System
 */

session_start();

// Apply the site-configured timezone as early as possible
require_once __DIR__ . '/../src/utils/Timezone.php';
\FAS\Utils\Timezone::apply();

class AdminAuth
{
    private const ANALYTICS_ADMIN_COOKIE = 'fas_admin_analytics';
    private const ANALYTICS_ADMIN_COOKIE_TTL = 2592000;

    private $db;
    
    public function __construct($db = null)
    {
        if ($db) {
            $this->db = $db;
        } else {
            require_once __DIR__ . '/../src/config/Database.php';
            $this->db = \FAS\Config\Database::getInstance()->getConnection();
        }
    }
    
    /**
     * Check if admin is logged in
     */
    public function isLoggedIn()
    {
        return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    }
    
    /**
     * Login admin
     */
    public function login($username, $password)
    {
        $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && password_verify($password, $admin['password_hash'])) {
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_email'] = $admin['email'];
            $this->issueAnalyticsAdminCookie();
            
            // Update last login
            $stmt = $this->db->prepare("UPDATE admin_users SET last_login = datetime('now') WHERE id = ?");
            $stmt->execute([$admin['id']]);
            
            return true;
        }
        
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
        $stmt = $this->db->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && password_verify($currentPassword, $admin['password_hash'])) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
            return $stmt->execute([$newHash, $adminId]);
        }
        
        return false;
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
