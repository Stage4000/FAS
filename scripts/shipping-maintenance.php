<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingOrder.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingLabelCancellations.php';
require_once __DIR__.'/../src/shipping/ShippingTracking.php';
require_once __DIR__.'/../src/shipping/ShippingTrackingService.php';
require_once __DIR__.'/../src/shipping/ShippingNotifications.php';
require_once __DIR__.'/../src/shipping/ShippingReadiness.php';
require_once __DIR__.'/../src/shipping/ShippingCatalogReadiness.php';
require_once __DIR__.'/../src/config/Database.php';
use FAS\Shipping\{ShippingConfig,ShippingCache};

try {
    $command=$argv[1] ?? 'health';
    if (!in_array($command,['init','init-orders','health','readiness','cleanup','refresh-tracking',
        'prepare-notifications','send-notifications','notification-review','resolve-notification'],true)) {
        throw new RuntimeException('Unknown shipping maintenance command.');
    }
    if ($command==='send-notifications' && !in_array('--deliver',$argv,true)) {
        fwrite(STDERR,"Sending requires send-notifications --deliver and verified notification settings.\n");
        exit(1);
    }
    $config=ShippingConfig::load();
    $result=['mode'=>$config['mode'],'parcel_data_verified'=>$config['parcel_data_verified'],
        'credentials_verified_with_carriers'=>false,'carriers'=>[]];
    foreach ($config['carriers'] as $name=>$carrier) {
        $result['carriers'][$name]=['enabled'=>$carrier['enabled'],'environment'=>$carrier['environment'],
            'credentials_present'=>ShippingConfig::ready(array_replace($carrier,['enabled'=>true]),$name,false),
            'checkout_activated'=>ShippingConfig::ready($carrier,$name,true)];
    }
    if ($command==='readiness') {
        // The normal database bootstrap can create missing files/directories. A report must not.
        $siteConfig=require __DIR__.'/../src/config/config.php';
        $orderPath=$siteConfig['database']['path'] ?? __DIR__.'/../database/flipandstrip.db';
        $orderDb=is_string($orderPath) && is_file($orderPath)
            ? new PDO('sqlite:'.$orderPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]) : null;
    } else {
        $orderDb=\FAS\Config\Database::getInstance()->getConnection();
    }
    if ($command==='init' || $command==='init-orders') \FAS\Shipping\ShippingOrder::install($orderDb);
    $result['order_shipping_storage']=$orderDb!==null && \FAS\Shipping\ShippingOrder::installed($orderDb);
    $result['direct_order_storage']=$orderDb!==null && \FAS\Shipping\ShippingOrder::directReady($orderDb);
    if ($command==='readiness') {
        $result['catalog']=\FAS\Shipping\ShippingCatalogReadiness::report($orderDb);
    }
    if ($command==='init-orders') {
        echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit;
    }
    if ($config['mode']!=='easyship' && !$result['direct_order_storage'] && $command!=='readiness') {
        throw new RuntimeException('Order shipping storage is not initialized.');
    }
    if ($config['cache_path']==='' && ($command==='readiness' || ($command==='health' && $config['mode']==='easyship'))) {
        $result['cache']=['initialized'=>false,'required_for'=>'direct carriers'];
    } else {
        try {
            $cache=new ShippingCache($config['cache_path'],$command==='init');
        } catch (Throwable $e) {
            if ($command!=='readiness') throw $e;
            $result['cache']=['healthy'=>false,'requires'=>'verify private storage path, permissions and initialization'];
            $result['readiness']=\FAS\Shipping\ShippingReadiness::report($config,$result);
            echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
            exit;
        }
        if ($command==='init') {
            \FAS\Shipping\ShippingLabelOperations::install($cache->database());
            \FAS\Shipping\ShippingTracking::install($cache->database());
            \FAS\Shipping\ShippingNotifications::install($cache->database());
        }
        $result['cache']=$cache->health();
        $labelTable=$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_label_operations'")->fetchColumn();
        $packageTable=$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_label_packages'")->fetchColumn();
        $handoffTable=$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_label_handoffs'")->fetchColumn();
        $reprintTable=$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_label_reprints'")->fetchColumn();
        $resolutionTable=$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_label_resolutions'")->fetchColumn();
        $cancelTable=$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_label_cancellations'")->fetchColumn();
        $trackingTable=$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_tracking'")->fetchColumn();
        $notificationTable=$cache->database()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_notifications'")->fetchColumn();
        $result['label_operations']=$labelTable && $packageTable
            ? (new \FAS\Shipping\ShippingLabelOperations($cache->database()))->health()
            : ['initialized'=>false];
        $result['label_storage']=$packageTable
            ? ['initialized'=>true,'packages'=>(int)$cache->database()->query('SELECT COUNT(*) FROM shipping_label_packages')->fetchColumn()]
            : ['initialized'=>false];
        $result['reservation_handoff_storage']=['initialized'=>(bool)$handoffTable];
        $result['label_reprint_storage']=['initialized'=>(bool)$reprintTable];
        $result['label_resolution_storage']=['initialized'=>(bool)$resolutionTable];
        $result['label_cancellations']=$cancelTable
            ? (new \FAS\Shipping\ShippingLabelCancellations($cache->database()))->health()
            : ['initialized'=>false];
        $result['label_cancellation_storage']=['initialized'=>(bool)$cancelTable];
        $result['cancellation_review_storage']=['initialized'=>(bool)$cache->database()->query(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name='shipping_cancellation_resolutions'")->fetchColumn()];
        $result['tracking']=$trackingTable
            ? (new \FAS\Shipping\ShippingTracking($cache->database()))->health()
            : ['initialized'=>false];
        $result['tracking_storage']=['initialized'=>(bool)$trackingTable];
        $notifications=$notificationTable && $orderDb!==null
            ? new \FAS\Shipping\ShippingNotifications($cache->database(),$orderDb,$config) : null;
        $result['notifications']=$notifications ? $notifications->health() : ['initialized'=>false];
        if (in_array($command,['prepare-notifications','send-notifications','notification-review','resolve-notification'],true)) {
            if (!$notifications) throw new RuntimeException('Notification storage is not initialized.');
            if ($command==='prepare-notifications') $result['notification_preparation']=$notifications->prepare();
            if ($command==='notification-review') $result['notification_review']=$notifications->attention();
            if ($command==='resolve-notification') {
                if (!preg_match('/^[1-9][0-9]{0,9}$/D',$argv[2] ?? '')) throw new RuntimeException('Invalid notification reference.');
                $notifications->resolve((int)$argv[2],$argv[3] ?? '',in_array('--verified',$argv,true));
                $result['notification_resolved']=(int)$argv[2];
            }
            if ($command==='send-notifications') {
                $result['notification_delivery']=$notifications->sendBatch(static function(array $mail): bool {
                    $headers=['From: Flip and Strip <'.$mail['from'].'>','Reply-To: '.$mail['reply_to'],
                        'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: 8bit'];
                    return mail($mail['to'],$mail['subject'],$mail['body'],implode("\r\n",$headers));
                });
            }
        }
        if ($command==='refresh-tracking') {
            if (!$trackingTable) throw new RuntimeException('Tracking storage is not initialized.');
            $result['tracking_refresh']=(new \FAS\Shipping\ShippingTrackingService($cache,$config))->refresh();
        }
        if ($command==='cleanup') $result['removed']=$cache->cleanup();
        if ($notifications) $result['notifications']=$notifications->health();
        if (!$result['cache']['healthy']) throw new RuntimeException('Shipping cache health check failed.');
    }
    if ($command==='readiness') $result['readiness']=\FAS\Shipping\ShippingReadiness::report($config,$result);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $e) {
    // Avoid PDO exception paths, credentials and configuration source in CLI output.
    fwrite(STDERR,"Shipping check failed. Verify configuration, private storage permissions and initialization.\n");
    exit(1);
}
