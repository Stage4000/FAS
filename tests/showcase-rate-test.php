<?php
declare(strict_types=1);
require __DIR__.'/../src/showcase/ShowcaseRateAdmission.php';
use FAS\Showcase\ShowcaseRateAdmission as R;
$n=0;function ok($v){global $n;if(!$v)throw new RuntimeException('failed');++$n;}function reject($f){try{$f();}catch(Throwable $e){ok(true);return;}throw new RuntimeException('expected rejection');}
$d=sys_get_temp_dir().'/showcase-rate-'.bin2hex(random_bytes(8));mkdir($d);mkdir($d.'/public');$p=$d.'/quota.sqlite';$key=str_repeat('x',32);$now=100;
$db=new PDO('sqlite:'.$p);$db->exec(R::SCHEMA);$db->exec(R::STATE_SCHEMA);$db->prepare('INSERT INTO showcase_rate_state VALUES(1,?,0,0,0)')->execute([R::binding($key,2,10,2,3)]);
$clock=static function()use(&$now){return $now;};$r=new R($p,[$d.'/public'],$key,2,10,2,3,$clock);
try{
 ok($r->admit('private@example.invalid'));ok($r->admit('private@example.invalid'));reject(fn()=>$r->admit('private@example.invalid'));ok($r->admit('other'));reject(fn()=>$r->admit('new'));
 ok($db->query('SELECT used FROM showcase_rate_state')->fetchColumn()===3);ok($db->query('SELECT COUNT(*) FROM showcase_rate')->fetchColumn()===2);
 $rows=$db->query('SELECT actor FROM showcase_rate')->fetchAll(PDO::FETCH_COLUMN);ok(!in_array('private@example.invalid',$rows,true));ok(strlen($rows[0])===64);
 $now=99;reject(fn()=>$r->admit('other'));$now=110;ok($r->admit('new'));ok($db->query('SELECT COUNT(*) FROM showcase_rate')->fetchColumn()===1);
 reject(fn()=>new R($p,[$d.'/public'],$key,3,10,2,3,$clock));reject(fn()=>new R($p,[$d.'/public'],str_repeat('y',32),2,10,2,3,$clock));
 reject(fn()=>new R($d.'/missing',[$d.'/public'],$key,2,10,2,3,$clock));ok(!file_exists($d.'/missing'));
 reject(fn()=>new R($p,[$d],$key,2,10,2,3,$clock));
 $processes=[];
 $code='require $argv[1];try{$r=new FAS\\Showcase\\ShowcaseRateAdmission($argv[2],[$argv[3]],str_repeat("x",32),2,10,2,3,fn()=>120);$r->admit($argv[4]);echo "yes";}catch(Throwable $e){echo "no";}';
 for($i=0;$i<8;$i++){$proc=proc_open([PHP_BINARY,'-r',$code,__DIR__.'/../src/showcase/ShowcaseRateAdmission.php',$p,$d.'/public','actor'.($i%2)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$processes[]=[$proc,$pipes];}
 $yes=0;foreach($processes as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);ok(proc_close($proc)===0 && $err==='');if($out==='yes')$yes++;else ok($out==='no');}
 ok($yes===3);ok($db->query('SELECT used FROM showcase_rate_state')->fetchColumn()===3);ok((int)$db->query('SELECT MAX(used) FROM showcase_rate')->fetchColumn()<=2);
 $now=120;
 foreach(["UPDATE showcase_rate_state SET used='oops'", "UPDATE showcase_rate_state SET started=121", "UPDATE showcase_rate SET used='oops'", "UPDATE showcase_rate SET actor='ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ' WHERE rowid=(SELECT MIN(rowid) FROM showcase_rate)"] as $sql){
  $backup=$db->query('SELECT * FROM showcase_rate')->fetchAll(PDO::FETCH_ASSOC);$state=$db->query('SELECT * FROM showcase_rate_state')->fetch(PDO::FETCH_ASSOC);$db->exec($sql);reject(fn()=>$r->admit('new'));
  $db->exec('DELETE FROM showcase_rate');foreach($backup as $row)$db->prepare('INSERT INTO showcase_rate VALUES(?,?,?)')->execute(array_values($row));$db->prepare('UPDATE showcase_rate_state SET config=?,clock=?,started=?,used=? WHERE id=1')->execute([$state['config'],$state['clock'],$state['started'],$state['used']]);
 }
 echo "Rate admission: $n assertions passed.\n";
}finally{$r=null;$db=null;unlink($p);rmdir($d.'/public');rmdir($d);}
