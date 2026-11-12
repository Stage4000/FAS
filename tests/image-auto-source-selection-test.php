<?php
declare(strict_types=1);
require __DIR__.'/fixtures/image-auto-pilot/ImageSourceSelection.php';
use FAS\Utils\ImageSourceSelection as S;
$n=0;function ok(bool $v):void{global$n;if(!$v)throw new RuntimeException('Check failed');$n++;}function rejects(callable$f):void{try{$f();}catch(RuntimeException$e){ok(true);return;}throw new RuntimeException('Expected refusal');}
$p=['image_url'=>'https://i.ebayimg.com/primary','images'=>'["https://i.ebayimg.com/a","https://i.ebayimg.com/a","https://i.ebayimg.com/b"]'];
ok(S::selector([])===['kind'=>'primary']);ok(S::selected($p,[])===$p['image_url']);ok(S::selected($p,['source_selector'=>['kind'=>'primary']])===$p['image_url']);
foreach([0,1,2]as$i){$e=['source_selector'=>['kind'=>'gallery','index'=>$i]];ok(S::selected($p,$e)===json_decode($p['images'],true)[$i]);ok(S::selector($e)===$e['source_selector']);}
foreach([null,[],['kind'=>'other'],['kind'=>'primary','index'=>0],['kind'=>'gallery','index'=>'0'],['kind'=>'gallery','index'=>-1],['kind'=>'gallery','index'=>128],['index'=>0,'kind'=>'gallery']]as$s)rejects(fn()=>S::selector(['source_selector'=>$s]));
$e=['source_selector'=>['kind'=>'gallery','index'=>0]];
foreach(['[]','{}','{"0":"a"}','[null]','[1]','[[]]','[""]','[',str_repeat(' ',131073),json_encode(array_fill(0,129,'a'))]as$raw){$q=$p;$q['images']=$raw;rejects(fn()=>S::selected($q,$e));}
rejects(fn()=>S::selected($p,['source_selector'=>['kind'=>'gallery','index'=>3]]));
// Selection does not authorize a URL/host: intake's unchanged source gate remains mandatory.
$q=$p;$q['images']='["https://unsupported.example/a"]';ok(S::selected($q,$e)==='https://unsupported.example/a');
echo "PASS $n exact source-selection checks; pure only\n";
