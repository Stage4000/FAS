<?php
namespace {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
}

namespace FAS\Integrations {
    // The real client uses these namespaced transport functions in this process.
    function curl_init() { return new \stdClass(); }
    function curl_setopt($handle, $option, $value)
    {
        if ($option === CURLOPT_HTTPHEADER) {
            $handle->call = $value[1];
        }
        return true;
    }
    function curl_exec($handle)
    {
        return $GLOBALS['ebayTradingFixtures'][$handle->call]
            ?? throw new \RuntimeException('Unexpected eBay request in quantity fixture');
    }
    function curl_getinfo($handle, $option) { return 200; }
    function curl_errno($handle) { return 0; }
    function curl_close($handle) {}
}

namespace {
    require_once __DIR__ . '/../src/integrations/EbayAPI.php';
    require_once __DIR__ . '/../src/utils/SyncLogger.php';
    require_once __DIR__ . '/../src/utils/EbaySyncHealth.php';
    require_once __DIR__ . '/../src/utils/ErrorMonitor.php';
    require_once __DIR__ . '/../src/models/Product.php';

    use FAS\Integrations\EbayAPI;
    use FAS\Models\Product;
    use FAS\Utils\EbaySyncHealth;

    function assertQuantityResult($expected, $actual, string $scenario): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($scenario . '. Expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
    }

    function listingXml(string $id, ?int $total, ?int $sold, string $status = 'Active'): string
    {
        $quantity = $total === null ? '' : "<Quantity>$total</Quantity>";
        $quantitySold = $sold === null ? '' : "<QuantitySold>$sold</QuantitySold>";
        return "<Item><ItemID>$id</ItemID><Title>Fixture listing</Title>$quantity"
            . "<SellingStatus><ListingStatus>$status</ListingStatus>$quantitySold"
            . '<CurrentPrice>12.00</CurrentPrice></SellingStatus>'
            . '<ShippingPackageDetails><WeightMajor unit="lbs">1</WeightMajor>'
            . '<PackageDepth>2</PackageDepth><PackageWidth>3</PackageWidth>'
            . '<PackageLength>4</PackageLength></ShippingPackageDetails></Item>';
    }

    $config = ['ebay' => [
        'app_id' => 'fixture',
        'cert_id' => 'fixture',
        'dev_id' => 'fixture',
        'user_token' => 'fixture',
        'token_expires_at' => time() + 3600,
        'sandbox' => true,
        'site_id' => 0,
    ]];
    $client = new EbayAPI($config);
    $listings = listingXml('partial', 3, 2) . listingXml('full', 3, 3)
        . listingXml('excess', 2, 5) . listingXml('unsold', 4, null)
        . listingXml('negative', 1, -2) . listingXml('ended', 8, 0, 'Completed')
        . listingXml('missing', null, null);

    foreach (['GetSellerEvents', 'GetSellerList'] as $call) {
        $GLOBALS['ebayTradingFixtures']['X-EBAY-API-CALL-NAME: ' . $call]
            = "<{$call}Response><Ack>Success</Ack><ItemArray>$listings</ItemArray></{$call}Response>";
        $result = $call === 'GetSellerEvents' ? $client->getSellerEvents() : $client->getStoreItems();
        assertQuantityResult(true, is_array($result), $call . ' returns the fixture');
        assertQuantityResult(['partial' => 1, 'unsold' => 4, 'negative' => 1],
            array_column($result['items'], 'quantity', 'id'), $call . ' available quantities');
        assertQuantityResult(['full', 'excess', 'ended', 'missing'], $result['inactive_item_ids'],
            $call . ' inactive listing IDs');
    }

    // Run the production retry operation without loading the dashboard or authentication.
    $controller = file_get_contents(__DIR__ . '/../admin/ebay-sync-health.php');
    $start = strpos($controller, 'function eshRetryItem(');
    $end = strpos($controller, 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\') {');
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('Admin retry function boundary changed');
    }
    eval('use FAS\Integrations\EbayAPI; use FAS\Models\Product; '
        . 'use FAS\Utils\EbaySyncHealth; use FAS\Utils\ErrorMonitor; '
        . substr($controller, $start, $end - $start));

    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schema = file_get_contents(__DIR__ . '/../database/schema.sqlite.sql');
    $db->exec(substr($schema, 0, strpos($schema, '-- Categories table')));
    $health = new EbaySyncHealth($db);
    $health->ensureTables();
    $products = new Product($db);

    foreach ([[3, 2, 1], [3, 3, 0], [2, 5, 0], [4, null, 4], [1, -2, 1], [null, null, 1]] as $case) {
        [$total, $sold, $expected] = $case;
        $GLOBALS['ebayTradingFixtures']['X-EBAY-API-CALL-NAME: GetItem']
            = '<GetItemResponse><Ack>Success</Ack>' . listingXml('retry', $total, $sold) . '</GetItemResponse>';
        [$ok, $message] = eshRetryItem($db, $health, $config, '', 'retry');
        assertQuantityResult(true, $ok, $message);
        assertQuantityResult($expected, (int) $products->getByEbayId('retry')['quantity'],
            'Retry must persist the available quantity');
    }
    unset($GLOBALS['ebayTradingFixtures']);
    echo "eBay remaining quantity passed for seller lists, seller events, and persisted admin retries\n";
}
