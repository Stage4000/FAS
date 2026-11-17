<?php
declare(strict_types=1);
foreach (['Photo','Submission','Repository','Request','Views','AdminController'] as $name) require __DIR__.'/../src/showcase/Showcase'.$name.'.php';
use FAS\Showcase\ShowcaseRepository;
use FAS\Showcase\ShowcaseRequest;
use FAS\Showcase\ShowcaseAdminController;
$checks=0;
function check($test) { global $checks; if (!$test) throw new RuntimeException('Failed check '.($checks+1)); ++$checks; }
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');ShowcaseRepository::initializeSchema($db);
$active=true;$enabled=true;$actorCalls=0;$repoCalls=0;
$repo=new ShowcaseRepository($db,function($actor,$action)use(&$active,&$repoCalls){++$repoCalls;return $active && $actor===7;},50,10000000);
$req=new ShowcaseRequest($repo,'test-v1','Synthetic consent.',fn()=>true);
$paths=['queue'=>'/admin/showcase.php','detail'=>'/admin/showcase-review.php','moderate'=>'/admin/showcase-moderate.php','photo'=>'/admin/showcase-photo.php'];
$actor=function()use(&$active,&$actorCalls){++$actorCalls;if(!$active)throw new RuntimeException('private account error');return 7;};
$controller=new ShowcaseAdminController($repo,$req,$actor,fn()=>true,$paths);
$csrf=str_repeat('c',64);$get=['REQUEST_METHOD'=>'GET'];
$disabled=new ShowcaseAdminController($repo,$req,$actor,fn()=>false,$paths);
check($disabled->handle('queue',$get,[],[],$csrf)['status']===404);check($actorCalls===0 && $repoCalls===0);
$active=false;$result=$controller->handle('queue',$get,[],[],$csrf);check($result['status']===403);check($repoCalls===0);check(strpos($result['body'],'private account')===false);$active=true;
foreach([null,'7',0,-1,true] as $bad){$c=new ShowcaseAdminController($repo,$req,fn()=>$bad,fn()=>true,$paths);check($c->handle('queue',$get,[],[],$csrf)['status']===403);}
check($controller->handle('unknown',$get,[],[],$csrf)['status']===404);
check($controller->handle('queue',['REQUEST_METHOD'=>'POST'],[],[],$csrf)['status']===405);
check($controller->handle('moderate',$get,[],[],$csrf)['headers']['Allow']==='POST');
foreach([['status'=>[]],['before_time'=>'2026-01-01T00:00:00Z'],['limit'=>'999'],['status'=>'invalid']] as $query)check($controller->handle('queue',$get,$query,[],$csrf)['status']===400);
check($controller->handle('queue',$get,[],['unexpected'=>'body'],$csrf)['status']===400);
$im=imagecreatetruecolor(5,3);ob_start();imagepng($im);$photo=FAS\Showcase\ShowcasePhoto::fromBytes(ob_get_clean());
$submission=FAS\Showcase\ShowcaseSubmission::prepare(['display_name'=>'<script>Test</script>','email'=>'private@example.invalid','caption'=>'<img onerror=alert(1)>','consent'=>true],$photo,'test-v1','Synthetic consent.');
$receipt=$repo->accept($submission,'Synthetic consent.',str_repeat('r',64));$id=$receipt['id'];
$queue=$controller->handle('queue',$get,[],[],$csrf);check($queue['status']===200);check(strpos($queue['body'],'&lt;script&gt;')!==false);check(strpos($queue['body'],'private@example.invalid')===false);
$detail=$controller->handle('detail',$get,['id'=>$id],[],$csrf);check($detail['status']===200);check(strpos($detail['body'],'private@example.invalid')!==false);check(strpos($detail['body'],'<script>')===false);check(substr_count($detail['body'],'<script data-showcase-history-guard>')===1);check(strpos($detail['body'],'name="csrf_token"')!==false);
$image=$controller->handle('photo',$get,['id'=>$id],[],$csrf);check($image['status']===200);check($image['body']===$photo->bytes());check($image['headers']['Content-Type']==='image/jpeg');check($image['headers']['Content-Length']===(string)strlen($photo->bytes()));
foreach([$queue,$detail,$image,$result] as $response){check($response['headers']['Cache-Control']==='private, no-store');check($response['headers']['X-Content-Type-Options']==='nosniff');}
foreach([[],['id'=>[]],['id'=>$id,'actor'=>'8']] as $query)check($controller->handle('detail',$get,$query,[],$csrf)['status']===400);
check($controller->handle('photo',$get,['id'=>str_repeat('0',32)],[],$csrf)['status']===404);
$row=$repo->readForModeration($id,7);$post=['csrf_token'=>$csrf,'id'=>$id,'version'=>'1','photo_sha256'=>$row['photo_sha256'],'consent_text_sha256'=>$row['consent_text_sha256'],'decision'=>'approved','reason'=>'Synthetic approval'];
$server=['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/x-www-form-urlencoded','CONTENT_LENGTH'=>(string)strlen(http_build_query($post))];
$bad=$post;$bad['csrf_token']=str_repeat('x',64);check($controller->handle('moderate',$server,[],$bad,$csrf)['status']===400);
check($controller->handle('moderate',$server,['id'=>$id],$post,$csrf)['status']===400);check($repo->readForModeration($id,7)['status']==='pending_review');
$saved=$controller->handle('moderate',$server,[],$post,$csrf);check($saved['status']===303);check($saved['headers']['Location']===$paths['detail'].'?id='.$id);check($saved['body']==='');check($repo->readForModeration($id,7)['status']==='approved');
check($controller->handle('moderate',$server,[],$post,$csrf)['status']===409);check(count($repo->moderationAudit($id,7))===1);
$active=false;check($controller->handle('photo',$get,['id'=>$id],[],$csrf)['status']===403);check($controller->handle('moderate',$server,[],$post,$csrf)['status']===403);$active=true;
$post['version']='2';$post['decision']='withdrawn';check($controller->handle('moderate',$server,[],$post,$csrf)['status']===303);check($repo->readForModeration($id,7)['status']==='withdrawn');
$closed=$controller->handle('detail',$get,['id'=>$id],[],$csrf);check(strpos($closed['body'],'<form')===false);
$fail=new ShowcaseAdminController($repo,$req,$actor,function(){throw new RuntimeException('secret');},$paths);$error=$fail->handle('queue',$get,[],[],$csrf);check($error['status']===503);check(strpos($error['body'],'secret')===false);
// Authorization may change after initial actor resolution: repository must still deny.
$late=new ShowcaseAdminController($repo,$req,fn()=>7,fn()=>true,$paths);$active=false;
foreach(['photo','detail'] as $endpoint){$r=$late->handle($endpoint,$get,['id'=>$id],[],$csrf);check($r['status']===503);check(strpos($r['body'],'private@example.invalid')===false);}
check($late->handle('moderate',$server,[],$post,$csrf)['status']===503);$active=true;
foreach([1,'true',null] as $enabledValue){$c=new ShowcaseAdminController($repo,$req,$actor,fn()=>$enabledValue,$paths);check($c->handle('queue',$get,[],[],$csrf)['status']===404);}
foreach(['/public.php','/admin/../showcase.php','//evil.invalid/x','/admin/x?y=1'] as $path){$p=$paths;$p['queue']=$path;try{new ShowcaseAdminController($repo,$req,$actor,fn()=>true,$p);throw new Exception('accepted unsafe path');}catch(InvalidArgumentException $e){check(true);}}
$older=$controller->handle('queue',$get,['status'=>'withdrawn','before_time'=>$receipt['accepted_at'],'before_id'=>$id],[],$csrf);check($older['status']===200);check(strpos($older['body'],'No photos match')!==false);
echo "Admin controller: $checks assertions passed.\n";
