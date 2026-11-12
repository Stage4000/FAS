<?php
declare(strict_types=1);
require_once __DIR__.'/ImageQuotaAdmission.php';
use FAS\Utils\ImageQuotaAdmission as Q;
$n=0;function ok(bool$x):void{global$n;if(!$x)throw new RuntimeException('Quota assertion '.($n+1));$n++;}function no(callable$f):void{try{$f();}catch(Throwable){ok(true);return;}ok(false);}
$job=str_repeat('a',32);$other=str_repeat('b',32);$v=['observed_at'=>1000,'quota_limit'=>5368709120,'quota_used'=>1073741824,'private_free'=>2147483648,'public_free'=>2147483648,'tmp_free'=>2147483648];
$r=Q::plan($v,1000,[],$job);ok($r['state']==='reservation_planned'&&!$r['dispatch_authorized']);ok($r['reserve_bytes']===67108864);
foreach(['observed_at','quota_limit','quota_used','private_free','public_free','tmp_free']as$k){$x=$v;$x[$k]=true;no(fn()=>Q::plan($x,1000,[],$job));}
foreach([699,1001]as$t){$x=$v;$x['observed_at']=$t;ok(Q::plan($x,1000,[],$job)['state']==='pause');}
$x=$v;$x['quota_used']=$x['quota_limit']-134217727;ok(Q::plan($x,1000,[],$job)['reason']==='subscription_headroom');$x['quota_used']--;ok(Q::plan($x,1000,[],$job)['state']==='reservation_planned');
foreach(['private_free','public_free','tmp_free']as$k){$x=$v;$x[$k]=134217727;ok(Q::plan($x,1000,[],$job)['reason']==='filesystem_headroom');}
$rows=[['job'=>$job,'bytes'=>67108864,'state'=>'unknown']];ok(Q::plan($v,1000,$rows,$job)['state']==='reconcile');ok(Q::plan($v,1000,$rows,$other)['state']==='pause');no(fn()=>Q::plan($v,1000,[$rows[0],$rows[0]],$other));$rows[0]['bytes']=1;no(fn()=>Q::plan($v,1000,$rows,$other));
$x=$v;$x['quota_limit']=0;no(fn()=>Q::plan($x,1000,[],$job));$x=$v;$x['quota_used']=$x['quota_limit']+1;no(fn()=>Q::plan($x,1000,[],$job));echo "PASS pure PHP quota policy: $n checks; no reservation/dispatch\n";
