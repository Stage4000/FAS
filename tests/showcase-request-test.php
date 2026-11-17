<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['Photo','Submission','Repository','Request','Form'] as $name) { require_once __DIR__ . '/../src/showcase/Showcase' . $name . '.php'; }
use FAS\Showcase\ShowcasePhoto;
use FAS\Showcase\ShowcaseSubmission;
use FAS\Showcase\ShowcaseRepository;
use FAS\Showcase\ShowcaseRequest;
use FAS\Showcase\ShowcaseForm;

$checks = 0;
function rqCheck(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException($label); } ++$checks; }
function rqReject(callable $fn, string $type, string $label): void {
    try { $fn(); } catch (Throwable $e) { rqCheck($e instanceof $type, $label . ': ' . get_class($e)); return; }
    throw new RuntimeException('Expected rejection: ' . $label);
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON'); ShowcaseRepository::initializeSchema($db);
$active = true; $calls = [];
$repo = new ShowcaseRepository($db, static function ($actor, $action, $snapshot) use (&$active): bool { return $active && $actor === 7; }, 10, 1000000);
$gate = static function ($action) use (&$calls) { $calls[] = $action; return true; };
$permission = 'Synthetic permission only. Not reviewed for customer use.';
$request = new ShowcaseRequest($repo, 'test-v1', $permission, $gate);
$csrf = str_repeat('c', 64); $form = str_repeat('f', 64);
$server = ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '2000', 'CONTENT_TYPE' => 'multipart/form-data; boundary=fixture'];
$post = ['csrf_token' => $csrf, 'form_id' => $form, 'display_name' => 'Fixture', 'email' => 'person@example.invalid', 'caption' => 'Synthetic', 'consent' => '1'];
$fake = ['photo' => ['error' => UPLOAD_ERR_OK, 'size' => 12, 'tmp_name' => __FILE__]];
foreach (['GET','HEAD','PUT','DELETE','','post',null,[]] as $method) {
    rqReject(static function () use ($request,$server,$post,$fake,$csrf,$form,$method) { $request->submit(array_replace($server,['REQUEST_METHOD'=>$method]),$post,$fake,$csrf,$form); }, InvalidArgumentException::class, 'POST only');
}
foreach (['','0','-1','1.5','1e3',' 2000','02000','99999999',strval(ShowcaseRequest::MAX_BODY_BYTES+1),2000,null,[]] as $length) {
    rqReject(static function () use ($request,$server,$post,$fake,$csrf,$form,$length) { $request->submit(array_replace($server,['CONTENT_LENGTH'=>$length]),$post,$fake,$csrf,$form); }, InvalidArgumentException::class, 'Strict bounded length');
}
foreach (['application/json','text/plain','multipart/form-datax',str_repeat('x',1025),null,[]] as $type) {
    rqReject(static function () use ($request,$server,$post,$fake,$csrf,$form,$type) { $request->submit(array_replace($server,['CONTENT_TYPE'=>$type]),$post,$fake,$csrf,$form); }, InvalidArgumentException::class, 'Correct media type');
}
foreach (['',str_repeat('x',64),null,[]] as $token) {
    rqReject(static function () use ($request,$server,$post,$fake,$csrf,$form,$token) { $request->submit($server,array_replace($post,['csrf_token'=>$token]),$fake,$csrf,$form); }, InvalidArgumentException::class, 'Matching CSRF');
}
rqCheck($calls === [], 'Rejected protocol/CSRF never calls admission gate');
foreach (['','short',str_repeat('x',129),'!'.str_repeat('x',32)] as $bad) {
    rqReject(static function () use ($request,$server,$post,$fake,$bad,$form) { $request->submit($server,$post,$fake,$bad,$form); }, LogicException::class, 'Server token configuration');
}
foreach ([false,0,1,'true',null] as $allow) {
    $denied = new ShowcaseRequest($repo,'test-v1',$permission,static function () use ($allow) { return $allow; });
    rqReject(static function () use ($denied,$server,$post,$fake,$csrf,$form) { $denied->submit($server,$post,$fake,$csrf,$form); }, RuntimeException::class, 'Literal admission approval');
}
$throwing = new ShowcaseRequest($repo,'test-v1',$permission,static function () { throw new RuntimeException('synthetic gate error'); });
rqReject(static function () use ($throwing,$server,$post,$fake,$csrf,$form) { $throwing->submit($server,$post,$fake,$csrf,$form); }, RuntimeException::class, 'Policy failure closed');
foreach ([['form_id'=>'other'],['consent'=>true],['consent'=>'0'],['consent'=>null],['display_name'=>[]],['caption'=>str_repeat('x',8001)],['status'=>'approved'],['actor_id'=>'7'],['consent_text'=>'spoof']] as $change) {
    rqReject(static function () use ($request,$server,$post,$fake,$csrf,$form,$change) { $request->submit($server,array_replace($post,$change),$fake,$csrf,$form); }, InvalidArgumentException::class, 'Strict bounded input fields');
}
foreach ([[],['photo'=>'path'],['photo'=>[]],$fake,['photo'=>$fake['photo'],'other'=>$fake['photo']]] as $files) {
    rqReject(static function () use ($request,$server,$post,$files,$csrf,$form) { $request->submit($server,$post,$files,$csrf,$form); }, InvalidArgumentException::class, 'Native upload provenance required');
}
foreach ([UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE,UPLOAD_ERR_PARTIAL,UPLOAD_ERR_NO_FILE,UPLOAD_ERR_NO_TMP_DIR,UPLOAD_ERR_CANT_WRITE,UPLOAD_ERR_EXTENSION,'0',null] as $error) {
    $files = ['photo'=>array_replace($fake['photo'],['error'=>$error])];
    rqReject(static function () use ($request,$server,$post,$files,$csrf,$form) { $request->submit($server,$post,$files,$csrf,$form); }, InvalidArgumentException::class, 'Upload error rejected');
}
rqCheck((int)$db->query('SELECT COUNT(*) FROM showcase_submissions')->fetchColumn()===0,'No rejected upload inserted');

