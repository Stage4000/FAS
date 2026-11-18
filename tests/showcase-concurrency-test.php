<?php
declare(strict_types=1);
require __DIR__.'/../src/showcase/ShowcaseConcurrency.php';
use FAS\Showcase\ShowcaseConcurrency as C;
$n=0;function ok($v){global $n;if(!$v)throw new RuntimeException('check failed');$n++;}
function reject($f){try{$f();}catch(Throwable $e){ok(true);return;}throw new RuntimeException('expected rejection');}
$d=sys_get_temp_dir().'/showcase-slots-'.bin2hex(random_bytes(8));mkdir($d);mkdir($d.'/public');file_put_contents($d.'/slot',C::MARKER);
$p=$d.'/slot';$roots=[$d.'/public'];$run=fn($paths,$gate)=>new C($paths,$roots,$gate);
try {
 $c=$run([$p],fn()=>true);ok($c->execute(fn()=>42)===42);
 foreach([false,1,'true',null] as $denied){$called=false;reject(fn()=>$run([$p],fn()=>$denied)->execute(function()use(&$called){$called=true;}));ok(!$called);}
 reject(fn()=>$c->execute(fn()=>throw new RuntimeException('synthetic')));ok($c->execute(fn()=>42)===42);
 reject(fn()=>$c->execute(fn()=>$c->execute(fn()=>0)));ok($c->execute(fn()=>42)===42);
 foreach([[$d.'/missing'],[$d],[$p,$p],['relative']] as $bad)reject(fn()=>$run($bad,fn()=>true)->execute(fn()=>1));
 ok(!file_exists($d.'/missing'));
 file_put_contents($d.'/public/slot',C::MARKER);reject(fn()=>$run([$d.'/public/slot'],fn()=>true)->execute(fn()=>1));
 symlink($p,$d.'/link');reject(fn()=>$run([$d.'/link'],fn()=>true)->execute(fn()=>1));unlink($d.'/link');
 link($p,$d.'/hardlink');reject(fn()=>$run([$p,$d.'/hardlink'],fn()=>true)->execute(fn()=>1));unlink($d.'/hardlink');
 file_put_contents($p,'bad');reject(fn()=>$c->execute(fn()=>1));file_put_contents($p,C::MARKER);
 // Independent process holds the slot through a long operation; no timeout expiry.
 $script='$h=fopen($argv[1],"r+b");flock($h,LOCK_EX);echo "held\\n";fflush(STDOUT);fgets(STDIN);flock($h,LOCK_UN);fclose($h);';
 $proc=proc_open([PHP_BINARY,'-r',$script,$p],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 ok(is_resource($proc));ok(trim(fgets($pipes[1]))==='held');
 file_put_contents($d.'/slot2',C::MARKER);$two=$run([$p,$d.'/slot2'],fn()=>true);ok($two->execute(fn()=>42)===42);reject(fn()=>$two->execute(fn()=>$two->execute(fn()=>0)));unlink($d.'/slot2');
 reject(fn()=>$c->execute(fn()=>1));usleep(150000);reject(fn()=>$c->execute(fn()=>1));
 fwrite($pipes[0],"release\n");fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]);ok(proc_close($proc)===0);ok($c->execute(fn()=>42)===42);
 echo "Concurrency: $n assertions passed.\n";
}finally{unlink($p);if(file_exists($d.'/public/slot'))unlink($d.'/public/slot');rmdir($d.'/public');rmdir($d);}
