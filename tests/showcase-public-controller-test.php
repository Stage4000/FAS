<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['Photo','Submission','Repository','Views','PublicController'] as $name) require_once __DIR__.'/../src/showcase/Showcase'.$name.'.php';
use FAS\Showcase\ShowcasePhoto; use FAS\Showcase\ShowcaseSubmission; use FAS\Showcase\ShowcaseRepository; use FAS\Showcase\ShowcasePublicController;
$n=0; function c(bool $ok,string $label): void { global $n; if (!$ok) throw new RuntimeException($label); ++$n; }
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $db->exec('PRAGMA foreign_keys=ON'); ShowcaseRepository::initializeSchema($db);
$repo=new ShowcaseRepository($db,static function($actor){return $actor===7;},100,10000000);
$im=imagecreatetruecolor(3,2); ob_start(); imagepng($im); $photo=ShowcasePhoto::fromBytes(ob_get_clean());
$text='Synthetic permission only'; $hash=hash('sha256',$text);
$make=function(string $key,string $version='fixture-v1') use ($repo,$photo,$text): array { return $repo->accept(ShowcaseSubmission::prepare(['display_name'=>'<script>Ride</script>','email'=>'private@example.invalid','caption'=>'<img src=x>','consent'=>true],$photo,$version,$text),$text,str_pad($key,64,'_')); };
$decide=function(array $receipt,string $status) use ($repo): array { $r=$repo->readForModeration($receipt['id'],7); return $repo->moderate($r['id'],7,$r['version'],$r['photo_sha256'],$r['consent_text_sha256'],$status,'Synthetic'); };
$pending=$make('pending'); $approved=$decide($make('approved'),'approved'); $other=$decide($make('other','fixture-v2'),'approved'); $rejected=$decide($make('rejected'),'rejected');
$enabled=true; $ctl=new ShowcasePublicController($repo,static function() use (&$enabled){return $enabled;},'fixture-v1',$hash,'/showcase-photo.php');
$get=['REQUEST_METHOD'=>'GET']; $call=function($endpoint,$query=[]) use ($ctl,$get) {return $ctl->handle($endpoint,$get,$query,[]);};
$r=$call('gallery'); c($r['status']===200,'gallery works'); c(strpos($r['body'],$approved['id'])!==false,'approved shown');
foreach ([$pending,$rejected,$other] as $hidden) c(strpos($r['body'],$hidden['id'])===false,'other states/version hidden');
foreach (['private@example.invalid','<script>','<img src=x','consent_version','photo_sha256'] as $secret) c(strpos($r['body'],$secret)===false,'no private/unsafe fields');
c(strpos($r['body'],'&lt;script&gt;')!==false,'escaped display');
foreach (['gallery','photo'] as $endpoint) {
 $r=$call($endpoint,$endpoint==='photo'?['id'=>$approved['id']]:[]);
 foreach (['Cache-Control'=>'private, no-store','Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer'] as $key=>$value) c($r['headers'][$key]===$value,'safe headers');
 foreach (['POST','HEAD','DELETE','get',''] as $method) { $m=$ctl->handle($endpoint,['REQUEST_METHOD'=>$method],[],[]); c($m['status']===405 && $m['headers']['Allow']==='GET','method restricted'); }
}
$r=$call('photo',['id'=>$approved['id']]); c($r['body']===$photo->bytes() && $r['headers']['Content-Type']==='image/jpeg' && (int)$r['headers']['Content-Length']===strlen($photo->bytes()),'exact jpeg');
foreach ([$pending,$rejected,$other,['id'=>str_repeat('0',32)]] as $hidden) c($call('photo',['id'=>$hidden['id']])['status']===404,'unavailable indistinguishable');
foreach ([[],['id'=>[]],['id'=>'../x'],['id'=>$approved['id'],'debug'=>'1']] as $query) c($call('photo',$query)['status']===404,'query guarded');
c($call('gallery',['limit'=>'50'])['status']===404,'fixed gallery bound'); c($call('unknown')['status']===404,'fixed dispatch');
c($ctl->handle('gallery',$get,[],['x'=>'1'])['status']===404,'no request body');
foreach ([false,1,'true',null] as $value) { $enabled=$value; c($call('gallery')['status']===404,'strict gate'); c($call('photo',['id'=>$approved['id']])['status']===404,'photo strict gate'); } $enabled=true;
$wrong=new ShowcasePublicController($repo,static function(){return true;},'fixture-v1',str_repeat('0',64),'/photo'); c($wrong->handle('photo',$get,['id'=>$approved['id']],[])['status']===404,'wrong consent hash');
foreach (['gallery','photo'] as $endpoint) { $count=0; $closing=new ShowcasePublicController($repo,static function() use (&$count){return ++$count===1;},'fixture-v1',$hash,'/photo'); c($closing->handle($endpoint,$get,$endpoint==='photo'?['id'=>$approved['id']]:[],[])['status']===404,'mid request close'); }
$fail=new ShowcasePublicController($repo,static function(){throw new RuntimeException('SECRET');},'fixture-v1',$hash,'/photo'); $r=$fail->handle('gallery',$get,[],[]); c($r['status']===503 && strpos($r['body'],'SECRET')===false,'failure sanitized');
$decide($approved,'withdrawn'); c($call('photo',['id'=>$approved['id']])['status']===404,'withdrawal photo'); c(strpos($call('gallery')['body'],$approved['id'])===false,'withdrawal gallery');
foreach ([['',$hash,'/photo'],['valid','bad','/photo'],['valid',$hash,'https://bad.invalid'],['valid',$hash,'//bad'],['valid',$hash,'/../bad'],['valid',$hash,"/photo\n"]] as $args) {try {new ShowcasePublicController($repo,static function(){return true;},...$args); throw new RuntimeException('accepted bad config');} catch (InvalidArgumentException $e) {c(true,'configuration strict');}}
$fresh=$decide($make('reviewer-fresh'),'approved');
foreach ([false,1,'true',null] as $last) {
 $calls=0;
 $x=new FAS\Showcase\ShowcasePublicController($repo,static function() use (&$calls,$last) {return ++$calls===3?$last:true;},'fixture-v1',$hash,'/photo');
 $r=$x->handle('photo',$get,['id'=>$fresh['id']],[]);
 c($r['status']===404 && $r['body']==='Not found.','last gate closure no bytes');
 c($r['headers']['Cache-Control']==='private, no-store','last gate denial no cache');
}
$calls=0;
$x=new FAS\Showcase\ShowcasePublicController($repo,static function() use (&$calls) {if (++$calls===3) throw new RuntimeException('SECRET'); return true;},'fixture-v1',$hash,'/photo');
$r=$x->handle('photo',$get,['id'=>$fresh['id']],[]);
c($r['status']===503 && strpos($r['body'],'SECRET')===false && $r['body']!==$photo->bytes(),'last gate exception no bytes');
$q=$db->prepare('UPDATE showcase_submissions SET photo_jpeg=? WHERE id=?'); $q->bindValue(1,str_repeat('X',strlen($photo->bytes())),PDO::PARAM_LOB); $q->bindValue(2,$fresh['id']); $q->execute();
$r=$call('photo',['id'=>$fresh['id']]); c($r['status']===503 && strpos($r['body'],'CORRUPTED')===false,'corrupt derivative not returned');
$db->prepare('UPDATE showcase_consent_versions SET text_sha256=? WHERE version=?')->execute([str_repeat('0',64),'fixture-v1']);
$r=$call('gallery'); c($r['status']===200 && strpos($r['body'],$fresh['id'])===false,'corrupt catalog hidden');
c($call('photo',['id'=>$fresh['id']])['status']===404,'corrupt catalog image absent');
echo "showcase public controller: $n assertions passed\n";
