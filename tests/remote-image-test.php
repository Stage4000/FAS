<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/ResponsiveImage.php';
use FAS\Utils\ResponsiveImage;
$checks=0;
function remoteCheck(bool $ok,string $message): void { global $checks; if(!$ok)throw new RuntimeException($message);$checks++; }
remoteCheck(method_exists(ResponsiveImage::class,'importRemote'),'Offline remote import is available');
$base=sys_get_temp_dir().'/fas-remote-test-'.bin2hex(random_bytes(6));
$root=$base.'/site';$inputs=$base.'/inputs';mkdir($root.'/gallery',0755,true);mkdir($inputs,0700,true);
$image=imagecreatetruecolor(1600,1200);
for($x=0;$x<1600;$x++)imageline($image,$x,0,$x,1199,imagecolorallocate($image,$x%256,($x*7)%256,($x*17)%256));
imagepng($image,$inputs.'/original.png',0);imagedestroy($image);
$url='https://i.ebayimg.com/images/g/example/s-l1600.png?set_id=fixture';
$record=['product_id'=>'9876','source_url'=>$url,'local_file'=>'original.png','source_sha256'=>hash_file('sha256',$inputs.'/original.png'),'verified_at'=>gmdate('c'),'valid_until'=>gmdate('c',time()+86400),
    'provenance'=>['basis'=>'existing_catalog','reference'=>'synthetic-test-only','optimization_authorized'=>true,'excluded'=>false]];
