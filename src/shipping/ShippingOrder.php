<?php
declare(strict_types=1);
namespace FAS\Shipping;

require_once __DIR__.'/../payments/ApplePayContext.php';

use FAS\Payments\ApplePayContext;
use FAS\Payments\CheckoutProblem;

/** Immutable shipping selection attached to an order, separate from carrier API caches. */
final class ShippingOrder
{
    public static function install(\PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS order_shipping (
            order_id INTEGER PRIMARY KEY REFERENCES orders(id) ON DELETE CASCADE,
            provider TEXT NOT NULL,
            courier_id TEXT NOT NULL,
            service_code TEXT,
            courier_name TEXT NOT NULL,
            service_name TEXT NOT NULL,
            quoted_cents INTEGER NOT NULL,
            currency TEXT NOT NULL DEFAULT \'USD\',
            rate_basis TEXT,
            quote_hash TEXT NOT NULL,
            quote_expires_at INTEGER NOT NULL,
            origin_json TEXT,
            packages_json TEXT,
            fulfillment_json TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        if (!self::hasFulfillmentColumn($db)) {
            $db->exec('ALTER TABLE order_shipping ADD COLUMN fulfillment_json TEXT');
        }
        $db->exec('CREATE INDEX IF NOT EXISTS idx_order_shipping_provider ON order_shipping(provider)');
    }

    public static function installed(\PDO $db): bool
    {
        $stmt=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='order_shipping'");
        return (bool)$stmt->fetchColumn();
    }

    public static function find(\PDO $db,int $orderId): ?array
    {
        if (!self::installed($db)) return null;
        $stmt=$db->prepare('SELECT * FROM order_shipping WHERE order_id=?');
        $stmt->execute([$orderId]);
        $row=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['origin']=$row['origin_json']===null ? null : json_decode($row['origin_json'],true);
        $row['packages']=$row['packages_json']===null ? [] : json_decode($row['packages_json'],true);
        $row['fulfillment_options']=empty($row['fulfillment_json']) ? [] : json_decode($row['fulfillment_json'],true);
        unset($row['origin_json'],$row['packages_json'],$row['fulfillment_json'],$row['quote_hash']);
        return $row;
    }

    public static function selected(array $input): array
    {
        $key=$input['shipping_quote'] ?? null;
        $index=$input['shipping_index'] ?? null;
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D',$key)
            || (!is_int($index) && !is_string($index))
            || !preg_match('/^(0|[1-9][0-9]{0,2})$/D',(string)$index)) {
            throw new CheckoutProblem('invalid_shipping','Calculate shipping and select a current method.',400);
        }
        $quote=ApplePayContext::shipping($key);
        if (!is_array($input['items'] ?? null) || !is_array($input['shipping_address'] ?? null)
            || $quote['cart'] !== ApplePayContext::cart($input['items'])
            || $quote['address'] !== ApplePayContext::address($input['shipping_address'])) {
            throw new CheckoutProblem('shipping_changed','Your cart or address changed. Calculate shipping again.',409);
        }
        $rate=$quote['rates'][(int)$index] ?? null;
        if (!is_array($rate)) {
            throw new CheckoutProblem('invalid_shipping','Choose a current shipping method.',400);
        }
        if (($rate['currency'] ?? 'USD') !== 'USD') {
            throw new CheckoutProblem('invalid_shipping','Choose a USD shipping method.',400);
        }
        $cost=ApplePayContext::catalogCents($rate['total_charge'] ?? null);
        if ($cost!==ApplePayContext::catalogCents($input['shipping_cost'] ?? null)) {
            throw new CheckoutProblem('shipping_changed','The shipping amount changed. Calculate shipping again.',409);
        }
        return [$key,$quote,$rate];
    }

