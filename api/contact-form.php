<?php
/**
 * Contact Form Submission Handler
 * Handles contact form submissions with Cloudflare Turnstile verification
 */

header('Content-Type: application/json');

// Load configuration
$configFile = __DIR__ . '/../src/config/config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/../src/config/config.example.php';
}
$config = require $configFile;

// Get POST data
$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$subject = $_POST['subject'] ?? '';
$message = $_POST['message'] ?? '';
$turnstileToken = $_POST['cf-turnstile-response'] ?? '';

// Validate required fields
if (empty($name) || empty($email) || empty($subject) || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

// Verify Turnstile if enabled
if (!empty($config['turnstile']['enabled']) && !empty($config['turnstile']['secret_key'])) {
    if (empty($turnstileToken)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please complete the security verification']);
        exit;
    }
    
    // Verify token with Cloudflare
    $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $verifyData = [
        'secret' => $config['turnstile']['secret_key'],
        'response' => $turnstileToken,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];
    
    $ch = curl_init($verifyUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($verifyData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $verifyResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$verifyResponse) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to verify security token']);
        exit;
    }
    
    $verifyResult = json_decode($verifyResponse, true);
    
    if (!$verifyResult['success']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Security verification failed. Please try again.']);
        exit;
    }
}

// TODO: Here you would typically:
// 1. Save the message to database
// 2. Send email notification to site admin
// 3. Send confirmation email to user

// For now, we'll just return success
// In production, you should implement email sending or database storage

$siteEmail = $config['site']['email'] ?? 'info@flipandstrip.com';
$siteName = $config['site']['name'] ?? 'Flip and Strip';

// Simple email sending (if mail() is configured on server)
$to = $siteEmail;
$emailSubject = "Contact Form: " . $subject;
$emailBody = "Name: $name\n";
$emailBody .= "Email: $email\n";
$emailBody .= "Subject: $subject\n\n";
$emailBody .= "Message:\n$message\n";
$headers = "From: $email\r\n";
$headers .= "Reply-To: $email\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

// Attempt to send email (will fail silently if mail is not configured)
@mail($to, $emailSubject, $emailBody, $headers);

echo json_encode([
    'success' => true,
    'message' => 'Thank you for your message! We will get back to you soon.'
]);
