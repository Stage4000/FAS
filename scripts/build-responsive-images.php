<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../src/utils/ResponsiveImage.php';
use FAS\Utils\ResponsiveImage;
$options=getopt('',['source:','inventory','limit:','offset:','remote-manifest:','remote-status','help']);
if(isset($options['help']) || (!isset($options['source']) && !isset($options['inventory']) && !isset($options['remote-manifest']) && !isset($options['remote-status']))) {
    echo "Build smaller image copies; originals are never changed. No URLs are downloaded.\n";
    echo "php scripts/build-responsive-images.php --source=/gallery/example.jpg\n";
    echo "php scripts/build-responsive-images.php --inventory --limit=100 --offset=0\n";
    echo "php scripts/build-responsive-images.php --remote-manifest=/private/reviewed-images/manifest.json\n";
    echo "php scripts/build-responsive-images.php --remote-status --limit=100 --offset=0\n";exit;
}
try {
    if(isset($options['remote-status'])) {
        if(isset($options['source']) || isset($options['inventory']) || isset($options['remote-manifest']))throw new InvalidArgumentException('Use remote-status without build options.');
        require_once __DIR__.'/../src/utils/ImportedImage.php';
        $warning=false;
        foreach(\FAS\Utils\ImportedImage::status(dirname(__DIR__),(int)($options['limit']??100),(int)($options['offset']??0)) as $result) {
            echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
            if(!in_array($result['status'],['ready','excluded'],true))$warning=true;
        }
        exit($warning?2:0);
    }
    if(isset($options['remote-manifest'])) {
        if(isset($options['source']) || isset($options['inventory']) || !is_string($options['remote-manifest']))throw new InvalidArgumentException('Use one remote manifest without source or inventory options.');
        $file=realpath($options['remote-manifest']);
        if(!$file || !is_file($file))throw new InvalidArgumentException('Remote import manifest must be a local file.');
        $json=file_get_contents($file,false,null,0,1024*1024+1);
        if($json===false || strlen($json)>1024*1024)throw new InvalidArgumentException('Manifest exceeds 1 MiB.');
        $manifest=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($manifest) || ($manifest['version']??null)!==1 || !is_array($manifest['images']??null)
            || !array_is_list($manifest['images']) || count($manifest['images'])<1 || count($manifest['images'])>500)throw new InvalidArgumentException('Manifest version 1 requires 1–500 image records.');
        $incomplete=false;
        foreach($manifest['images'] as $record) {
            $result=is_array($record)?ResponsiveImage::importRemote($record,dirname($file)):['status'=>'skipped','reason'=>'Image record must be an object.'];
            echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
            if(!in_array($result['status'],['ready','excluded'],true))$incomplete=true;
        }
        exit($incomplete?2:0);
    }
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