$im=imagecreatetruecolor(3,2); ob_start(); imagepng($im); $bytes=ob_get_clean();
$photo=ShowcasePhoto::fromBytes($bytes);
$base=['display_name'=>'Test','email'=>'person@example.invalid','caption'=>'test','consent'=>true];
foreach (["Line one\nLine two", "Line one\r\nLine two", "Line one\rLine two"] as $caption) {
    $prepared=ShowcaseSubmission::prepare(array_replace($base,['caption'=>$caption]),$photo,'test-v1',$permission);
    rqCheck($prepared->privateRecord()['caption']==="Line one\nLine two",'Caption line endings normalized');
}
foreach (array_merge(range(0,9),range(11,12),range(14,31),[127]) as $control) {
    rqReject(static function () use ($base,$photo,$permission,$control) { ShowcaseSubmission::prepare(array_replace($base,['caption'=>'a'.chr($control).'b']),$photo,'test-v1',$permission); }, InvalidArgumentException::class,'Other caption controls banned');
}
foreach (['display_name','email'] as $field) {
    foreach (["\n","\r","\r\n"] as $newline) {
        rqReject(static function () use ($base,$photo,$permission,$field,$newline) { ShowcaseSubmission::prepare(array_replace($base,[$field=>'a'.$newline.'b']),$photo,'test-v1',$permission); }, InvalidArgumentException::class,'Names/email remain single line');
    }
}
rqReject(static function () use ($base,$photo,$permission) { ShowcaseSubmission::prepare(array_replace($base,['caption'=>str_repeat("x\n",1000).'x']),$photo,'test-v1',$permission); }, InvalidArgumentException::class,'Newlines count toward caption length');
$sub=ShowcaseSubmission::prepare(['display_name'=>'Test','email'=>'person@example.invalid','caption'=>'test','consent'=>true],$photo,'test-v1',$permission);
$receipt=$repo->accept($sub,$permission,$form);
$row=$repo->readForModeration($receipt['id'],7);
$review=['csrf_token'=>$csrf,'id'=>$row['id'],'version'=>strval($row['version']),'photo_sha256'=>$row['photo_sha256'],'consent_text_sha256'=>$row['consent_text_sha256'],'decision'=>'approved','reason'=>'Reviewed fixture'];
$reviewServer=array_replace($server,['CONTENT_TYPE'=>'application/x-www-form-urlencoded']);
foreach (['0','01','-1','1.0','1e0','9223372036854775808','9223372036854775807','',[]] as $version) {
    rqReject(static function () use ($request,$reviewServer,$review,$csrf,$version) { $request->moderate($reviewServer,array_replace($review,['version'=>$version]),$csrf,7); }, InvalidArgumentException::class, 'Review version strict');
}
foreach ([['csrf_token'=>'bad'],['actor_id'=>'7'],['reason'=>[]],['decision'=>'published'],['photo_sha256'=>'bad']] as $change) {
    rqReject(static function () use ($request,$reviewServer,$review,$csrf,$change) { $request->moderate($reviewServer,array_replace($review,$change),$csrf,7); }, InvalidArgumentException::class, 'Review input guard');
}
rqReject(static function () use ($request,$reviewServer,$review,$csrf) { $request->moderate($reviewServer,$review,$csrf,8); }, RuntimeException::class, 'Actor from trusted session');
$active=false;
rqReject(static function () use ($request,$reviewServer,$review,$csrf) { $request->moderate($reviewServer,$review,$csrf,7); }, RuntimeException::class, 'Revoked actor refused');
$active=true;
$approved=$request->moderate($reviewServer,$review,$csrf,7);
rqCheck($approved['status']==='approved' && $approved['version']===2,'Approved committed through HTTP boundary');
rqReject(static function () use ($request,$reviewServer,$review,$csrf) { $request->moderate($reviewServer,$review,$csrf,7); }, DomainException::class, 'Repeated stale moderation refused');
$withdraw=array_replace($review,['version'=>'2','decision'=>'withdrawn','reason'=>'Withdrawal fixture']);
rqCheck($request->moderate($reviewServer,$withdraw,$csrf,7)['status']==='withdrawn','Withdrawal through boundary');
rqCheck((int)$db->query('SELECT COUNT(*) FROM showcase_moderation_audit')->fetchColumn()===2,'Exactly two audit records');
rqCheck(count(array_diff($calls,['submit','moderate']))===0,'Policy receives only known actions');

