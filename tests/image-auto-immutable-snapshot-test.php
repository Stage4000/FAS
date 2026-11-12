<?php
declare(strict_types=1);
require __DIR__.'/fixtures/image-auto-local/ImmutableImageSnapshot.php';
use FAS\Utils\ImmutableImageSnapshot;
if(!function_exists('posix_geteuid')){fwrite(STDERR,"UNACCEPTED: POSIX required\n");exit(2);}
$root=getcwd().'/immutable-snapshot-synthetic-'.bin2hex(random_bytes(8));
foreach([$root,$root.'/site',$root.'/site/gallery',$root.'/site/gallery/uploads',$root.'/site/gallery/uploads/immutable']as$p)mkdir($p,0700);
$site=$root.'/site';$bytes="synthetic-original\0bytes";$source='/gallery/uploads/immutable/sha256-'.hash('sha256',$bytes).'-'.str_repeat('a',32).'.png';$path=$site.$source;file_put_contents($path,$bytes);chmod($path,0644);
$n=0;function check(bool$v):void{global$n;if(!$v)throw new RuntimeException('Assertion '.($n+1));$n++;}
function deny(callable$f):void{try{$f();}catch(Throwable$e){check(true);return;}throw new RuntimeException('Expected refusal');}
deny(fn()=>new ImmutableImageSnapshot($site));$r=new ImmutableImageSnapshot($site,true);$s=$r->read($source);check($s['bytes']===$bytes);check($s['publication_authorized']===false);check($s['image_validation_required']===true);$r->assertCurrent($s);check(file_get_contents($path)===$bytes);
$altered=$s;$altered['bytes']=str_repeat('x',strlen($bytes));deny(fn()=>$r->assertCurrent($altered));
$missing=$s;unset($missing['bytes']);deny(fn()=>$r->assertCurrent($missing));
$short=$s;$short['bytes']='x';deny(fn()=>$r->assertCurrent($short));
$proof=$s;$proof['source_sha256']=hash('sha256',str_repeat('x',strlen($bytes)));deny(fn()=>$r->assertCurrent($proof));
$metadata=$s;unset($metadata['identity']['ctime']);deny(fn()=>$r->assertCurrent($metadata));
$r->assertCurrent($s);check(file_get_contents($path)===$bytes);
foreach(['../x',$source.'?x=1',str_replace('.png','.gif',$source),str_replace('/gallery/','/gallery/../gallery/',$source),str_replace('sha256-','sha256-%61',$source),'https://example.com'.$source]as$bad)deny(fn()=>$r->read($bad));
file_put_contents($path,'same length altered!!!');deny(fn()=>$r->read($source));file_put_contents($path,$bytes);
chmod($path,0666);deny(fn()=>$r->read($source));chmod($path,0644);
link($path,$root.'/alias');deny(fn()=>$r->read($source));unlink($root.'/alias');
rename($path,$root.'/saved');symlink($root.'/saved',$path);deny(fn()=>$r->read($source));unlink($path);rename($root.'/saved',$path);
$s=$r->read($source);rename($path,$root.'/old');file_put_contents($path,$bytes);chmod($path,0644);deny(fn()=>$r->assertCurrent($s));check(file_get_contents($root.'/old')===$bytes);
file_put_contents($path,str_repeat('x',2097153));deny(fn()=>$r->read($source));file_put_contents($path,'');deny(fn()=>$r->read($source));
chmod($site.'/gallery/uploads',0777);deny(fn()=>new ImmutableImageSnapshot($site,true));chmod($site.'/gallery/uploads',0700);
echo json_encode(['status'=>'PASS','checks'=>$n,'original_snapshot_only'=>true,'image_decode'=>false,'production_data'=>false,'fixture_root'=>$root])."\n";
