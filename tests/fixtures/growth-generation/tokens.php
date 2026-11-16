<?php
declare(strict_types=1);
require $argv[1].'/src/marketing/Growth.php';
use FAS\Marketing\Growth;
$i=new PDO('sqlite::memory:');$i->exec(file_get_contents($argv[1].'/tests/fixtures/applepay-schema.sql'));
$now=1800000000;$g=new Growth(new PDO('sqlite::memory:'),$i,['url'=>'https://example.invalid'],function()use(&$now){return $now;});
$owner=str_repeat('o',64);$g->saveCart($owner,'old@example.invalid',[['id'=>1,'quantity'=>1]],false);
$old=json_decode($g->run('SELECT links FROM growth_carts')->fetchColumn(),true);
$g->saveCart($owner,'new@example.invalid',[['id'=>2,'quantity'=>1,'price'=>0.01]],false);
$current=json_decode($g->run('SELECT links FROM growth_carts')->fetchColumn(),true);
try{$g->restore($old['restore']);throw new RuntimeException('Old restore accepted');}catch(InvalidArgumentException $e){}
if($g->unsubscribe($old['unsubscribe']))throw new RuntimeException('Old unsubscribe accepted');
$items=$g->restore($current['restore']);
if(count($items)!==1 || $items[0]['id']!==2 || $items[0]['price']!==15.0)throw new RuntimeException('Current restore fixture price mismatch');
if((int)$g->run("SELECT COUNT(*) FROM growth_messages WHERE status='cancelled' AND payload<>'{}'")->fetchColumn())throw new RuntimeException('Old links retained');
if(!$g->unsubscribe($current['unsubscribe']))throw new RuntimeException('Current unsubscribe rejected');
echo "PASS replaced tokens rejected, current tokens accepted, authoritative fixture price, old links cleared\n";
