<?php
declare(strict_types=1);
require_once __DIR__.'/../src/utils/ImmutableProductUpload.php';
use FAS\Utils\ImmutableProductUpload;
$n=0;function ck(bool $v):void{global $n;$n++;if(!$v)throw new RuntimeException('Failed assertion '.$n);}
function no(callable $f):void{try{$f();}catch(Throwable $e){ck($e instanceof RuntimeException || $e instanceof InvalidArgumentException);return;}throw new RuntimeException('Expected refusal');}
function fixturePng(int $w=2,int $h=2):string{$chunk=static fn($t,$d)=>pack('N',strlen($d)).$t.$d.pack('N',crc32($t.$d));return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$w,$h,8,2,0,0,0)).$chunk('IDAT',gzcompress("\0\xff\0\0\xff\0\0\0\xff\0\0\xff\0\0")).$chunk('IEND','');}
$root=sys_get_temp_dir().'/fas-ingest-'.bin2hex(random_bytes(8));mkdir($root,0700);$method=new ReflectionMethod(ImmutableProductUpload::class,'storeSnapshot');$store=fn($b)=>$method->invoke(null,$b,$root,str_repeat('a',32));$png=fixturePng();
try{
 file_put_contents($root.'/not-upload.png',$png);no(fn()=>ImmutableProductUpload::receive($root.'/not-upload.png',$root));ck(!is_dir($root.'/gallery'));
 $one=$store($png);$path=$root.$one['path'];ck(file_get_contents($path)===$png);ck($one['sha256']===hash('sha256',$png));ck($one['bytes']===strlen($png));ck($one['mime']==='image/png');ck($one['width']===2 && $one['height']===2);ck(str_ends_with($path,'.png'));ck($store($png)===$one);ck(count(glob(dirname($path).'/*'))===1);ck(glob(dirname($path).'/.ingest-*')===[]);
 file_put_contents($path,'tampered');no(fn()=>$store($png));ck(file_get_contents($path)==='tampered');unlink($path);
 if(function_exists('symlink')){symlink($root.'/not-upload.png',$path);no(fn()=>$store($png));ck(is_link($path));unlink($path);}
 mkdir($path);no(fn()=>$store($png));ck(is_dir($path));rmdir($path);
 foreach(['','not an image',str_repeat('x',20971521),fixturePng(20000001,1)] as $bad)no(fn()=>$store($bad));
 $one=$store($png);$fresh=$method->invoke(null,$png,$root);$second=$method->invoke(null,$png,$root);ck($fresh['path']!==$second['path']);ck($fresh['sha256']===$second['sha256']);unlink($root.$fresh['path']);ck(file_get_contents($root.$second['path'])===$png);ck(file_get_contents($root.'/not-upload.png')===$png);ck(file_get_contents($root.$one['path'])===$png);
 $admin=file_get_contents(__DIR__.'/../admin/products.php');ck(substr_count($admin,'ImmutableProductUpload::receive(')===2);ck(!str_contains($admin,'move_uploaded_file('));ck(strpos($admin,'CSRF::validateToken')<strpos($admin,'ImmutableProductUpload::receive('));ck(!str_contains(file_get_contents(__DIR__.'/../src/utils/ImmutableProductUpload.php'),'imagewebp('));
 define('MAX_ADDITIONAL_IMAGES',10);
 $start=strpos($admin,'                // Handle image upload');
 // Locate the real block boundary independent of newline escaping.
 $end=strpos($admin,'$preservedFields = ProductEditor::fields', $start);$end=strrpos(substr($admin,0,$end),'                try {');
 $block=substr($admin,$start,$end-$start);
 $run=static function(array $files)use($block):string{$_FILES=$files;$_POST=[];$error='';eval($block);return $error;};
 foreach([UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE,UPLOAD_ERR_PARTIAL,UPLOAD_ERR_NO_TMP_DIR,UPLOAD_ERR_CANT_WRITE,UPLOAD_ERR_EXTENSION] as $code){
  ck($run(['image_file'=>['error'=>$code]])!=='');
  ck($run(['additional_images'=>['name'=>['fixture.png'],'error'=>[$code]]])!=='');
 }
 ck($run(['image_file'=>['error'=>UPLOAD_ERR_NO_FILE]])==='');
 ck($run(['additional_images'=>['name'=>['fixture.png'],'error'=>[UPLOAD_ERR_NO_FILE]]])==='');
 ck($run(['image_file'=>[]])!=='');
 echo "PASS $n immutable ingest assertions (private snapshot fixtures; HTTP upload acceptance separate).\n";
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);}
