<?php
declare(strict_types=1);
require $argv[1].'/src/marketing/Growth.php';
use FAS\Marketing\Growth;
$inventory=new PDO('sqlite::memory:');$inventory->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$inventory->exec(file_get_contents($argv[1].'/tests/fixtures/applepay-schema.sql'));
class ProbePDO extends PDO {
 public $barrier=null;
 public function prepare(string $query,array $options=[]):PDOStatement|false {
  if($this->barrier && $query==='SELECT status FROM growth_contacts WHERE email=?'){$f=$this->barrier;$this->barrier=null;$f();}
  return parent::prepare($query,$options);
 }
}
$out=[];
foreach(['replace-email','stop-resave','stop'] as $mode){
 $path=tempnam(sys_get_temp_dir(),'growth-race-');
 try{
 $now=1800000000;$armed=false;$fired=false;$g=null;$other=null;$owner=str_repeat('o',64);
 $clock=function()use(&$now){return $now;};
 $workerDb=new ProbePDO('sqlite:'.$path);
 $g=new Growth($workerDb,$inventory,['url'=>'https://example.invalid'],$clock);
 $other=new Growth(new PDO('sqlite:'.$path),$inventory,['url'=>'https://example.invalid'],function()use(&$now){return $now;});
 $g->saveSettings(['from_email'=>'fixture@example.invalid','reply_to'=>'fixture@example.invalid','postal_address'=>'Synthetic address','mail_enabled'=>'1']);
 $g->saveCart($owner,'old@example.invalid',[['id'=>1,'quantity'=>1]],false);$now+=86401;$armed=true;$sent=[];
 $workerDb->barrier=function()use(&$fired,$other,$owner,$mode){$fired=true;if($mode==='stop')$other->stopCart($owner);elseif($mode==='stop-resave'){$other->stopCart($owner);$other->saveCart($owner,'old@example.invalid',[['id'=>2,'quantity'=>1]],false);}else $other->saveCart($owner,'new@example.invalid',[['id'=>2,'quantity'=>1]],false);};
 $first=$g->sendBatch(function($message)use(&$sent){$sent[]=$message;return true;},1);
 $firstSent=count($sent);
 if($first['cancelled']!==1||$first['deferred']!==0)throw new RuntimeException('Stale claimed generation must cancel not defer');
 if((int)$g->run('SELECT COUNT(*) FROM growth_mail_limits')->fetchColumn()!==0)throw new RuntimeException('Stale generation consumed recipient quota');
 $now+=86401;
 $result=$g->sendBatch(function($message)use(&$sent){$sent[]=$message;return true;},1);
 $cart=$g->run('SELECT email,status FROM growth_carts')->fetch();
 if(!$fired||$firstSent!==0)throw new RuntimeException('Barrier failed');
 if($mode!=='stop'&&(count($sent)!==1||$sent[0]['to']!==($mode==='replace-email'?'new@example.invalid':'old@example.invalid')))throw new RuntimeException('Current generation was not delivered');
 if($mode==='stop'&&count($sent)!==0)throw new RuntimeException('Pre-eligibility stop should suppress');
 $out[]=['scenario'=>$mode,'mutation_completed_before_sender'=>$fired,'cart_after'=>$cart,'sender_calls'=>count($sent),'recipient'=>$sent[0]['to']??null,'first_batch'=>$first,'result'=>$result];
 }finally{unset($g,$other);@unlink($path);}
}
echo json_encode($out,JSON_PRETTY_PRINT);