    public static function record(\PDO $db,int $orderId,string $key,array $quote,array $rate): void
    {
        $provider=$rate['provider'] ?? (!empty($rate['is_free_shipping']) ? 'free' : null);
        if (!in_array($provider,['free','easyship','usps','ups'],true)) {
            throw new \RuntimeException('Unsupported shipping provider.');
        }
        if (($rate['currency'] ?? 'USD') !== 'USD') {
            throw new \RuntimeException('Unsupported shipping currency.');
        }
        if (!self::installed($db)) {
            // An existing Easyship checkout may run before the additive schema migration.
            if (in_array($provider,['easyship','free'],true)) return;
            throw new \RuntimeException('Direct shipping order storage is not initialized.');
        }
        $shipment=$quote['shipment'] ?? null;
        if (in_array($provider,['usps','ups'],true)
            && (!is_array($shipment) || empty($shipment['origin']) || empty($shipment['packages']))) {
            throw new \RuntimeException('Direct shipping parcel snapshot is missing.');
        }
        $fulfillment=null;
        if ($provider==='usps') {
            if (!self::hasFulfillmentColumn($db)) throw new \RuntimeException('USPS fulfillment storage is not initialized.');
            $options=$rate['parcel_services'] ?? null;
            if (!is_array($options) || !array_is_list($options)
                || count($options)!==count($shipment['packages'])) {
                throw new \RuntimeException('USPS parcel service selections are missing.');
            }
            $sum=0;
            foreach ($options as $option) {
                if (!is_array($option)
                    || !in_array($option['rate_indicator'] ?? '',['SP','DR'],true)
                    || !in_array($option['processing_category'] ?? '',['MACHINABLE','NONSTANDARD'],true)
                    || ($option['destination_entry_facility_type'] ?? '')!=='NONE'
                    || !in_array($option['price_type'] ?? '',['RETAIL','COMMERCIAL'],true)
                    || !is_int($option['quoted_cents'] ?? null) || $option['quoted_cents']<1) {
                    throw new \RuntimeException('Invalid USPS parcel service selection.');
                }
                $sum+=$option['quoted_cents'];
            }
            if ($sum!==ApplePayContext::catalogCents($rate['total_charge'] ?? null)) {
                throw new \RuntimeException('USPS parcel charges do not match the selected rate.');
            }
            $fulfillment=json_encode($options,JSON_THROW_ON_ERROR);
        }
        $short=static function($value,int $max): string {
            if (!is_string($value) || $value==='' || strlen($value)>$max
                || preg_match('/[\x00-\x1f\x7f]/',$value)) throw new \RuntimeException('Invalid shipping rate metadata.');
            return $value;
        };
        $stmt=$db->prepare('INSERT INTO order_shipping
            (order_id,provider,courier_id,service_code,courier_name,service_name,quoted_cents,currency,
             rate_basis,quote_hash,quote_expires_at,origin_json,packages_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $courierId=$rate['courier_id'] ?? null;
        if (is_int($courierId)) $courierId=(string)$courierId;
        $stmt->execute([$orderId,$provider,$short($courierId,128),
            isset($rate['service_code']) ? $short($rate['service_code'],80) : null,
            $short($rate['courier_name'] ?? null,128),$short($rate['service_name'] ?? null,128),
            ApplePayContext::catalogCents($rate['total_charge'] ?? null),'USD',
            isset($rate['rate_basis']) ? $short($rate['rate_basis'],40) : null,
            hash('sha256',$key),(int)$quote['expires'],
            $shipment===null ? null : json_encode($shipment['origin'] ?? null,JSON_THROW_ON_ERROR),
            $shipment===null ? null : json_encode($shipment['packages'] ?? null,JSON_THROW_ON_ERROR)]);
        if ($fulfillment!==null) {
            $stmt=$db->prepare('UPDATE order_shipping SET fulfillment_json=? WHERE order_id=?');
            $stmt->execute([$fulfillment,$orderId]);
        }
    }

    private static function hasFulfillmentColumn(\PDO $db): bool
    {
        foreach ($db->query('PRAGMA table_info(order_shipping)') as $column) {
            if ($column['name']==='fulfillment_json') return true;
        }
        return false;
    }
}
