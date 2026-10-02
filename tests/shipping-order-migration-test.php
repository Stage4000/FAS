<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingOrder.php';

use FAS\Shipping\ShippingOrder;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE orders(id INTEGER PRIMARY KEY);
    INSERT INTO orders VALUES(1);
    CREATE TABLE order_shipping (
        order_id INTEGER PRIMARY KEY REFERENCES orders(id) ON DELETE CASCADE,
        provider TEXT NOT NULL,courier_id TEXT NOT NULL,service_code TEXT,
        courier_name TEXT NOT NULL,service_name TEXT NOT NULL,quoted_cents INTEGER NOT NULL,
        currency TEXT NOT NULL DEFAULT 'USD',rate_basis TEXT,quote_hash TEXT NOT NULL,
        quote_expires_at INTEGER NOT NULL,origin_json TEXT,packages_json TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
    INSERT INTO order_shipping (order_id,provider,courier_id,courier_name,service_name,
        quoted_cents,quote_hash,quote_expires_at)
        VALUES (1,'easyship','legacy-1','Legacy','Ground',1500,'old-hash',1)");
if (ShippingOrder::directReady($db)) throw new RuntimeException('Legacy order storage was treated as direct-ready.');
ShippingOrder::install($db);
ShippingOrder::install($db);
$columns=array_column($db->query('PRAGMA table_info(order_shipping)')->fetchAll(PDO::FETCH_ASSOC),'name');
if (!in_array('carrier_quote_cents',$columns,true) || !in_array('fulfillment_json',$columns,true)
    || !ShippingOrder::directReady($db)) {
    throw new RuntimeException('Additive shipping order columns were not installed.');
}
$row=ShippingOrder::find($db,1);
if (!$row || $row['provider']!=='easyship' || (int)$row['quoted_cents']!==1500
    || $row['carrier_quote_cents']!==null || $row['courier_id']!=='legacy-1') {
    throw new RuntimeException('Historical Easyship order changed during migration.');
}
echo 'PASS additive order-shipping migration retains historical Easyship selection.'.PHP_EOL;
