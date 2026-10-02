<?php
declare(strict_types=1);
require_once __DIR__.'/../src/shipping/ShippingCatalogReadiness.php';
require_once __DIR__.'/../src/shipping/ShippingRateService.php';
require_once __DIR__.'/../src/shipping/ShippingReadiness.php';

use FAS\Shipping\{ShippingCatalogReadiness,ShippingRateService,ShippingReadiness};

$checks=[];
function catalogCheck(bool $ok,string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks[]=$message;
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
catalogCheck(!ShippingCatalogReadiness::report($db)['schema_initialized'],
    'Missing catalog schema reports unavailable without creating tables');
$incomplete=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$incomplete->exec('CREATE TABLE products(id INTEGER PRIMARY KEY); CREATE TABLE warehouses(id INTEGER PRIMARY KEY)');
catalogCheck(!ShippingCatalogReadiness::report($incomplete)['schema_initialized'],
    'An incomplete legacy catalog schema reports unavailable without failing the CLI');
$db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,warehouse_id INTEGER,weight REAL,length REAL,
    width REAL,height REAL,quantity INTEGER,is_active INTEGER,show_on_website INTEGER);
    CREATE TABLE warehouses(id INTEGER PRIMARY KEY,is_active INTEGER,is_default INTEGER,
    address_line1 TEXT,address_line2 TEXT,city TEXT,state TEXT,postal_code TEXT,country_code TEXT);
    INSERT INTO warehouses VALUES(1,1,1,\'100 Fixture Road\',\'\',\'Test City\',\'KS\',\'66614\',\'US\');
    INSERT INTO products VALUES(1,NULL,1,10,8,6,2,1,1);
    INSERT INTO products VALUES(90,NULL,NULL,NULL,NULL,NULL,0,1,1);
    INSERT INTO products VALUES(91,NULL,NULL,NULL,NULL,NULL,1,0,1);
    INSERT INTO products VALUES(92,NULL,NULL,NULL,NULL,NULL,1,1,0)');
$report=ShippingCatalogReadiness::report($db);
catalogCheck($report['schema_initialized'] && $report['saleable_products']===1
    && $report['complete_measurements']===1 && $report['default_origin']===1,
    'Only saleable products are scanned and the configured default is counted');
catalogCheck($report['data_complete_for_direct_quotes'] && $report['distinct_origin_addresses']===1,
    'Complete single-origin catalog passes the local data scan');
catalogCheck((int)ShippingRateService::originForProduct($db,1)['id']===1,
    'Unassigned product resolves the unique active default');
$db->exec('UPDATE warehouses SET is_default=0 WHERE id=1');
catalogCheck(ShippingRateService::originForProduct($db,1)===null,
    'No configured default cannot silently fall back to an arbitrary active warehouse');
$report=ShippingCatalogReadiness::report($db);
catalogCheck($report['issues']['origin']===1 && !$report['data_complete_for_direct_quotes'],
    'Missing default warehouse is a direct-shipping data gap');
$db->exec('UPDATE warehouses SET is_default=1 WHERE id=1;
    INSERT INTO warehouses VALUES(2,1,1,\'200 Fixture Road\',\'\',\'Other City\',\'CA\',\'90210\',\'US\')');
catalogCheck(ShippingRateService::originForProduct($db,1)===null,
    'Multiple active defaults are rejected for an unassigned product');
$db->exec('UPDATE warehouses SET is_default=0 WHERE id=2;
    INSERT INTO products VALUES(2,2,1,10,8,6,1,1,1)');
$report=ShippingCatalogReadiness::report($db);
catalogCheck($report['assigned_origin']===1 && $report['potential_mixed_origin_carts']
    && !$report['data_complete_for_direct_quotes'],
    'Different active origin addresses are reported as a mixed-cart rollout gap');
$readiness=ShippingReadiness::report(require __DIR__.'/../src/config/shipping.example.php',
    ['direct_order_storage'=>true,'cache'=>['healthy'=>true],'catalog'=>$report]);
catalogCheck(in_array('plan mixed-origin carts before full direct rollout',
        $readiness['carriers']['usps']['configuration_blockers'],true)
    && !in_array('resolve saleable catalog measurement, size and origin gaps',
        $readiness['carriers']['usps']['configuration_blockers'],true),
    'Carrier readiness separates valid multi-origin inventory from missing parcel data');
$db->exec('UPDATE warehouses SET is_active=0 WHERE id=2');
catalogCheck(ShippingRateService::originForProduct($db,2)===null,
    'Explicit inactive assignment never falls back to the default');
$report=ShippingCatalogReadiness::report($db);
catalogCheck($report['issues']['origin']===1 && $report['example_product_ids']['origin']===[2],
    'Inactive assigned origin appears in a bounded product-ID list');
$db->exec('DELETE FROM products WHERE id=2;
    INSERT INTO products VALUES(3,NULL,NULL,10,8,6,1,1,1);
    INSERT INTO products VALUES(4,NULL,1,109,8,6,1,1,1);
    INSERT INTO products VALUES(5,NULL,71,10,8,6,1,1,1)');
$report=ShippingCatalogReadiness::report($db);
catalogCheck($report['issues']['measurements']===1
    && $report['issues']['size']===1 && $report['issues']['usps_weight']===1
    && $report['issues']['usps_size']===1,
    'Missing measurements, oversize parcels and USPS-only exceptions are separated');
catalogCheck($report['example_product_ids']['measurements']===[3]
    && $report['example_product_ids']['size']===[4]
    && $report['example_product_ids']['usps_weight']===[5]
    && $report['example_product_ids']['usps_size']===[4],
    'Gap examples identify products without disclosing addresses or customer data');
catalogCheck(strpos(json_encode($report,JSON_THROW_ON_ERROR),'Fixture Road')===false,
    'Readiness output omits origin street addresses');
$db->exec('DELETE FROM products WHERE id IN (3,4,5);
    INSERT INTO products VALUES(6,NULL,1,100,12,10,1,1,1)');
$report=ShippingCatalogReadiness::report($db);
catalogCheck($report['data_complete_for_direct_quotes'] && $report['issues']['size']===0
    && $report['issues']['usps_size']===1
    && $report['example_product_ids']['usps_size']===[6],
    'UPS-compatible packed product above USPS size limits is reported separately');
$readiness=ShippingReadiness::report(require __DIR__.'/../src/config/shipping.example.php',
    ['direct_order_storage'=>true,'cache'=>['healthy'=>true],'catalog'=>$report]);
catalogCheck(in_array('plan products over USPS weight or size limits',
        $readiness['carriers']['usps']['configuration_blockers'],true)
    && !in_array('plan products over USPS weight or size limits',
        $readiness['carriers']['ups']['configuration_blockers'],true),
    'USPS-only size exception blocks USPS readiness without blocking UPS');
file_put_contents(__DIR__.'/../audit/shipping-catalog-readiness-local.json',json_encode([
    'date'=>gmdate('c'),'scope'=>'synthetic saleable inventory and warehouse origins; read-only scan',
    'checks'=>count($checks),'passed'=>$checks,'live_carrier_calls'=>0,'production_verified'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
echo 'PASS '.count($checks).' shipping catalog readiness assertions; no carrier calls or database writes outside the fixture.'.PHP_EOL;
