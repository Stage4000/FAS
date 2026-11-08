<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../src/utils/ResponsiveImage.php';
use FAS\Utils\ResponsiveImage;
$root=sys_get_temp_dir().'/fas-local-fallback-'.bin2hex(random_bytes(8));
mkdir($root.'/gallery/responsive',0700,true);
try {
file_put_contents($root.'/gallery/original.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAUAAAACgCAIAAADywSLLAAAB7klEQVR4nO3TQQ3AIADAwDE1yEE2svDAhzS5U9BPx17zA5r+1wHAPQNDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIOxNgCgNJrPH0AAAAASUVORK5CYII=',true));
file_put_contents($root.'/gallery/responsive/valid.webp',base64_decode('UklGRk4AAABXRUJQVlA4IEIAAADwAwCdASpQACgAPm02mEkkIyKhIigAgA2JaQB2APwAACel1mXiFxuIAP7kAX//5BcsLrka//+hJ+CT8En7gAAAAAA=',true));
file_put_contents($root.'/gallery/responsive/wrong-height.webp',base64_decode('UklGRjgAAABXRUJQVlA4ICwAAABwAwCdASpQACkAPm02mEkkIyKhIigAgA2JaQAAEDdTUAV4hTAA/vucwAAAAA==',true));
file_put_contents($root.'/gallery/responsive/wrong-width.webp',base64_decode('UklGRjoAAABXRUJQVlA4IC4AAACwAwCdASpRACgAPm02mEkkIyKhIcgAgA2JaQAADbWNB7sxQFwaAAD++5zAAAAA',true));
$file=$root.'/gallery/original.png';$source='/gallery/original.png';
$key=hash('sha256',$source.'|'.filemtime($file).'|'.filesize($file));$path=$root.'/gallery/responsive/'.$key.'-80.webp';
$n=0;function check($ok,$name){global $n;if(!$ok)throw new RuntimeException($name);echo 'PASS '.(++$n).' '.$name."\n";}
function attrs(){global $root,$source;clearstatcache();return ResponsiveImage::attributes($source,'100vw',0,$root);}
if(file_exists($path)||is_link($path))unlink($path);
check(!str_contains(attrs(),'srcset='),'missing derivative original fallback');
file_put_contents($path,'not an image');check(!str_contains(attrs(),'srcset='),'corrupt derivative original fallback');
file_put_contents($path,'');check(!str_contains(attrs(),'srcset='),'empty derivative original fallback');
copy($file,$path);check(!str_contains(attrs(),'srcset='),'PNG pretending to be WebP rejected');
foreach(['wrong-height','wrong-width'] as $case){copy($root.'/gallery/responsive/'.$case.'.webp',$path);check(!str_contains(attrs(),'srcset='),$case.' rejected');}
copy($root.'/gallery/responsive/valid.webp',$path);check(str_contains(attrs(),' 80w'),'valid smaller derivative retained');
check(str_contains(attrs(),'src="/gallery/original.png"'),'original src preserved');
$valid=file_get_contents($path);file_put_contents($path,$valid.str_repeat('x',filesize($file)));check(!str_contains(attrs(),'srcset='),'larger derivative rejected');
unlink($path);symlink($root.'/gallery/responsive/valid.webp',$path);check(!str_contains(attrs(),'srcset='),'symlink derivative rejected');unlink($path);
$remote='https://i.ebayimg.com/example.jpg?x=1&y=2';
check(ResponsiveImage::attributes($remote,'100vw',0,$root,'123')==='src="https://i.ebayimg.com/example.jpg?x=1&amp;y=2"','remote missing receipt preserves escaped source');
check(hash_file('sha256',$file)==='02703fc57f62c31a99ae89c53143531a7204345ee737641a425b16dd38b779f3','original unchanged');
foreach([20,30,40,60] as $length){file_put_contents($path,substr($valid,0,$length));check(!str_contains(attrs(),'srcset='),'truncated WebP '.$length.' rejected');}
echo "$n assertions passed\n";
} finally {
    foreach(glob($root.'/gallery/responsive/*') as $fixture)unlink($fixture);
    unlink($root.'/gallery/original.png');rmdir($root.'/gallery/responsive');rmdir($root.'/gallery');rmdir($root);
}
