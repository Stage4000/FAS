<?php
declare(strict_types=1);
require __DIR__.'/../src/utils/ImageOptimizationPlan.php';
require __DIR__.'/../src/utils/ImageOptimizationQueue.php';require_once __DIR__.'/../src/utils/ImageQueuePromotionSchema.php';
use FAS\Utils\ImageOptimizationQueue as Queue;
$n=0;
function check(bool $ok): void { global $n; $n++; if (!$ok) throw new RuntimeException('Assertion '.$n); }
function fails(callable $f): void { try {$f();} catch(Throwable $e){check(true);return;} check(false); }
$file=tempnam(sys_get_temp_dir(),'fas-queue-');
try {
 $a=new PDO('sqlite:'.$file); $a->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $b=new PDO('sqlite:'.$file); $b->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 $a->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,is_active INTEGER,show_on_website INTEGER,image_url TEXT,images TEXT)');
 $a->exec("INSERT INTO products VALUES(1,1,1,'/gallery/a.png','[]')");
 \FAS\Utils\ImageQueueSchema::install($a);\FAS\Utils\ImageQueuePromotionSchema::upgrade($a);
 $qa=new Queue($a); $qb=new Queue($b); $e=str_repeat('a',32);
 fails(fn()=>$qa->enqueueInTransaction(1,$e,100));
 $a->beginTransaction(); check($qa->enqueueInTransaction(1,$e,100)===1); $a->rollBack();
 check((int)$b->query('SELECT count(*) FROM image_optimization_jobs')->fetchColumn()===0);
 $a->beginTransaction(); check($qa->enqueueInTransaction(1,$e,100)===1); check($qa->enqueueInTransaction(1,$e,100)===0); $a->commit();
 $first=$qa->claim(100,10); check($first['attempt']===1); check($qb->claim(100)===null); check($qa->current($first,109));
 check(!$qa->current($first,110)); check(!$qa->finish($first,110,'done'));
 $second=$qb->claim(110,10); check($second['id']===$first['id']); check($second['token']!==$first['token']); check($second['attempt']===2);
 check(!$qa->finish($first,111,'done')); check($qb->finish($second,111,'failed','temporary_io'));
 check($qa->claim(230)===null); $third=$qa->claim(231,10); check($third['attempt']===3);
 $b->exec("UPDATE products SET image_url='/gallery/replacement.png' WHERE id=1"); check(!$qa->current($third,232)); check($qa->finish($third,232,'superseded'));
 // New event identity schedules unchanged URL refresh; retry of same event is idempotent.
 $a->beginTransaction(); check($qa->enqueueInTransaction(1,str_repeat('b',32),240)===1); check($qa->enqueueInTransaction(1,str_repeat('c',32),240)===1); $a->commit();
 $x=$qa->claim(240,1); check($x!==null); check($qa->finish($x,240,'failed','provenance_failed'));
 check($a->query('SELECT state FROM image_optimization_jobs WHERE id='.$x['id'])->fetchColumn()==='quarantined');
 $x=$qa->claim(240,1); for($i=2;$i<=5;$i++){ $x=$qa->claim(239+$i,1);check($x['attempt']===$i); }
 check($qa->claim(245)===null); check($a->query('SELECT state FROM image_optimization_jobs WHERE id='.$x['id'])->fetchColumn()==='quarantined');
 $a->beginTransaction(); fails(fn()=>$qa->enqueueInTransaction(999,str_repeat('d',32),300)); fails(fn()=>$qa->enqueueInTransaction(1,'bad',300)); $a->rollBack();
 $a->exec('UPDATE products SET show_on_website=0 WHERE id=1'); $a->beginTransaction(); check($qa->enqueueInTransaction(1,str_repeat('d',32),300)===0); $a->commit();
 $a->exec('UPDATE products SET show_on_website=1 WHERE id=1'); $a->beginTransaction(); check($qa->enqueueInTransaction(1,str_repeat('e',32),300)===1); $a->commit();
 $x=$qa->claim(300); $b->exec('DELETE FROM products WHERE id=1'); check(!$qa->current($x,301)); check($qa->finish($x,301,'superseded'));
 // Valid JSON with incomplete schema, invalid JSON, and oversized payload quarantine.
 $a->exec("INSERT INTO products VALUES(1,1,1,'/gallery/a.png','[]')");
 foreach (['{}','{',str_repeat('x',16385)] as $i=>$bad) {
  $a->beginTransaction(); check($qa->enqueueInTransaction(1,str_repeat((string)($i+1),32),400)===1); $a->commit();
  $id=(int)$a->lastInsertId(); $q=$a->prepare('UPDATE image_optimization_jobs SET intent_json=? WHERE id=?');$q->execute([$bad,$id]);
  check($qa->claim(400)===null);check($a->query('SELECT failure FROM image_optimization_jobs WHERE id='.$id)->fetchColumn()==='invalid_intent');
 }
 fails(fn()=>$qa->claim(0)); fails(fn()=>$qa->claim(100,301)); fails(fn()=>$qa->finish(['id'=>1,'token'=>'invalid'],300,'done'));
 echo "PASS $n SQLite queue assertions across two independent connections; no activation or production DB.\n";
} finally { $a=null;$b=null;$qa=null;$qb=null;@unlink($file); }
