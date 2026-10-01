<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/ResponsiveImage.php';
require_once __DIR__.'/../src/utils/MerchantFeedBuilder.php';
require_once __DIR__.'/../src/models/Product.php';
require_once __DIR__.'/../includes/sale-helper.php';
use FAS\Utils\{ResponsiveImage, ProductCondition, Seo, MerchantFeedBuilder};
$checks=0;
function verifyPresentation($ok,$message) {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$db=new PDO('sqlite::memory:');
$db->exec(file_get_contents(__DIR__.'/fixtures/security-catalog.sql'));
$product=$db->query('SELECT * FROM products LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$builder=new MerchantFeedBuilder(new FAS\Models\Product($db));
foreach ([
    ['New with tags','new','NewCondition'], [' NEW ','new','NewCondition'],
    ['Like new','used','UsedCondition'], ['New other (see details)','used','UsedCondition'],
    ['New without box','used','UsedCondition'], ['Used','used','UsedCondition'],
    ['Manufacturer refurbished','refurbished','RefurbishedCondition'],
    ['For parts or not working','used','DamagedCondition'], ['Unspecified','used','UsedCondition'],
] as [$label,$merchant,$schema]) {
    $product['condition_name']=$label;
    $feed=$builder->buildItem($product);
    $data=Seo::productSchema($product,[$product['image_url']],['effective_price'=>100],Seo::productUrl($product),'Fixture notes');
    verifyPresentation($feed['condition']===$merchant && $data['offers']['itemCondition']==='https://schema.org/'.$schema,'Feed/schema condition: '.$label);
}
foreach (['n/a','NA','N.A.','None','Does Not Apply','not applicable','unknown','--'] as $value) {
    $product['model']=$value;
    $feed=$builder->buildItem($product);
    $data=Seo::productSchema($product,[$product['image_url']],['effective_price'=>100],Seo::productUrl($product),'Fixture notes');
    verifyPresentation($feed['mpn']==='' && !isset($data['mpn']),'Placeholder MPN omitted: '.$value);
}
foreach (['2203143','XR 100','PWR128-101'] as $value) verifyPresentation(ProductCondition::identifier($value)===$value,'Identifier not guessed or rewritten');
$root=sys_get_temp_dir().'/fas-image-test-'.bin2hex(random_bytes(5));
mkdir($root.'/gallery',0755,true);
mkdir($root.'/public/uploads',0755,true);
copy(__DIR__.'/../gallery/FLIPANDSTRIP.COM_d00a_018a.jpg',$root.'/gallery/part.jpg');
$source='/gallery/part.jpg';
$hash=hash_file('sha256',$root.$source);
$before=ResponsiveImage::attributes($source,'50vw',0,$root);
verifyPresentation(str_contains($before,'width=') && !str_contains($before,'srcset='),'Unbuilt images keep original and intrinsic dimensions');
$built=ResponsiveImage::build($source,$root);
verifyPresentation($built['status']==='ready' && count($built['variant_bytes'])>=4,'CLI builder produces responsive options');
foreach ($built['variant_bytes'] as $width=>$bytes) {
    $file=glob($root.'/gallery/responsive/*-'.$width.'.webp')[0];
    $size=getimagesize($file);
    verifyPresentation($size[0]===$width && $size[1]===(int)round($built['height']*$width/$built['width']),'Derivative dimensions preserve aspect ratio');
    verifyPresentation($bytes<$built['original_bytes'],'Derivative is smaller than original');
}
$after=ResponsiveImage::attributes($source,'50vw',0,$root);
verifyPresentation(str_contains($after,'srcset=') && str_contains($after,'sizes="50vw"'),'Built derivatives advertised');
verifyPresentation(str_contains($after,$source.' '.$built['width'].'w'),'Original retained for larger viewports');
verifyPresentation(!str_contains(ResponsiveImage::attributes($source,'80px',320,$root),' 640w'),'Thumbnails do not advertise larger variants');
verifyPresentation(ResponsiveImage::build($source,$root)===$built,'Repeated builds reuse derivative set');
verifyPresentation(hash_file('sha256',$root.$source)===$hash,'Original image is unchanged');
foreach (['https://example.invalid/image.jpg','/gallery/../private.jpg','/public/uploads/missing.png','//example.invalid/image.jpg'] as $bad) {
    verifyPresentation(!str_contains(ResponsiveImage::attributes($bad,'100vw',0,$root),'srcset='),'Unsupported images retain source fallback');
    verifyPresentation(ResponsiveImage::build($bad,$root)['status']==='skipped','Unsupported images are not processed');
}
file_put_contents($root.'/gallery/corrupt.jpg','not an image');
verifyPresentation(ResponsiveImage::attributes('/gallery/corrupt.jpg','100vw',0,$root)==='src="/gallery/corrupt.jpg"','Malformed image does not break rendering');
touch($root.$source,time()+20);clearstatcache();
verifyPresentation(!str_contains(ResponsiveImage::attributes($source,'100vw',0,$root),'srcset='),'Changed original stops advertising stale derivatives');
$alpha=imagecreatetruecolor(800,600);
imagealphablending($alpha,false);imagesavealpha($alpha,true);
imagefill($alpha,0,0,imagecolorallocatealpha($alpha,0,0,0,127));
for($i=0;$i<800;$i++) imageline($alpha,$i,100,$i,599,imagecolorallocatealpha($alpha,$i%255,($i*7)%255,($i*11)%255,0));
imagepng($alpha,$root.'/public/uploads/alpha.png',0);imagedestroy($alpha);
$transparent=ResponsiveImage::build('/public/uploads/alpha.png',$root);
verifyPresentation(!empty($transparent['variant_bytes']),'PNG upload generates WebP options');
preg_match('~(/gallery/responsive/[^ ]+)-80.webp~',ResponsiveImage::attributes('/public/uploads/alpha.png','100vw',0,$root),$match);
$small=imagecreatefromwebp($root.$match[1].'-80.webp');
verifyPresentation(((imagecolorat($small,0,0)>>24)&127)===127,'Transparent backgrounds remain transparent');
imagedestroy($small);
echo 'PASS '.$checks." presentation assertions\n";
echo json_encode(['sample'=>$built,'original_unchanged'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
