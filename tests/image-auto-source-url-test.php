<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/image-auto-pilot/ImageSourceIntake.php';
use FAS\Utils\ImageSourceIntake;
$checks=0;
foreach (['https://i.ebayimg.com/a.jpg','https://i.ebayimg.com/images/g/item/s-l1600.png','https://i.ebayimg.com/a.webp?x=1&y=2','https://i.ebayimg.com/a,b.jpg'] as $url) {
    ImageSourceIntake::source($url); $checks++;
}
foreach (['','http://i.ebayimg.com/a.jpg','https://I.EBAYIMG.COM/a.jpg','https://i.ebayimg.com','https://i.ebayimg.com:443/a.jpg','https://u@i.ebayimg.com/a.jpg','https://u:p@i.ebayimg.com/a.jpg','https://i.ebayimg.com/a.jpg#x','https://i.ebayimg.com.evil.invalid/a.jpg','https://evil.invalid/i.ebayimg.com/a.jpg','https://i.ebayimg.com./a.jpg','//i.ebayimg.com/a.jpg','file:///a.jpg','https://127.0.0.1/a.jpg','https://[::1]/a.jpg','https://i.ebayimg.com/a.jpg,','https://i.ebayimg.com\\@evil.invalid/a.jpg','https://i.ebayimg.com/%20a.jpg '."\n",'https://i.ebayimg.com/'.str_repeat('x',2048),"https://i.ebayimg.com/a\0b.jpg","https://i.ebayimg.com/a\tb.jpg"] as $url) {
    try { ImageSourceIntake::source($url); } catch (Throwable $e) { $checks++; continue; }
    throw new RuntimeException('Accepted invalid source: '.json_encode($url));
}
$transport=new \FAS\Utils\CurlImageIntakeTransport();
try {$transport->get('https://i.ebayimg.com/a.jpg',static function(){throw new RuntimeException('headers called');},static function(){throw new RuntimeException('chunk called');});}
catch (Throwable $e) {if($e->getMessage()!=='Network source transport disabled')throw $e; $checks++;}
if($checks!==26)throw new RuntimeException('Expected all source boundary checks');
echo json_encode(['status'=>'PASS','checks'=>$checks,'php'=>PHP_VERSION,'network'=>false,'pdo'=>false])."\n";
