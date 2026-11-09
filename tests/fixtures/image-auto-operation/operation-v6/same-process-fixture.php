<?php
declare(strict_types=1);
require_once __DIR__.'/../candidate/src/ImageEffectObservation.php';
use FAS\Utils\ImageEffectObservation;
if(!function_exists('posix_geteuid')||!class_exists('finfo')){fwrite(STDERR,"UNACCEPTED: POSIX/fileinfo required\n");exit(2);}
$root=getcwd().'/effect-observation-synthetic-'.bin2hex(random_bytes(8));$state=$root.'/private';$site=$root.'/site';
foreach([$root,$state,$state.'/inputs',$site,$site.'/gallery',$site.'/gallery/responsive']as$p)mkdir($p,0700);
function enc(array$v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
function canonical($v):string{if(is_array($v)){if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$x)if(is_array($x))$v[$k]=json_decode(canonical($x),true,16,JSON_THROW_ON_ERROR);}return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS);}
function save(string$p,string$b,int$m=0600):void{file_put_contents($p,$b);chmod($p,$m);}
$source=base64_decode(file_get_contents(__DIR__.'/../tests/effect-fixtures/source.png.base64'),true);$webp=base64_decode(file_get_contents(__DIR__.'/../tests/effect-fixtures/variant.webp.base64'),true);$sha=hash('sha256',$source);$id=str_repeat('a',32);$url='https://i.ebayimg.com/synthetic-effect.png';$now=time();$selector=['kind'=>'gallery','index'=>0];
$product=['id'=>'7','is_active'=>1,'show_on_website'=>1,'image_url'=>'https://i.ebayimg.com/other.png','images'=>enc([$url])];
$image=['bytes'=>strlen($source),'sha256'=>$sha,'mime'=>'image/png','width'=>160,'height'=>80,'orientation'=>1,'extension'=>'png'];
$record=['source_selector'=>$selector,'product_id'=>'7','source_url'=>$url,'local_file'=>'source-'.$id.'.png','source_sha256'=>$sha,'verified_at'=>gmdate('c',$now),'valid_until'=>gmdate('c',$now+86400),'provenance'=>['basis'=>'existing_catalog','reference'=>'Synthetic observer only','optimization_authorized'=>true,'excluded'=>false], 'intake'=>['version'=>1,'job'=>$id,'catalog_snapshot_sha256'=>hash('sha256',enc($product)),'receipt_preimage_sha256'=>null,'intent'=>'initial','image'=>$image]];
$events=[['state'=>'receiving','job'=>$id,'context'=>['catalog_snapshot'=>$product,'receipt_preimage_sha256'=>null,'source_url'=>$url,'intent'=>'initial','source_selector'=>$selector],'retry_authorized'=>false],['state'=>'staged','job'=>$id,'utc'=>gmdate('c',$now),'evidence'=>['image'=>$image,'local_file'=>$record['local_file']],'retry_authorized'=>false],['state'=>'ready','job'=>$id,'utc'=>gmdate('c',$now),'evidence'=>['record_sha256'=>hash('sha256',enc($record))],'retry_authorized'=>false]];
$journal=implode("\n",array_map('enc',$events))."\n";$manifest=enc($record)."\n";$intakePins=['journal_sha256'=>hash('sha256',$journal),'manifest_sha256'=>hash('sha256',$manifest)];
$receipt=['version'=>1,'product_id'=>'7','source_url'=>$url,'source_sha256'=>$sha,'verified_at'=>$now,'valid_until'=>$now+86400,'width'=>160,'height'=>80,'original_bytes'=>strlen($source),'variants'=>[80=>['bytes'=>strlen($webp),'sha256'=>hash('sha256',$webp)]],'optimization_authorized'=>true,'provenance_basis'=>'existing_catalog'];$receiptRaw=enc($receipt)."\n";
$name='remote-'.$sha.'-80.webp';$meta=['bytes'=>strlen($webp),'sha256'=>hash('sha256',$webp),'width'=>80,'height'=>40];
$job=['version'=>1,'id'=>$id,'state'=>'done','record'=>$record,'product'=>$product,'before_receipt'=>null,'queued_at'=>$now,'intake_binding'=>$intakePins,'after_receipt'=>$receiptRaw,'files'=>[$name=>$meta]];
$lifecycle=['version'=>2,'product_id'=>7,'revision'=>3,'deleted'=>false,'image_url'=>$product['image_url'],'images'=>$product['images'],'is_active'=>1,'show_on_website'=>1];
$pins=['attempt_id'=>$id,'product_id'=>7,'revision'=>3,'snapshot_sha256'=>hash('sha256',canonical($lifecycle)),'operation_key'=>str_repeat('b',64),'source_selector'=>$selector,'source_url'=>$url,'receipt_preimage_sha256'=>null];
$pins['operation_key']=hash('sha256',canonical(['version'=>1,'product_id'=>7,'revision'=>3,'snapshot_sha256'=>$pins['snapshot_sha256'],'source_url'=>$url,'source_selector'=>$selector,'intent'=>'initial','receipt_preimage_sha256'=>null]));
$proof=['version'=>1,'attempt_id'=>$id,'operation_key'=>$pins['operation_key'],'snapshot_sha256'=>$pins['snapshot_sha256'],'source_selector'=>$selector,'source_url'=>$url,'source_sha256'=>$sha,'source_bytes'=>strlen($source),'mime'=>'image/png','width'=>160,'height'=>80,'verified_at'=>$now,'valid_until'=>$now+86400,'intake_journal_sha256'=>$intakePins['journal_sha256'],'intake_manifest_sha256'=>$intakePins['manifest_sha256']];$proofRaw=canonical($proof);$proofHash=hash('sha256',$proofRaw);
$jobPath=$state.'/job-'.$id.'.json';$receiptPath=$site.'/gallery/responsive/remote-'.hash('sha256',"7\n".$url).'.json';$variantPath=$site.'/gallery/responsive/'.$name;
foreach([$state.'/.worker.lock',$state.'/.intake.lock',$site.'/gallery/responsive/.remote.lock']as$p)save($p,'');
save($state.'/approved-record.json',$manifest);save($state.'/intake-attempt.jsonl',$journal);save($state.'/inputs/'.$record['local_file'],$source);save($jobPath,enc($job));save($receiptPath,$receiptRaw,0644);save($variantPath,$webp,0644);
