<?php
declare(strict_types=1);
// Isolated real retry adapter + Product model. No application bootstrap, credentials, or provider calls.
namespace FAS\Integrations {
    final class EbayAPI {
        public static ?string $currency = 'USD';
        public function __construct(...$unused) {}
        public function getItemDetails($id): array {
            return ['item'=>['Title'=>'Synthetic retry item', 'SellingStatus'=>['CurrentPrice'=>['__value__'=>'110.00']],
                'Currency'=>self::$currency, 'Quantity'=>7], 'weight'=>1, 'length'=>10, 'width'=>10, 'height'=>10,
                'brand'=>null, 'mpn'=>null, 'condition'=>'Used', 'sku'=>'QA-1', 'description'=>'Fixture', 'images'=>[]];
        }
    }
}
namespace FAS\Utils {
    final class EbaySyncHealth {
        public function markItemRetried(...$unused): void {}
        public function recordFailedItem(...$unused): void {}
    }
    final class ErrorMonitor {
        public const AREA_EBAY_SYNC = 'sync';
        public function __construct(...$unused) {}
        public function recordThrowable(...$unused): void {}
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    $root = dirname(__DIR__);
    if (is_file($root.'/src/config/config.php')) throw new RuntimeException('Credential-free isolated source required.');
    require_once $root.'/src/models/Product.php';
    $source = file_get_contents($root.'/admin/ebay-sync-health.php');
    $start = strpos($source, 'function eshRetryItem(');
    $end = $start === false ? false : strpos($source, "\nif (\$_SERVER['REQUEST_METHOD'] === 'POST')", $start);
    if ($start === false || $end === false) throw new RuntimeException('Retry adapter boundary changed.');
    // Extract only the real pure adapter function, never execute the admin page bootstrap.
    eval('use FAS\\Integrations\\EbayAPI; use FAS\\Utils\\EbaySyncHealth; use FAS\\Utils\\ErrorMonitor; use FAS\\Models\\Product; '.substr($source,$start,$end-$start));
    $checks = 0;
    $verify = static function(bool $ok,string $why) use (&$checks): void {
        $checks++; if (!$ok) throw new RuntimeException($why);
    };
    foreach ([['USD',false],['EUR',false],[null,false],['USD',true]] as [$currency,$override]) {
        $db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec(file_get_contents($root.'/tests/fixtures/security-catalog.sql'));
        $db->exec("UPDATE products SET ebay_item_id='QA-1',source='ebay',sale_price=80 WHERE id=1");
        \FAS\Utils\ProductSyncOwnership::install($db);
        \FAS\Utils\WebsitePrice::install($db);
        if ($override) {
            $db->beginTransaction();
            \FAS\Utils\WebsitePrice::sync($db,1,['price'=>'100.00','currency'=>'USD']);
            $db->commit();
            \FAS\Utils\WebsitePrice::save($db,1,\FAS\Utils\WebsitePrice::snapshot($db,1),17,'override','65.25','USD');
        }
        $before = $db->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC);
        \FAS\Integrations\EbayAPI::$currency = $currency;
        [$ok,$message] = eshRetryItem($db,new \FAS\Utils\EbaySyncHealth(),[],'/unused-fixture-config','QA-1');
        $after = $db->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC);
        $observations = $db->query('SELECT * FROM website_price_source')->fetchAll(PDO::FETCH_ASSOC);
        if ($currency !== 'USD') {
            $verify(!$ok,'Unknown/non-USD currency must fail closed.');
            $verify($before === $after,'Invalid currency must preserve every product value.');
            $verify($observations === [],'Invalid currency must not create a source observation.');
            continue;
        }
        $verify($ok,'Valid USD retry must succeed: '.$message);
        $verify(count($observations) === 1 && (int)$observations[0]['amount_cents'] === 11000
            && $observations[0]['currency'] === 'USD','Retry must record actual USD source price.');
        $verify((float)$after[0]['price'] === ($override ? 65.25 : 110.0),'Retry must preserve enabled override or adopt unowned source.');
        $verify((int)$after[0]['quantity'] === 7,'Normal retry stock update remains intact.');
        $verify($after[0]['name'] === $before[0]['name'] && $after[0]['description'] === $before[0]['description'],
            'Existing text ownership remains intact.');
    }
    echo "PASS {$checks} retry currency assertions; mocked provider and disposable SQLite only.\n";
}
