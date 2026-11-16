<?php
declare(strict_types=1);
require __DIR__.'/../src/showcase/ShowcaseRepository.php';
require __DIR__.'/../src/showcase/ShowcasePrivateStorage.php';
use FAS\Showcase\ShowcaseRepository;
use FAS\Showcase\ShowcasePrivateStorage;
$n=0;function ok($v){global $n;if(!$v)throw new RuntimeException('check failed');++$n;}
function rejects($f){try{$f();}catch(Throwable $e){ok(true);return;}throw new RuntimeException('expected rejection');}
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'showcase-private-'.bin2hex(random_bytes(10));mkdir($root);mkdir($root.'/public');mkdir($root.'/public-other');
$path=$root.'/private.sqlite';$db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');ShowcaseRepository::initializeSchema($db);$db=null;
$path=realpath($path);$public=realpath($root.'/public');$auth=fn()=>false;
$open=fn($p,$roots)=>ShowcasePrivateStorage::open($p,$roots,$auth,20,1000000);
try {
 if (@symlink($path,$root.'/linked.sqlite')) {rejects(fn()=>$open($root.'/linked.sqlite',[$public]));unlink($root.'/linked.sqlite');} else {echo "SKIP: OS symlink creation unavailable.\n";}
 $hash=hash_file('sha256',$path);$repo=$open($path,[$public]);ok($repo instanceof ShowcaseRepository);$repo=null;ok(hash_file('sha256',$path)===$hash);
 foreach (['relative.sqlite','file:'.$path,':memory:',$root.'/missing.sqlite',$root, $root.'/public/../private.sqlite',$path.'?mode=rw',$path.chr(0)] as $bad) rejects(fn()=>$open($bad,[$public]));
 ok(!file_exists($root.'/missing.sqlite'));
 if (DIRECTORY_SEPARATOR==='\\') foreach (['C:relative.sqlite','/root-relative.sqlite','\\root-relative.sqlite','\\\\server\\share\\db.sqlite'] as $bad) rejects(fn()=>$open($bad,[$public]));
 foreach ([[],[$root.'/missing'],[42],[$root]] as $roots) rejects(fn()=>$open($path,$roots));
 rejects(fn()=>$open($path,[$root.'/public/..']));
 copy($path,$public.'/inside.sqlite');rejects(fn()=>$open(realpath($public.'/inside.sqlite'),[$public]));
 copy($path,$root.'/public-other/allowed.sqlite');ok($open(realpath($root.'/public-other/allowed.sqlite'),[$public]) instanceof ShowcaseRepository);
 touch($root.'/empty.sqlite');rejects(fn()=>$open(realpath($root.'/empty.sqlite'),[$public]));ok(filesize($root.'/empty.sqlite')===0);
 file_put_contents($root.'/bad.sqlite','not sqlite');rejects(fn()=>$open(realpath($root.'/bad.sqlite'),[$public]));
 $db=new PDO('sqlite:'.$path);$db->exec('DROP TABLE showcase_moderation_audit');$db=null;rejects(fn()=>$open($path,[$public]));
 echo "Private storage: $n assertions passed.\n";
} finally {
 // Only this newly created synthetic directory, no caller paths.
 foreach (['public/inside.sqlite','public-other/allowed.sqlite','empty.sqlite','bad.sqlite','private.sqlite'] as $f) if(file_exists($root.'/'.$f))unlink($root.'/'.$f);
 rmdir($root.'/public');rmdir($root.'/public-other');rmdir($root);
}
