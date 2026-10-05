<?php
declare(strict_types=1);
namespace FAS\Shipping;

final class ShippingConfig
{
    /** Managed settings live alongside, never inside, the deployed web tree. */
    public static function managedPath(): string
    {
        $override = getenv('FAS_SHIPPING_CONFIG_PATH');
        return $override !== false && $override !== ''
            ? $override : dirname(__DIR__, 3).'/fas-private/shipping.php';
    }

    public static function load(): array
    {
        $defaults = require __DIR__.'/../config/shipping.example.php';
        $override = getenv('FAS_SHIPPING_CONFIG_PATH');
        $managed = self::managedPath();
        $legacy = __DIR__.'/../config/shipping.php';
        $path = $override ? $managed : (is_file($managed) ? $managed : $legacy);
        if ($override && !is_file($path)) throw new \RuntimeException('Shipping configuration file is unavailable.');
        if (is_file($path)) {
            $config = require $path;
            if (!is_array($config)) throw new \RuntimeException('Invalid shipping configuration.');
            $defaults = array_replace_recursive($defaults, $config);
        }
        // A private settings file may have been created before storage was provisioned.
        $cacheOverride=getenv('FAS_SHIPPING_CACHE_PATH');
        if (($defaults['cache_path'] ?? '')==='' && $cacheOverride!==false && $cacheOverride!=='') {
            $defaults['cache_path']=$cacheOverride;
        }
        return self::validate($defaults);
    }

    public static function validate(array $config): array
    {
        if (!in_array($config['mode'] ?? '', ['easyship','direct_with_fallback','direct'], true)) {
            throw new \RuntimeException('Invalid shipping mode.');
        }
        foreach (['max_packages'=>[1,10], 'request_budget_seconds'=>[2,20], 'quote_ttl_seconds'=>[30,300]] as $key=>$bounds) {
            if (!is_int($config[$key] ?? null) || $config[$key]<$bounds[0] || $config[$key]>$bounds[1]) {
                throw new \RuntimeException('Invalid shipping limits.');
            }
        }
        if (($config['packing_policy'] ?? '') !== 'individual' || !is_bool($config['parcel_data_verified'] ?? null)) {
            throw new \RuntimeException('Invalid packing policy.');
        }
        if (!is_string($config['cache_path'] ?? null)) throw new \RuntimeException('Invalid shipping cache path.');
        $mail=$config['notifications'] ?? [];
        if (!is_bool($mail['enabled'] ?? null) || !is_bool($mail['delivery_verified'] ?? null)
            || !is_int($mail['not_before'] ?? null) || $mail['not_before']<0) {
            throw new \RuntimeException('Invalid shipping notification settings.');
        }
        foreach (['from_email','reply_to'] as $key) {
            $value=$mail[$key] ?? null;
            if (!is_string($value) || strlen($value)>254 || preg_match('/[\x00-\x20\x7f]/',$value)
                || ($value!=='' && !filter_var($value,FILTER_VALIDATE_EMAIL))) {
                throw new \RuntimeException('Invalid shipping notification address.');
            }
        }
        foreach (['shipper_name'=>35,'shipper_phone'=>30] as $key=>$max) {
            $value=$config[$key] ?? null;
            if (!is_string($value) || strlen($value)>$max || preg_match('/[\x00-\x1f\x7f]/',$value)) {
                throw new \RuntimeException('Invalid shipping fulfillment identity.');
            }
        }
        if (array_diff(array_keys($config['carriers'] ?? []),['usps','ups'])) {
            throw new \RuntimeException('Unknown shipping carrier.');
        }
        foreach (['usps','ups'] as $name) {
            $carrier = $config['carriers'][$name] ?? [];
            if (!is_bool($carrier['enabled'] ?? null) || !is_bool($carrier['production_verified'] ?? null)
                || !is_bool($carrier['label_purchasing_enabled'] ?? null)
                || !is_bool($carrier['label_cancellation_enabled'] ?? null)
                || !is_bool($carrier['tracking_enabled'] ?? null)
                || !in_array($carrier['environment'] ?? '', ['sandbox','production'], true)) {
                throw new \RuntimeException('Invalid carrier activation settings.');
            }
            foreach (['client_id','client_secret','account_number','crid','mid','manifest_mid','eps_account_number'] as $key) {
                $value = $carrier[$key] ?? '';
                if (!is_string($value) || strlen($value)>4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new \RuntimeException('Invalid carrier credentials.');
                }
            }
        }
        if (!in_array($config['carriers']['usps']['gateway'] ?? '', ['apis','api'], true)
            || !in_array($config['carriers']['usps']['price_type'] ?? '', ['RETAIL','COMMERCIAL'], true)
            || !is_bool($config['carriers']['usps']['label_reprint_enabled'] ?? null)) {
            throw new \RuntimeException('Invalid USPS pricing configuration.');
        }
        return $config;
    }

    public static function ready(array $carrier, string $name, bool $checkout): bool
    {
        if (($carrier['enabled'] ?? false) !== true || empty($carrier['client_id']) || empty($carrier['client_secret'])) return false;
        if ($name !== 'usps' && empty($carrier['account_number'])) return false;
        return !$checkout || ($carrier['environment'] === 'production' && $carrier['production_verified'] === true);
    }
}
