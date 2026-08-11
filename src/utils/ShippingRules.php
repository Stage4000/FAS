<?php

namespace FAS\Utils;

class ShippingRules
{
    private const DEFAULT_FREE_SHIPPING = [
        'enabled' => true,
        'product_flags_enabled' => true,
        'auto_rules_enabled' => false,
        'max_weight' => null,
        'max_length' => null,
        'max_width' => null,
        'max_height' => null,
    ];

    public static function getFreeShippingSettings(?array $config = null): array
    {
        if ($config === null) {
            $config = self::loadConfig();
        }

        $settings = $config['shipping']['free_shipping'] ?? [];
        return self::normalizeFreeShippingSettings(is_array($settings) ? $settings : []);
    }

    public static function normalizeFreeShippingSettings(array $settings): array
    {
        $normalized = array_merge(self::DEFAULT_FREE_SHIPPING, $settings);

        $normalized['enabled'] = self::truthy($normalized['enabled']);
        $normalized['product_flags_enabled'] = self::truthy($normalized['product_flags_enabled']);
        $normalized['auto_rules_enabled'] = self::truthy($normalized['auto_rules_enabled']);

        foreach (['max_weight', 'max_length', 'max_width', 'max_height'] as $field) {
            $normalized[$field] = self::positiveFloatOrNull($normalized[$field] ?? null);
        }

        return $normalized;
    }

    public static function productQualifiesForFreeShipping(array $product, ?array $settings = null): bool
    {
        return self::getFreeShippingReason($product, $settings) !== '';
    }

    public static function getFreeShippingReason(array $product, ?array $settings = null): string
    {
        $settings = $settings ?? self::getFreeShippingSettings();

        if (empty($settings['enabled'])) {
            return '';
        }

        if (!empty($settings['product_flags_enabled']) && self::truthy($product['free_shipping'] ?? false)) {
            return 'manual';
        }

        if (empty($settings['auto_rules_enabled'])) {
            return '';
        }

        if (self::matchesAutoSizeWeightRule($product, $settings)) {
            return 'size_weight';
        }

        return '';
    }

    public static function freeShippingRate(array $summary = []): array
    {
        return [
            'courier_id' => 'free_shipping',
            'courier_name' => 'Flip and Strip',
            'service_name' => 'Free Shipping',
            'delivery_time_text' => 'Free shipping applied to eligible items',
            'total_charge' => 0.0,
            'currency' => 'USD',
            'is_free_shipping' => true,
            'free_shipping_item_count' => (int)($summary['item_count'] ?? 0),
        ];
    }

    private static function matchesAutoSizeWeightRule(array $product, array $settings): bool
    {
        $limits = [
            'weight' => 'max_weight',
            'length' => 'max_length',
            'width' => 'max_width',
            'height' => 'max_height',
        ];

        $hasLimit = false;

        foreach ($limits as $productField => $settingField) {
            $limit = $settings[$settingField] ?? null;
            if ($limit === null) {
                continue;
            }

            $hasLimit = true;
            $value = self::positiveFloatOrNull($product[$productField] ?? null);

            if ($value === null || $value > $limit) {
                return false;
            }
        }

        return $hasLimit;
    }

    private static function loadConfig(): array
    {
        $configFile = __DIR__ . '/../config/config.php';
        if (!file_exists($configFile)) {
            $configFile = __DIR__ . '/../config/config.example.php';
        }

        return file_exists($configFile) ? require $configFile : [];
    }

    private static function positiveFloatOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float)$value;
        return $number > 0 ? $number : null;
    }

    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int)$value) === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return !empty($value);
    }
}
