<?php
declare(strict_types=1);
namespace FAS\Payments;
require_once __DIR__ . '/ApplePayContext.php';
require_once __DIR__ . '/WalletPayPalClient.php';
require_once __DIR__ . '/ApplePayService.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/Coupon.php';
require_once __DIR__ . '/../../includes/sale-helper.php';

final class ApplePayFactory
{
    public static function make(): ApplePayService
    {
        $config = require __DIR__ . '/../config/config.php';
        $db = \FAS\Config\Database::getInstance()->getConnection();
        $coupon = new \FAS\Models\Coupon($db);
        return new ApplePayService($db, new WalletPayPalClient($config['paypal']),
            static function (array $product): float {
                $price = \getEffectivePrice((float)$product['price'],
                    !empty($product['sale_price']) ? (float)$product['sale_price'] : null);
                return (float)$price['effective_price'];
            },
            static function (string $code, int $subtotal) use ($coupon): int {
                $result = $coupon->validateCoupon($code, $subtotal / 100);
                if (empty($result['valid'])) {
                    throw new CheckoutProblem('invalid_coupon', 'The coupon is no longer valid. Remove it and review your total.');
                }
                return ApplePayContext::catalogCents($result['discount']);
            }, (string)$config['paypal']['mode']);
    }
}
