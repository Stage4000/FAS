<?php
declare(strict_types=1);
require __DIR__.'/../src/showcase/ShowcaseSessionForms.php';
use FAS\Showcase\ShowcaseSessionForms;
$n=0;
function ok($v) { global $n; if (!$v) throw new RuntimeException('check failed'); ++$n; }
function rejects($f,$type) { try {$f();} catch(Throwable $e) {ok($e instanceof $type); return;} throw new RuntimeException('expected rejection'); }
$state=[];$now=1000;$clock=static function() use (&$now) {return $now;};$pool=new ShowcaseSessionForms($state,10,2,$clock);
$a=$pool->issue();$b=$pool->issue();ok($a['form_id']!==$b['form_id']);ok($a['csrf_token']!==$b['csrf_token']);
$before=$state;rejects(fn()=>$pool->issue(),OverflowException::class);ok($state===$before);
foreach ([null,[],1,'',str_repeat('a',64)] as $bad) {rejects(fn()=>$pool->resolve($bad),InvalidArgumentException::class);ok($state===$before);}
ok($pool->resolve($a['form_id'])===$a);ok($pool->resolve($a['form_id'])===$a);ok($state===$before);
$other=[];$otherPool=new ShowcaseSessionForms($other,10,2,$clock);rejects(fn()=>$otherPool->resolve($a['form_id']),InvalidArgumentException::class);ok($other===[]);
$now=999;rejects(fn()=>$pool->resolve($a['form_id']),InvalidArgumentException::class);
$now=1009;ok($pool->resolve($a['form_id'])===$a);$now=1010;rejects(fn()=>$pool->resolve($a['form_id']),InvalidArgumentException::class);ok($state===$before);
$c=$pool->issue();ok(count($state)===1);ok($c['expires_at']===1020);ok(array_keys($state[$c['form_id']])===['csrf_token','issued_at','expires_at']);
$state[$c['form_id']]['email']='private@example.invalid';rejects(fn()=>$pool->resolve($c['form_id']),LogicException::class);
foreach ([[0,2],[86401,2],[10,0],[10,21]] as [$ttl,$cap]) { rejects(function() use ($ttl,$cap,$clock) {$s=[];new ShowcaseSessionForms($s,$ttl,$cap,$clock);},InvalidArgumentException::class); }
foreach ([null,'1000',-1,PHP_INT_MAX] as $badNow) { $s=[];$p=new ShowcaseSessionForms($s,10,2,fn()=>$badNow);rejects(fn()=>$p->issue(),LogicException::class);ok($s===[]); }
foreach (['csrf','expiry','lifetime','overflow'] as $kind) {
 $s=[];$p=new ShowcaseSessionForms($s,10,2,$clock);$t=$p->issue();
 if ($kind==='csrf') $s[$t['form_id']]['csrf_token']='bad';
 if ($kind==='expiry') $s[$t['form_id']]['expires_at']=$s[$t['form_id']]['issued_at'];
 if ($kind==='lifetime') $s[$t['form_id']]['expires_at']=$s[$t['form_id']]['issued_at']+86401;
 if ($kind==='overflow') { for($i=0;$i<21;$i++) $s[hash('sha256',(string)$i)]=$s[$t['form_id']]; }
 rejects(fn()=>$p->resolve($t['form_id']),LogicException::class);
}
echo "Session lifecycle: $n assertions passed.\n";
