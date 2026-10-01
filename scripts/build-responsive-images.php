<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../src/utils/ResponsiveImage.php';
use FAS\Utils\ResponsiveImage;
$options=getopt('',['source:','inventory','limit:','offset:','help']);
if(isset($options['help']) || (!isset($options['source']) && !isset($options['inventory']))) {
    echo "Build smaller local image copies; originals are never changed. No remote images are downloaded.\n";
    echo "php scripts/build-responsive-images.php --source=/gallery/example.jpg\n";
    echo "php scripts/build-responsive-images.php --inventory --limit=100 --offset=0\n";exit;
}
try {
    $sources=(array)($options['source']??[]);
    if(isset($options['inventory'])) {
        require_once __DIR__.'/../src/config/Database.php';
        $db=\FAS\Config\Database::getInstance()->getConnection();
        $limit=max(1,min(500,(int)($options['limit']??100)));$offset=max(0,(int)($options['offset']??0));
        $rows=$db->query('SELECT image_url,images FROM products WHERE is_active=1 AND show_on_website=1 ORDER BY id LIMIT '.$limit.' OFFSET '.$offset)->fetchAll();
        foreach($rows as $row) {
            $sources[]=$row['image_url']??'';
            $extra=json_decode($row['images']??'[]',true);
            if(is_array($extra))foreach($extra as $image)if(is_string($image))$sources[]=$image;
        }
    }
    foreach(array_unique(array_filter($sources,'is_string')) as $source)if($source!=='')echo json_encode(ResponsiveImage::build($source),JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
