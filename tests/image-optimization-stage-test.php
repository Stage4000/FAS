<?php
declare(strict_types=1);
namespace FAS\Utils {
// Contract double only: no claim that the actual PHP GD encoder ran.
final class ResponsiveImage { public const WIDTHS=[160,320,640]; }
final class ImportedImage {
 public static string $mode='valid'; public static ?\PDO $db=null;
 public static function import(array $r,string $input,string $root): array {
  if(self::$mode==='skipped')return ['status'=>'skipped'];
  mkdir($root.'/gallery');mkdir($root.'/gallery/responsive');
  $bytes=base64_decode('UklGRlYAAABXRUJQVlA4IEoAAACwBQCdASqgAFAAPm02mUmkIyKhIKgAgA2JaW7hc+AAY2upvcReWAa6m9xF5YBrqb3EXlgGuAAA/v3tu/52rY+z7/ScQUdAAAAAAA==');
  $path=$root.'/gallery/responsive/remote-'.$r['source_sha256'].'-160.webp';file_put_contents($path,$bytes);
  $receipt=['version'=>1,'product_id'=>$r['product_id'],'source_url'=>$r['source_url'],'source_sha256'=>$r['source_sha256'],'verified_at'=>strtotime($r['verified_at']),'valid_until'=>strtotime($r['valid_until']),'optimization_authorized'=>true,'provenance_basis'=>'existing_catalog','original_bytes'=>1000,'width'=>320,'height'=>160,'variants'=>[160=>['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)]]];
  if(self::$mode==='tamper')file_put_contents($path,'broken');
  if(self::$mode==='variant_link'){unlink($path);symlink($input.'/missing.webp',$path);}
  if(self::$mode==='lease')self::$db->exec('UPDATE image_optimization_jobs SET lease_until='.(time()-1));
  if(self::$mode==='renew')$receipt['valid_until']++;
  if(self::$mode==='changed')self::$db->exec("UPDATE products SET image_url='https://i.ebayimg.com/new.png'");
  file_put_contents($root.'/gallery/responsive/remote-'.hash('sha256',$r['product_id']."\n".$r['source_url']).'.json',json_encode($receipt));
  if(self::$mode==='receipt_link'){ $receiptPath=$root.'/gallery/responsive/remote-'.hash('sha256',$r['product_id']."\n".$r['source_url']).'.json';rename($receiptPath,$root.'/receipt-copy.json');symlink($root.'/receipt-copy.json',$receiptPath); }
  return ['status'=>'ready'];
 }
}
}
namespace {
require __DIR__.'/../src/utils/ImageOptimizationPlan.php';require __DIR__.'/../src/utils/ImageOptimizationQueue.php';require_once __DIR__.'/../src/utils/ImageQueuePromotionSchema.php';require __DIR__.'/../src/utils/ImageOptimizationStage.php';
use FAS\Utils\ImageOptimizationQueue as Queue;use FAS\Utils\ImageOptimizationStage as Stage;use FAS\Utils\ImportedImage;
$n=0;function check(bool $x):void{global $n;$n++;if(!$x)throw new \RuntimeException('Assertion '.$n);}function fails(callable $f):void{try{$f();}catch(\Throwable $e){check(true);return;}check(false);}
$root=sys_get_temp_dir().'/fas-stage-test-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/app',0700);mkdir($root.'/private',0700);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,is_active INTEGER,show_on_website INTEGER,image_url TEXT,images TEXT)');$db->exec("INSERT INTO products VALUES(1,1,1,'https://i.ebayimg.com/a.png','[]')");\FAS\Utils\ImageQueueSchema::install($db);\FAS\Utils\ImageQueuePromotionSchema::upgrade($db);$q=new Queue($db);$now=time();$db->beginTransaction();$q->enqueueInTransaction(1,str_repeat('a',32),$now);$db->commit();$claim=$q->claim($now,300);ImportedImage::$db=$db;
$r=['product_id'=>'1','source_url'=>'https://i.ebayimg.com/a.png','source_sha256'=>str_repeat('a',64),'verified_at'=>gmdate('c',$now-10),'valid_until'=>gmdate('c',$now+3600),'provenance'=>['excluded'=>false,'basis'=>'existing_catalog','optimization_authorized'=>true,'reference'=>'synthetic contract fixture only']];
try {
 fails(fn()=>new Stage($q,$root.'/app',$root.'/app'));mkdir($root.'/app/private',0700);fails(fn()=>new Stage($q,$root.'/app',$root.'/app/private'));
 chmod($root.'/private',0755);fails(fn()=>new Stage($q,$root.'/app',$root.'/private'));chmod($root.'/private',0700);
 $stage=new Stage($q,$root.'/app',$root.'/private');$out=$stage->prepare($claim,$r,$root);check($out['state']==='prepared_private');check(count($out['files'])===2);check(str_starts_with($out['workspace'],$root.'/private/'));check(!file_exists($root.'/app/gallery'));
 $forged=$claim;$forged['intent']['source']='https://i.ebayimg.com/forged.png';check($q->currentIntent($forged,time())['source']===$r['source_url']);
 $wrong=$r;$wrong['source_url']='https://i.ebayimg.com/forged.png';fails(fn()=>$stage->prepare($forged,$wrong,$root));
 foreach(['skipped','tamper','renew','variant_link','receipt_link','lease','changed']as$mode){
  ImportedImage::$mode=$mode;fails(fn()=>$stage->prepare($claim,$r,$root));
  check(glob($root.'/private/image-stage-*')===[$out['workspace']]);
  foreach($out['files'] as $relative=>$meta)check(hash_file('sha256',$out['workspace'].'/'.$relative)===$meta['sha256']);
  if($mode==='lease')$db->exec('UPDATE image_optimization_jobs SET lease_until='.(time()+300));
 }
 check(!$q->current($claim,time()));check($db->query('SELECT state FROM image_optimization_jobs')->fetchColumn()==='leased');
 echo "PASS $n private stage contract assertions (importer double; no GD execution or public activation).\n";
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($root);
}
}
