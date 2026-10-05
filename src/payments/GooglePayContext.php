<?php
declare(strict_types=1);
namespace FAS\Payments;
require_once __DIR__ . '/ApplePayContext.php';

final class GooglePayContext
{
    public static function settings(): array
    {
        $defaults = require __DIR__ . '/../config/googlepay.example.php';
        $path = __DIR__ . '/../config/googlepay.php';
        $local = is_file($path) ? require $path : [];
        return array_replace($defaults, is_array($local) ? $local : []);
    }

    public static function allowed(): bool
    {
        // Same session and live-only public rollout rule as the existing wallet.
        return ApplePayContext::allowed(self::settings());
    }
}