$html=ShowcaseForm::intake('/showcase/submit',$csrf,$form,'<script>alert(1)</script>', ['display_name'=>'" onfocus="alert(1)','caption'=>'</textarea><img src=x onerror=alert(1)>','email'=>'person@example.invalid'],'invalid_input');
foreach (['<script>','<img','value="" onfocus=', 'checked','coupon'] as $absent) { rqCheck(strpos($html,$absent)===false,'No unsafe/premature form content: '.$absent); }
foreach (['enctype="multipart/form-data"','name="csrf_token"','name="form_id"','role="alert"','&lt;script&gt;','&lt;/textarea&gt;','aria-describedby="showcase-photo-help"','value="1"','maxlength="2000"'] as $present) { rqCheck(strpos($html,$present)!==false,'Accessible bounded form: '.$present); }
foreach (['//evil.invalid/x','https://evil.invalid','/../foo','/foo?x=1','/foo"'] as $action) {
    rqReject(static function () use ($action,$csrf,$form,$permission) { ShowcaseForm::intake($action,$csrf,$form,$permission); }, LogicException::class,'Same origin form action');
}
rqReject(static function () use ($csrf,$form,$permission) { ShowcaseForm::intake('/submit',$csrf,$form,$permission,[],'SQL error'); }, InvalidArgumentException::class,'Errors are fixed messages');
rqCheck(strpos(ShowcaseForm::intake('/submit',$csrf,$form,$permission,['caption'=>['bad']]),'Array')===false,'Malformed old input not reflected');
foreach (['pending_review','approved','rejected','withdrawn'] as $status) {
    $out=ShowcaseForm::receipt(array_replace($receipt,['status'=>$status,'email'=>'private@example.invalid']));
    rqCheck(strpos($out,'private@')===false && strpos($out,'Your submission is saved')!==false && strpos($out,'coupon')===false,'Minimal truthful retry receipt');
}
rqReject(static function () { ShowcaseForm::receipt(['id'=>'<script>']); }, InvalidArgumentException::class,'Malformed receipt rejected');
echo "Showcase request/form: $checks assertions passed.\n";
