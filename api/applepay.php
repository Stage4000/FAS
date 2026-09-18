<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../src/payments/ApplePayContext.php';
use FAS\Payments\ApplePayContext;
use FAS\Payments\ApplePayFactory;
use FAS\Payments\CheckoutProblem;

$id = '';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new CheckoutProblem('method', 'POST is required.', 405);
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        throw new CheckoutProblem('content_type', 'JSON is required.', 415);
    }
    // CSRF is the primary protection. Reject explicitly cross-origin browser requests as well.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== 'https://' . ($_SERVER['HTTP_HOST'] ?? '')) {
        throw new CheckoutProblem('origin', 'Use checkout on this site.', 403);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) {
        throw new CheckoutProblem('request_size', 'Checkout request is too large.', 413);
    }
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        throw new CheckoutProblem('invalid_json', 'Invalid checkout request.', 400);
    }
    ApplePayContext::startSession();
    ApplePayContext::requireCsrf((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $action = $input['action'] ?? '';
    if (!in_array($action, ['create', 'capture', 'status', 'abandon'], true)) {
        throw new CheckoutProblem('invalid_action', 'Invalid payment action.', 400);
    }
    // A kill switch stops new charges, but must not prevent read-only reconciliation.
    if (in_array($action, ['create', 'capture'], true) && !ApplePayContext::allowed()) {
        throw new CheckoutProblem('disabled', 'Apple Pay is not available for this checkout.', 403);
    }
    $id = ApplePayContext::text($input['attempt_id'] ?? '', 32);
    $owner = ApplePayContext::owner();
    require_once __DIR__ . '/../src/payments/ApplePayFactory.php';
    $service = ApplePayFactory::make();
    if ($action === 'create') {
        $quoteKey = is_string($input['shipping_quote'] ?? null) ? $input['shipping_quote'] : '';
        $quote = $_SESSION['fas_applepay_quotes'][$quoteKey] ?? null;
        $result = $service->create($id, $owner, $input, is_array($quote) ? $quote : null);
    } elseif ($action === 'capture') {
        // Persist the attempt and let PHP finish recording a capture if the browser disconnects.
        ignore_user_abort(true);
        $result = $service->capture($id, $owner);
    } elseif ($action === 'abandon') {
        $result = $service->abandon($id, $owner);
    } else {
        $result = $service->status($id, $owner);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (CheckoutProblem $e) {
    http_response_code($e->httpStatus);
    echo json_encode(['ok' => false, 'code' => $e->reason, 'error' => $e->getMessage(), 'attempt_id' => $id]);
} catch (Throwable $e) {
    // No raw wallet tokens, billing data, credentials or customer details in the error response/log.
    $safeId = preg_replace('/[^a-f0-9]/', '', $id);
    error_log('Apple Pay failure attempt=' . $safeId . ' type=' . get_class($e)
        . ($e instanceof \FAS\Payments\PayPalFailure ? ' ' . $e->getMessage() : ''));
    http_response_code(502);
    echo json_encode(['ok' => false, 'code' => 'payment_uncertain', 'attempt_id' => $safeId,
        'error' => 'Payment status could not be confirmed. Use Check payment status; do not make another payment.']);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}
