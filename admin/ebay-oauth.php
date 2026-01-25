<?php
/**
 * eBay OAuth Initiation
 * Starts the OAuth2 authorization flow for obtaining eBay User Token
 */

require_once __DIR__ . '/auth.php';

$auth = new AdminAuth();
$auth->requireLogin();

// Load current config
$configFile = __DIR__ . '/../src/config/config.php';
$config = file_exists($configFile) ? require $configFile : [];

// Validate eBay credentials
$appId = $config['ebay']['app_id'] ?? '';
$certId = $config['ebay']['cert_id'] ?? '';
$devId = $config['ebay']['dev_id'] ?? '';
$sandbox = !empty($config['ebay']['sandbox']);

if (empty($appId) || empty($certId) || empty($devId)) {
    $_SESSION['error'] = 'Please configure your eBay App ID, Cert ID, and Dev ID first.';
    header('Location: settings.php');
    exit;
}

// Generate secure state parameter for CSRF protection
$state = bin2hex(random_bytes(16));
$_SESSION['ebay_oauth_state'] = $state;

// Store credentials in session for callback
$_SESSION['ebay_oauth_config'] = [
    'app_id' => $appId,
    'cert_id' => $certId,
    'dev_id' => $devId,
    'sandbox' => $sandbox
];

// Determine OAuth URLs based on mode
if ($sandbox) {
    $authUrl = 'https://auth.sandbox.ebay.com/oauth2/authorize';
} else {
    $authUrl = 'https://auth.ebay.com/oauth2/authorize';
}

// Build callback URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$callbackUrl = $protocol . '://' . $host . dirname($_SERVER['PHP_SELF']) . '/ebay-oauth-callback.php';

// OAuth scopes for eBay API
// Use the Inventory API readonly scope for read-only access to inventory
$scopes = [
    'https://api.ebay.com/oauth/api_scope/sell.inventory.readonly'
];

// Build authorization URL with proper encoding
$params = [
    'client_id' => $appId,
    'redirect_uri' => $callbackUrl,
    'response_type' => 'code',
    'state' => $state,
    'scope' => implode(' ', $scopes),
    'prompt' => 'login'  // Force user to login for fresh consent
];

// Manually build query string to ensure proper encoding
$queryParts = [];
foreach ($params as $key => $value) {
    $queryParts[] = $key . '=' . urlencode($value);
}
$authorizationUrl = $authUrl . '?' . implode('&', $queryParts);

// Redirect to eBay for authorization
header('Location: ' . $authorizationUrl);
exit;
