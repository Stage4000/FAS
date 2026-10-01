<?php
declare(strict_types=1);
require_once __DIR__.'/security.php';
require_once __DIR__.'/../src/marketing/Growth.php';
function fas_growth(): \FAS\Marketing\Growth {
    static $growth;
    if($growth)return $growth;
    require_once __DIR__.'/../src/config/Database.php';
    require_once __DIR__.'/product-merchandising.php';
    $config=require __DIR__.'/../src/config/config.php';
    return $growth=\FAS\Marketing\Growth::open(\FAS\Config\Database::getInstance()->getConnection(),$config['site']??[],static function(array $p): array {
        return ['price'=>getEffectivePrice((float)$p['price'],isset($p['sale_price'])?(float)$p['sale_price']:null)['effective_price'],
            'free_shipping'=>\FAS\Utils\ShippingRules::productQualifiesForFreeShipping($p),
            'image'=>fasProductImagePath($p['image_url']??null),'image_alt'=>\FAS\Utils\ProductAltText::forProductImage($p),
            'manufacturer'=>(string)($p['manufacturer']??''),'source'=>(string)($p['source']??'')];
    });
}