$original=ResponsiveImage::attributes($url,'50vw',0,$root,'9876');
remoteCheck($original==='src="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'"','Unimported original remains fallback');
$result=ResponsiveImage::importRemote($record,$inputs,$root);
remoteCheck($result['status']==='ready' && count($result['variant_bytes'])===6,'Imported original yields six smaller verified variants');
$attrs=ResponsiveImage::attributes($url,'50vw',0,$root,'9876');
remoteCheck(str_contains($attrs,'srcset=') && str_contains($attrs,'sizes="50vw"'),'Verified import supplies responsive candidates');
remoteCheck(str_contains($attrs,'width="1600" height="1200"'),'Remote dimensions are measured from actual bytes');
remoteCheck(str_starts_with($attrs,$original),'Original source URL preserved exactly');
remoteCheck(str_contains($attrs,htmlspecialchars($url,ENT_QUOTES,'UTF-8').' 1600w'),'Original native resolution remains a candidate for high-resolution display');
remoteCheck(!str_contains(ResponsiveImage::attributes($url,'80px',320,$root,'9876'),' 640w'),'Thumbnail ceiling is respected');
remoteCheck(!str_contains(ResponsiveImage::attributes($url,'50vw',0,$root),'srcset='),'Remote receipt requires explicit product context');
remoteCheck(!str_contains(ResponsiveImage::attributes($url,'50vw',0,$root,'9877'),'srcset='),'Remote receipt cannot cross products');
remoteCheck(!str_contains(ResponsiveImage::attributes($url.'&changed=1','50vw',0,$root,'9876'),'srcset='),'Exact source URL is required');
remoteCheck(hash_file('sha256',$inputs.'/original.png')===$record['source_sha256'],'Input original bytes never change');
remoteCheck(ResponsiveImage::importRemote($record,$inputs,$root)['variant_bytes']===$result['variant_bytes'],'Repeated imports produce identical candidates');
foreach($result['variant_bytes'] as $width=>$bytes) {
    $file=$root.'/gallery/responsive/remote-'.$record['source_sha256'].'-'.$width.'.webp';
    $size=getimagesize($file);
    remoteCheck($size[0]===$width && $size[1]===(int)round(1200*$width/1600),'Accurate width descriptor and aspect ratio');
    remoteCheck($bytes<$result['original_bytes'],'Only smaller variants advertised');
}
$manifest=$root.'/gallery/responsive/remote-'.hash('sha256','9876'."\n".$url).'.json';
$receipt=file_get_contents($manifest);
remoteCheck(!str_contains($receipt,$inputs) && !str_contains($receipt,'synthetic-test-only'),'Published receipt excludes private local path and source evidence');
$decoded=json_decode($receipt,true,512,JSON_THROW_ON_ERROR);
remoteCheck($decoded['source_sha256']===$record['source_sha256'] && $decoded['product_id']==='9876' && $decoded['source_url']===$url,'Receipt binds source URL, product and original hash');
foreach(['http://i.ebayimg.com/a.jpg','https://evil.example/a.jpg','https://i.ebayimg.com.evil.example/a.jpg','https://127.0.0.1/a.jpg','https://[::1]/a.jpg','https://i.ebayimg.com@evil.example/a.jpg','https://user@i.ebayimg.com/a.jpg','https://i.ebayimg.com:443/a.jpg','//i.ebayimg.com/a.jpg','file:///etc/passwd',"https://i.ebayimg.com/a\n.jpg",'https://i.ebayimg.com/a.jpg#fragment','https://i.ebayimg.com/a.jpg,','https://i.ebayimg.com\\@evil.example/a.jpg'] as $bad) {
    $test=$record;$test['source_url']=$bad;
    remoteCheck(ResponsiveImage::importRemote($test,$inputs,$root)['status']==='skipped','Disallowed URL is never imported: '.json_encode($bad));
    remoteCheck(!str_contains(ResponsiveImage::attributes($bad,'50vw',0,$root,'9876'),'srcset='),'Disallowed source remains unoptimized');
}
foreach([
    ['provenance'=>['basis'=>'existing_catalog','reference'=>'fixture','optimization_authorized'=>false,'excluded'=>false]],
    ['provenance'=>['basis'=>'unrelated_scrape','reference'=>'URL','optimization_authorized'=>true,'excluded'=>false]],
    ['provenance'=>['basis'=>'existing_catalog','reference'=>'','optimization_authorized'=>true,'excluded'=>false]],
    ['source_sha256'=>str_repeat('0',64)],['product_id'=>'../9876'],['local_file'=>'../inputs/original.png'],
    ['local_file'=>$inputs.'/original.png'],['local_file'=>'https://i.ebayimg.com/a.png'],
    ['verified_at'=>gmdate('c',time()+86400)],['valid_until'=>gmdate('c',time()-1)],['valid_until'=>gmdate('c',time()+31*86400)],
] as $overrides) {
    remoteCheck(ResponsiveImage::importRemote(array_replace($record,$overrides),$inputs,$root)['status']==='skipped','Invalid provenance, stale verification, or local path is not activated');
    remoteCheck(file_get_contents($manifest)===$receipt,'Rejected import cannot replace valid receipt');
}
$excluded=['product_id'=>'9876','source_url'=>$url,'provenance'=>['excluded'=>true]];
remoteCheck(ResponsiveImage::importRemote($excluded,$inputs,$root)['status']==='excluded','Explicit exclusion revokes without needing a source file or new hash');
remoteCheck(ResponsiveImage::attributes($url,'50vw',0,$root,'9876')===$original,'Explicit exclusion immediately stops advertising previously valid derivatives');
remoteCheck(count(glob($root.'/gallery/responsive/remote-*.webp'))===6,'Exclusion leaves reusable bytes untouched');
remoteCheck(ResponsiveImage::importRemote($record,$inputs,$root)['status']==='ready','A deliberate new authorized import can replace exclusion');
$outside=$base.'/outside.png';copy($inputs.'/original.png',$outside);symlink($outside,$inputs.'/linked.png');
remoteCheck(ResponsiveImage::importRemote(array_replace($record,['local_file'=>'linked.png']),$inputs,$root)['status']==='skipped','Symlink escape is rejected');
$flagged=$decoded;$flagged['excluded']=true;file_put_contents($manifest,json_encode($flagged));
remoteCheck(ResponsiveImage::attributes($url,'50vw',0,$root,'9876')===$original,'Exclusion always wins even if old valid receipt fields remain');
$expired=$decoded;$expired['valid_until']=time()-1;file_put_contents($manifest,json_encode($expired));
remoteCheck(ResponsiveImage::attributes($url,'50vw',0,$root,'9876')===$original,'Expired receipt immediately falls back without static-cache staleness');
file_put_contents($manifest,'{"incomplete":');
remoteCheck(ResponsiveImage::attributes($url,'50vw',0,$root,'9876')===$original,'Invalid JSON falls back safely');
file_put_contents($manifest,$receipt);
$variant=$root.'/gallery/responsive/remote-'.$record['source_sha256'].'-320.webp';$bytes=file_get_contents($variant);file_put_contents($variant,'broken');
remoteCheck(!str_contains(ResponsiveImage::attributes($url,'50vw',0,$root,'9876'),' 320w'),'Tampered derivative is not advertised');
file_put_contents($variant,$bytes);
$wrong=$decoded;$wrong['width']=800;file_put_contents($manifest,json_encode($wrong));
remoteCheck(!str_contains(ResponsiveImage::attributes($url,'50vw',0,$root,'9876'),' 320w'),'Mismatched derivative dimensions fail closed');
file_put_contents($manifest,$receipt);
unlink($variant);symlink($outside,$variant);
remoteCheck(!str_contains(ResponsiveImage::attributes($url,'50vw',0,$root,'9876'),' 320w'),'Derivative symlink is not advertised');
unlink($variant);file_put_contents($variant,$bytes);
// Content hash changes invalidate paths even if input size and mtime are reused.
$mtime=filemtime($inputs.'/original.png');$image=imagecreatetruecolor(1600,1200);imagepng($image,$inputs.'/original.png',0);imagedestroy($image);touch($inputs.'/original.png',$mtime);
$new=$record;$new['source_sha256']=hash_file('sha256',$inputs.'/original.png');
remoteCheck($new['source_sha256']!==$record['source_sha256'],'Replacement has different hash');
remoteCheck(ResponsiveImage::importRemote($new,$inputs,$root)['status']==='ready','Replacement original can be reverified');
remoteCheck(!str_contains(ResponsiveImage::attributes($url,'50vw',0,$root,'9876'),$record['source_sha256']),'Replacement receipt stops advertising old content');
// Feed/schema and full-size links are not fed derivative URLs.
require_once __DIR__.'/../includes/sale-helper.php';require_once __DIR__.'/../src/utils/MerchantFeedBuilder.php';require_once __DIR__.'/../src/models/Product.php';
$db=new PDO('sqlite::memory:');$db->exec(file_get_contents(__DIR__.'/fixtures/security-catalog.sql'));$product=$db->query('SELECT * FROM products LIMIT 1')->fetch(PDO::FETCH_ASSOC);$product['image_url']=$url;
$feed=(new FAS\Utils\MerchantFeedBuilder(new FAS\Models\Product($db)))->buildItem($product);
$schema=FAS\Utils\Seo::productSchema($product,[$url],['effective_price'=>100],FAS\Utils\Seo::productUrl($product),'Fixture');
remoteCheck($feed['image_link']===$url,'Merchant feed keeps original URL');
remoteCheck($schema['image'][0]===$url,'Product schema keeps original URL');
function removeRemoteTest(string $dir): void { foreach(scandir($dir) as $p) { if($p==='.'||$p==='..')continue;$file=$dir.'/'.$p;if(is_dir($file)&&!is_link($file))removeRemoteTest($file);else unlink($file); }rmdir($dir); }
removeRemoteTest($base);
echo 'PASS '.$checks." offline remote-image assertions\n";
