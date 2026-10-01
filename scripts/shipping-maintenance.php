<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingOrder.php';
require_once __DIR__.'/../src/config/Database.php';
use FAS\Shipping\{ShippingConfig,ShippingCache};

try {
    $command=$argv[1] ?? 'health';
    if (!in_array($command,['init','init-orders','health','cleanup'],true)) {
        throw new RuntimeException('Usage: php scripts/shipping-maintenance.php init|init-orders|health|cleanup');
    }
    $config=ShippingConfig::load();
    $result=['mode'=>$config['mode'],'parcel_data_verified'=>$config['parcel_data_verified'],
        'credentials_verified_with_carriers'=>false,'carriers'=>[]];
    foreach ($config['carriers'] as $name=>$carrier) {
        $result['carriers'][$name]=['enabled'=>$carrier['enabled'],'environment'=>$carrier['environment'],
            'credentials_present'=>ShippingConfig::ready(array_replace($carrier,['enabled'=>true]),$name,false),
            'checkout_activated'=>ShippingConfig::ready($carrier,$name,true)];
    }
    $orderDb=\FAS\Config\Database::getInstance()->getConnection();
    if ($command==='init' || $command==='init-orders') \FAS\Shipping\ShippingOrder::install($orderDb);
    $result['order_shipping_storage']=\FAS\Shipping\ShippingOrder::installed($orderDb);
    if ($command==='init-orders') {
        echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit;
    }
    if ($config['mode']!=='easyship' && !$result['order_shipping_storage']) {
        throw new RuntimeException('Order shipping storage is not initialized.');
    }
    if ($config['cache_path']==='' && $command==='health' && $config['mode']==='easyship') {
        $result['cache']=['initialized'=>false,'required_for'=>'direct carriers'];
    } else {
        $cache=new ShippingCache($config['cache_path'],$command==='init');
        $result['cache']=$cache->health();
        if ($command==='cleanup') $result['removed']=$cache->cleanup();
        if (!$result['cache']['healthy']) throw new RuntimeException('Shipping cache health check failed.');
    }
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $e) {
    // Avoid PDO exception paths, credentials and configuration source in CLI output.
    fwrite(STDERR,"Shipping check failed. Verify configuration, private storage permissions and initialization.\n");
    exit(1);
}
