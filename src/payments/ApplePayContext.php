<?php
declare(strict_types=1);

namespace FAS\Payments;

final class CheckoutProblem extends \RuntimeException
{
    public string $reason;
    public int $httpStatus;
    public function __construct(string $reason, string $message, int $httpStatus = 409)
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->httpStatus = $httpStatus;
    }
}

final class ApplePayContext
{
    public const QUOTE_TTL = 600;
    public const ATTEMPT_TTL = 900;

    public static function settings(): array
    {
        $path = __DIR__ . '/../config/applepay.php';
        $settings = is_file($path) ? require $path : [];
        return array_replace(['enabled' => false, 'admin_only' => true,
            'display_name' => 'Flip and Strip'], is_array($settings) ? $settings : []);
    }

    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Share FAS's existing PHP session, including its admin login.
            if (!session_start(['use_strict_mode' => 1, 'cookie_secure' => true,
                'cookie_httponly' => true, 'cookie_samesite' => 'Lax'])) {
                throw new \RuntimeException('Unable to start checkout session');
            }
        }
    }

    public static function allowed(?array $settings = null): bool
    {
        $settings = $settings ?? self::settings();
        if (($settings['enabled'] ?? false) !== true) {
            return false;
        }
        self::startSession();
        if (($settings['admin_only'] ?? true) !== false) {
            return ($_SESSION['admin_logged_in'] ?? false) === true;
        }
        $path = __DIR__ . '/../config/config.php';
        $config = is_file($path) ? require $path : [];
        // Never expose sandbox payments as live public checkout.
        return ($config['paypal']['mode'] ?? '') === 'live';
    }

    public static function csrf(): string
    {
        self::startSession();
        if (empty($_SESSION['fas_applepay_csrf'])) {
            $_SESSION['fas_applepay_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['fas_applepay_csrf'];
    }

    public static function owner(): string
    {
        self::startSession();
        if (empty($_SESSION['fas_applepay_owner'])) {
            $_SESSION['fas_applepay_owner'] = bin2hex(random_bytes(32));
        }
        return hash('sha256', $_SESSION['fas_applepay_owner']);
    }

    public static function requireCsrf(string $token): void
    {
        $known = $_SESSION['fas_applepay_csrf'] ?? '';
        if (!is_string($known) || $known === '' || !hash_equals($known, $token)) {
            throw new CheckoutProblem('session_expired', 'Checkout session expired. Reload this page.', 403);
        }
    }

    /** Integer minor units; public amount inputs must be plain decimal strings. */
    public static function cents($value): int
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $m)) {
            throw new CheckoutProblem('invalid_amount', 'Invalid payment amount.', 400);
        }
        return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    }

    /** Use only for trusted database/provider amounts, never to trust a browser price. */
    public static function catalogCents($value): int
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) {
            throw new CheckoutProblem('invalid_amount', 'The catalog contains an invalid price.');
        }
        return self::cents(number_format((float)$value, 2, '.', ''));
    }

    public static function money(int $cents): string
    {
        if ($cents < 0 || $cents > 9999999999) {
            throw new CheckoutProblem('invalid_amount', 'Payment amount is out of range.');
        }
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function text($value, int $max, bool $required = true): string
    {
        if (!is_string($value)) {
            throw new CheckoutProblem('invalid_input', 'Please check your checkout details.', 400);
        }
        $value = trim($value);
        if (($required && $value === '') || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new CheckoutProblem('invalid_input', 'Please check your checkout details.', 400);
        }
        return $value;
    }

    public static function address(array $input): array
    {
        $a = [];
        foreach (['address1' => 300, 'address2' => 300, 'city' => 120, 'state' => 2, 'zip' => 10, 'country' => 2] as $key => $max) {
            $a[$key] = self::text($input[$key] ?? '', $max, $key !== 'address2');
        }
        $a['state'] = strtoupper($a['state']);
        $a['country'] = strtoupper($a['country']);
        if ($a['country'] !== 'US' || !preg_match('/^[A-Z]{2}$/D', $a['state'])
            || !preg_match('/^[0-9]{5}(?:-[0-9]{4})?$/D', $a['zip'])) {
            throw new CheckoutProblem('invalid_address', 'Use a US address, two-letter state, and valid ZIP code.', 400);
        }
        return $a;
    }

    /** Normalize IDs/quantities, aggregate duplicates, reject zero/negative/fractional values. */
    public static function cart(array $items): array
    {
        if (!$items || count($items) > 100) {
            throw new CheckoutProblem('invalid_cart', 'The cart is empty or too large.', 400);
        }
        $cart = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new CheckoutProblem('invalid_cart', 'Invalid cart item.', 400);
            }
            $id = $item['product_id'] ?? $item['id'] ?? null;
            $qty = $item['quantity'] ?? null;
            foreach ([$id, $qty] as $v) {
                if ((!is_int($v) && !is_string($v)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$v)) {
                    throw new CheckoutProblem('invalid_cart', 'Invalid product or quantity.', 400);
                }
            }
            $id = (int)$id;
            $cart[$id] = ($cart[$id] ?? 0) + (int)$qty;
            if ($cart[$id] > 999) {
                throw new CheckoutProblem('invalid_cart', 'Quantity is too large.', 400);
            }
        }
        ksort($cart, SORT_NUMERIC);
        return $cart;
    }

    /** Called ONLY with the rates actually computed by shipping-rates.php. */
    public static function rememberShipping(array $input, array $rates): ?string
    {
        try {
            if (!self::allowed() || !$rates) {
                return null;
            }
            $now = time();
            $quotes = $_SESSION['fas_applepay_quotes'] ?? [];
            $quotes = array_filter($quotes, static fn($q) => is_array($q) && ($q['expires'] ?? 0) > $now);
            // A malformed Apple Pay quote must not break the ordinary shipping response.
            $quote = ['cart' => self::cart($input['items']), 'address' => self::address($input['address']),
                'rates' => array_values($rates), 'expires' => $now + self::QUOTE_TTL];
            foreach ($quote['rates'] as $rate) {
                self::catalogCents($rate['total_charge'] ?? null);
            }
            $key = bin2hex(random_bytes(16));
            $quotes[$key] = $quote;
            $_SESSION['fas_applepay_quotes'] = array_slice($quotes, -8, null, true);
            header('Cache-Control: private, no-store');
            return $key;
        } catch (\Throwable $e) {
            error_log('Apple Pay shipping quote could not be saved.');
            return null;
        }
    }

    public static function shipping(string $key): array
    {
        $quote = $_SESSION['fas_applepay_quotes'][$key] ?? null;
        if (!is_array($quote) || ($quote['expires'] ?? 0) < time()) {
            throw new CheckoutProblem('shipping_expired', 'Please calculate shipping again before using Apple Pay.');
        }
        return $quote;
    }
}
