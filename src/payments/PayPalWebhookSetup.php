<?php
declare(strict_types=1);
namespace FAS\Payments;
require_once __DIR__.'/../integrations/PayPalAPI.php';

/** App-scoped REST webhook registration. Does not change account-wide IPN settings. */
class PayPalWebhookSetup extends \FAS\Integrations\PayPalAPI
{
    public const EVENTS = ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED'];

    private function request(string $method, string $path, ?array $data = null): array
    {
        $result = $this->makeRequest($method, $path, $data);
        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('PayPal webhook API request failed. Check credentials, permissions, and TLS certificate configuration.');
        }
        return $result['data'] ?? [];
    }

    public function webhooks(): array
    {
        $result = $this->request('GET', '/v1/notifications/webhooks');
        if (!isset($result['webhooks']) || !is_array($result['webhooks'])) {
            throw new \RuntimeException('PayPal returned an unexpected webhook list.');
        }
        return $result['webhooks'];
    }

    public function ensure(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new \InvalidArgumentException('An HTTPS webhook URL is required.');
        }
        $matches = array_values(array_filter($this->webhooks(), static fn($hook) => ($hook['url'] ?? '') === $url));
        if (count($matches) > 1) throw new \RuntimeException('Multiple webhooks use this URL. Resolve duplicates before setup.');
        $hook = $matches[0] ?? null;
        if ($hook === null) {
            $hook = $this->request('POST', '/v1/notifications/webhooks', ['url' => $url,
                'event_types' => array_map(static fn($name) => ['name' => $name], self::EVENTS)]);
        } else {
            $events = array_column($hook['event_types'] ?? [], 'name');
            if (!in_array('*', $events, true) && array_diff(self::EVENTS, $events)) {
                $id = $this->id($hook['id'] ?? '');
                $this->request('PATCH', '/v1/notifications/webhooks/'.$id, [[
                    'op' => 'replace', 'path' => '/event_types',
                    'value' => array_map(static fn($name) => ['name' => $name], array_values(array_unique(array_merge($events, self::EVENTS))))]]);
            }
        }
        $id = $this->id($hook['id'] ?? '');
        $verified = $this->request('GET', '/v1/notifications/webhooks/'.$id);
        $events = array_column($verified['event_types'] ?? [], 'name');
        if (($verified['id'] ?? '') !== $id || ($verified['url'] ?? '') !== $url
            || (!in_array('*', $events, true) && array_diff(self::EVENTS, $events))) {
            throw new \RuntimeException('Webhook registration needs review; PayPal read-back did not match.');
        }
        return $verified;
    }

    private function id($id): string
    {
        if (!is_string($id) || !preg_match('/^[A-Z0-9-]{1,128}$/D', $id)) {
            throw new \RuntimeException('Invalid webhook ID returned by PayPal.');
        }
        return $id;
    }

    public static function listenerReady(string $url): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['id' => 'FAS-READINESS-'.bin2hex(random_bytes(8)), 'event_type' => 'FAS.WEBHOOK.READINESS']),
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = is_string($body) ? json_decode($body, true) : null;
        return $code === 401 && ($data['error'] ?? '') === 'Webhook signature could not be verified';
    }
}
