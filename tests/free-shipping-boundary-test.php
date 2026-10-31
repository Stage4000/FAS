<?php
// Offline contract regression: explicit settings only, no configuration/DB/provider loading.
require __DIR__ . '/../src/utils/ShippingRules.php';
use FAS\Utils\ShippingRules;
$checks = 0;
function same($expected, $actual, string $label): void {
    global $checks;
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function settings(array $overrides = []): array {
    return ShippingRules::normalizeFreeShippingSettings(array_merge([
        'enabled' => true, 'product_flags_enabled' => false, 'auto_rules_enabled' => true,
    ], $overrides));
}
$address = ['country' => 'US', 'state' => 'OR'];
$fields = ['weight', 'length', 'width', 'height'];
foreach ($fields as $field) {
    foreach ([1, 2, 10, 100, 1000] as $limit) {
        $config = settings(['max_' . $field => (string)$limit]);
        same((float)$limit, $config['max_' . $field], "$field numeric-string setting");
        foreach ([$limit / 2, $limit, $limit + 0.25] as $value) {
            foreach ([$value, (string)$value] as $input) {
                $expected = $value <= $limit ? 'size_weight' : '';
                same($expected, ShippingRules::getFreeShippingReason([$field => $input], $config, $address), "$field threshold $limit value $value");
                same($expected !== '', ShippingRules::productQualifiesForFreeShipping([$field => $input], $config, $address), "$field public boolean wrapper");
            }
        }
        foreach ([null, '', 0, '0', -1, '-1'] as $missing) {
            same('', ShippingRules::getFreeShippingReason([$field => $missing], $config, $address), "$field missing/nonpositive does not qualify");
        }
        same('', ShippingRules::getFreeShippingReason([], $config, $address), "$field absent does not qualify");
    }
}
// Every enabled threshold must pass; unconfigured dimensions are not required.
for ($mask = 1; $mask < 16; ++$mask) {
    $limits = []; $product = [];
    foreach ($fields as $i => $field) {
        if ($mask & (1 << $i)) { $limits['max_' . $field] = '10'; $product[$field] = '10'; }
    }
    $config = settings($limits);
    same('size_weight', ShippingRules::getFreeShippingReason($product, $config, $address), "combination $mask boundary");
    foreach ($product as $field => $_) {
        $over = $product; $over[$field] = '10.01';
        same('', ShippingRules::getFreeShippingReason($over, $config, $address), "combination $mask $field above");
        $absent = $product; unset($absent[$field]);
        same('', ShippingRules::getFreeShippingReason($absent, $config, $address), "combination $mask $field absent");
    }
}
same('', ShippingRules::getFreeShippingReason(['weight' => 1], settings(), $address), 'no limits cannot grant automatic shipping');
foreach (['1', 'true', 'yes', 'on', true, 1] as $flag) {
    $manual = settings(['product_flags_enabled' => $flag, 'auto_rules_enabled' => false]);
    same('manual', ShippingRules::getFreeShippingReason(['free_shipping' => $flag], $manual, $address), 'manual grant independent of missing dimensions');
    same('', ShippingRules::getFreeShippingReason(['free_shipping' => $flag], array_merge($manual, ['enabled' => false]), $address), 'master disabled beats manual');
    same('', ShippingRules::getFreeShippingReason(['free_shipping' => $flag], $manual, ['country' => 'US', 'state' => 'AK']), 'destination gate beats manual');
}
same('', ShippingRules::getFreeShippingReason(['weight' => 1], settings(['max_weight' => 10, 'auto_rules_enabled' => false]), $address), 'automatic disabled beats matching dimension');
same('size_weight', ShippingRules::getFreeShippingReason(['weight' => 1, 'free_shipping' => true], settings(['max_weight' => 10]), $address), 'manual disabled preserves automatic reason');
same('manual', ShippingRules::getFreeShippingReason(['weight' => 1, 'free_shipping' => true], settings(['max_weight' => 10, 'product_flags_enabled' => true]), $address), 'manual reason precedes automatic');
echo "PASS: $checks offline free-shipping boundary assertions\n";
