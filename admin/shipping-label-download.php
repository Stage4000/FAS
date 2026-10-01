<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../src/shipping/ShippingConfig.php';
require_once __DIR__.'/../src/shipping/ShippingCache.php';
require_once __DIR__.'/../src/shipping/ShippingLabelOperations.php';
require_once __DIR__.'/../src/shipping/ShippingLabelCancellations.php';

use FAS\Config\Database;
use FAS\Shipping\{ShippingConfig,ShippingCache,ShippingLabelOperations,ShippingLabelCancellations};

$auth=new AdminAuth();
$admin=$auth->requireActiveAdmin();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
$id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$package=filter_var($_GET['package'] ?? null,FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>0,'max_range'=>9]]);
$piece=filter_var($_GET['piece'] ?? null,FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>0,'max_range'=>9]]);
if (!$id || $package===false || $piece===false) { http_response_code(400); exit('Invalid label request.'); }
try {
    $config=ShippingConfig::load();
    $cache=new ShippingCache($config['cache_path']);
    $labels=new ShippingLabelOperations($cache->database());
    $cancel=(new ShippingLabelCancellations($cache->database()))->find((int)$id,$package);
    if ($cancel) throw new RuntimeException('This label has a cancellation operation.');
    $item=$labels->label(Database::getInstance()->getConnection(),(int)$id,$package,$piece,(int)$admin['id']);
    $mime=$item['format']==='pdf' ? 'application/pdf' : 'image/gif';
    $ext=$item['format']==='pdf' ? 'pdf' : 'gif';
    header('Content-Type: '.$mime);
    header('Content-Disposition: attachment; filename="order-'.$id.'-parcel-'.($package+1).'-label-'.($piece+1).'.'.$ext.'"');
    header('Content-Length: '.strlen($item['label']));
    echo $item['label'];
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Confirmed label is unavailable.');
}
