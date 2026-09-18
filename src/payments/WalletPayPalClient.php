<?php
declare(strict_types=1);
namespace FAS\Payments;

interface WalletPayPalGateway
{
    public function create(array $payload, string $requestId): array;
    public function get(string $orderId): array;
    public function capture(string $orderId, string $requestId): array;
}

final class PayPalFailure extends \RuntimeException
{
    public function __construct(string $name, int $status, string $debugId = '')
    {
        // Keep diagnostics, but never raw provider payloads, OAuth credentials or tokens.
        parent::__construct('PayPal ' . preg_replace('/[^A-Z0-9_]/i', '', $name)
            . ' HTTP ' . $status . ' debug=' . preg_replace('/[^a-z0-9-]/i', '', $debugId));
    }
}

final class WalletPayPalClient implements WalletPayPalGateway
{
    private array $config;
    private string $base;
    private ?string $token = null;

    public function __construct(array $paypalConfig)
    {
        $this->config = $paypalConfig;
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('PHP cURL extension is required');
        }
        foreach (['client_id', 'client_secret'] as $key) {
            if (empty($paypalConfig[$key]) || str_starts_with($paypalConfig[$key], 'YOUR_')) {
                throw new \RuntimeException('PayPal credentials are not configured');
            }
        }
        if (!in_array($paypalConfig['mode'] ?? '', ['live', 'sandbox'], true)
            || ($paypalConfig['currency'] ?? 'USD') !== 'USD') {
            throw new \RuntimeException('Apple Pay requires a valid PayPal mode and USD for this checkout');
        }
        $this->base = $paypalConfig['mode'] === 'live'
            ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    public function create(array $payload, string $requestId): array
    {
        return $this->request('POST', '/v2/checkout/orders', json_encode($payload, JSON_THROW_ON_ERROR), $requestId);
    }
    public function get(string $orderId): array
    {
        return $this->request('GET', '/v2/checkout/orders/' . $this->id($orderId));
    }
    public function capture(string $orderId, string $requestId): array
    {
        // PayPal expects an object, not the JSON array produced by json_encode([]).
        return $this->request('POST', '/v2/checkout/orders/' . $this->id($orderId) . '/capture', '{}', $requestId);
    }
    private function id(string $id): string
    {
        if (!preg_match('/^[A-Z0-9]{6,32}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid PayPal order reference');
        }
        return $id;
    }
    private function request(string $method, string $path, ?string $body = null, ?string $requestId = null): array
    {
        if ($this->token === null) {
            $data = $this->send('POST', '/v1/oauth2/token', 'grant_type=client_credentials',
                ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], true);
            if (!is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
                throw new PayPalFailure('INVALID_OAUTH_RESPONSE', 502);
            }
            $this->token = $data['access_token'];
        }
        $headers = ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json',
            'Accept: application/json', 'Prefer: return=representation'];
        if ($requestId !== null) {
            $headers[] = 'PayPal-Request-Id: ' . $requestId;
        }
        return $this->send($method, $path, $body, $headers);
    }
    private function send(string $method, string $path, ?string $body, array $headers, bool $oauth = false): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($oauth) {
            curl_setopt($ch, CURLOPT_USERPWD, $this->config['client_id'] . ':' . $this->config['client_secret']);
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $failed = $raw === false;
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($failed || !is_array($data) || $status < 200 || $status >= 300) {
            throw new PayPalFailure($failed ? 'NETWORK_ERROR' : (string)($data['name'] ?? 'INVALID_RESPONSE'),
                $status, (string)($data['debug_id'] ?? ''));
        }
        return $data;
    }
}
