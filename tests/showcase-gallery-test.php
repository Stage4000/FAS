<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['Photo','Submission','Repository','Views'] as $name) { require_once __DIR__ . '/../src/showcase/Showcase' . $name . '.php'; }
use FAS\Showcase\ShowcasePhoto;
use FAS\Showcase\ShowcaseSubmission;
use FAS\Showcase\ShowcaseRepository;
use FAS\Showcase\ShowcaseViews;
$gchecks=0;
function gcheck(bool $condition,string $label): void { global $gchecks; if (!$condition) { throw new RuntimeException($label); } ++$gchecks; }
function greject(callable $fn,string $type,string $label): void { try { $fn(); } catch (Throwable $e) { gcheck($e instanceof $type,$label.': '.get_class($e)); return; } throw new RuntimeException('Expected rejection: '.$label); }
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $db->exec('PRAGMA foreign_keys=ON'); ShowcaseRepository::initializeSchema($db);
$active=true; $authCalls=[];
$repo=new ShowcaseRepository($db,static function ($actor,$action,$snapshot) use (&$active,&$authCalls): bool { $authCalls[]=$action; return $active && $actor===7 && in_array($action,['list','review','moderate'],true); },100,10000000);
$im=imagecreatetruecolor(5,3); ob_start(); imagepng($im); $photo=ShowcasePhoto::fromBytes(ob_get_clean());
$consent='Synthetic <b>photo permission</b> fixture only.';
$make=static function (string $suffix) use ($repo,$photo,$consent): array {
    $sub=ShowcaseSubmission::prepare(['display_name'=>'<script>Customer</script>','email'=>'private@example.invalid','caption'=>"<img src=x onerror=alert(1)>\nSecond line",'consent'=>true],$photo,'test-v1',$consent);
    return $repo->accept($sub,$consent,str_pad($suffix,64,'_'));
};
$decide=static function (array $receipt,string $status) use ($repo): array { $row=$repo->readForModeration($receipt['id'],7); return $repo->moderate($row['id'],7,$row['version'],$row['photo_sha256'],$row['consent_text_sha256'],$status,'<script>Audit reason</script>'); };
$pending=$make('pending'); $approved=$decide($make('approved'),'approved'); $rejected=$decide($make('rejected'),'rejected'); $withdrawn=$decide($make('withdrawn'),'withdrawn');
$snapshots=[]; $allow=static function ($snapshot) use (&$snapshots): bool { $snapshots[]=$snapshot; return $snapshot['consent_version']==='test-v1'; };
foreach (['pending_review','approved','rejected','withdrawn'] as $state) {
    $queue=$repo->moderationQueue(7,$state);
    gcheck(count($queue)===1 && $queue[0]['status']===$state,'Queue explicitly filters state');
    gcheck(array_intersect(['contact_email','photo_jpeg'],array_keys($queue[0]))===[],'Queue excludes contact and binary');
}
foreach ([0,-1,51,PHP_INT_MAX] as $limit) {
    greject(static function () use ($repo,$limit) { $repo->moderationQueue(7,'pending_review',$limit); },InvalidArgumentException::class,'Bounded private queue');
    greject(static function () use ($repo,$allow,$limit) { $repo->approvedGallery($allow,$limit); },InvalidArgumentException::class,'Bounded public gallery');
}
greject(static function () use ($repo) { $repo->moderationQueue(7,'any'); },InvalidArgumentException::class,'Known queue state only');
greject(static function () use ($repo) { $repo->moderationQueue(8); },RuntimeException::class,'Current admin queue authorization');
$active=false;
greject(static function () use ($repo) { $repo->moderationQueue(7); },RuntimeException::class,'Revoked queue access refused');
greject(static function () use ($repo,$approved) { $repo->moderationAudit($approved['id'],7); },RuntimeException::class,'Revoked audit access refused');
$active=true;
gcheck(in_array('list',$authCalls,true),'Queue has explicit authorization action');
$items=$repo->approvedGallery($allow);
gcheck(count($items)===1 && $items[0]['id']===$approved['id'],'Only approved candidate projected');
$keys=array_keys($items[0]); sort($keys); gcheck($keys===['caption','display_name','id'],'Strict minimal public fields');
gcheck(strpos(json_encode($items),'private@example.invalid')===false,'No private contact in public projection');
foreach ($snapshots as $snapshot) { gcheck(array_intersect(['contact_email','caption','display_name','consent_text'],array_keys($snapshot))===[],'Policy snapshot excludes personal content'); }
foreach ([false,1,'true',null] as $result) {
    $policy=static function () use ($result) { return $result; };
    gcheck($repo->approvedGallery($policy)===[],'Explicit literal public approval required');
    greject(static function () use ($repo,$approved,$policy) { $repo->approvedPhoto($approved['id'],$policy); },OutOfBoundsException::class,'Denied image unavailable');
}
$fail=static function () { throw new RuntimeException('policy unavailable'); };
greject(static function () use ($repo,$fail) { $repo->approvedGallery($fail); },RuntimeException::class,'Gallery policy errors fail closed');
greject(static function () use ($repo,$approved,$fail) { $repo->approvedPhoto($approved['id'],$fail); },RuntimeException::class,'Image policy errors fail closed');
foreach ([$pending,$rejected,$withdrawn] as $notPublic) {
    greject(static function () use ($repo,$notPublic,$allow) { $repo->approvedPhoto($notPublic['id'],$allow); },OutOfBoundsException::class,'Unapproved image unavailable');
}
$response=$repo->approvedPhoto($approved['id'],$allow);
gcheck($response['body']===$photo->bytes(),'Only regenerated JPEG bytes served');
foreach (['Content-Type'=>'image/jpeg','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store','Pragma'=>'no-cache','Content-Length'=>(string)strlen($photo->bytes())] as $name=>$value) { gcheck($response['headers'][$name]===$value,'Safe image response header '.$name); }
$rendered=ShowcaseViews::gallery($items,'/showcase/photo');
foreach (['<script>','<img src=x','private@example.invalid','Accepted photo permission','csrf_token'] as $bad) { gcheck(strpos($rendered,$bad)===false,'Public view excludes unsafe/private content '.$bad); }
foreach (['&lt;script&gt;','&lt;img','<br>','loading="lazy"','/showcase/photo?id='] as $good) { gcheck(strpos($rendered,$good)!==false,'Public view escapes/preserves contract '.$good); }
$row=$repo->readForModeration($approved['id'],7);
greject(static function () use ($row) { ShowcaseViews::gallery([$row],'/photo'); },InvalidArgumentException::class,'Private row cannot be passed to public renderer');
$csrf=str_repeat('c',64); $audit=$repo->moderationAudit($approved['id'],7);
gcheck(count($audit)===1 && $audit[0]['actor_id']===7 && $audit[0]['to_status']==='approved','Private audit preserves accountable history');
$review=ShowcaseViews::review($row,$audit,$csrf,'/admin/showcase','/admin/showcase-photo');
foreach (['private@example.invalid','&lt;b&gt;photo permission','name="photo_sha256"','name="consent_text_sha256"','name="version" value="2"','name="csrf_token"','value="withdrawn"','&lt;script&gt;Audit reason'] as $good) { gcheck(strpos($review,$good)!==false,'Private review contains required data '.$good); }
gcheck(strpos($review,'value="approved"')===false && strpos($review,'value="rejected"')===false,'Approved review offers withdrawal only');
gcheck(strpos($review,'<script>')===false,'Private review escapes all content');
$pendingView=ShowcaseViews::review($repo->readForModeration($pending['id'],7),[],$csrf,'/admin/showcase','/admin/photo');
foreach (['approved','rejected','withdrawn'] as $choice) { gcheck(strpos($pendingView,'value="'.$choice.'"')!==false,'Pending review available decision '.$choice); }
$withdrawnView=ShowcaseViews::review($repo->readForModeration($withdrawn['id'],7),$repo->moderationAudit($withdrawn['id'],7),$csrf,'/admin/showcase','/admin/photo');
gcheck(strpos($withdrawnView,'<form')===false && strpos($withdrawnView,'cannot be approved again')!==false,'Withdrawn view offers no mutation');
$queueHtml=ShowcaseViews::queue($repo->moderationQueue(7),'/admin/review');
gcheck(strpos($queueHtml,'<script>')===false && strpos($queueHtml,'?id=')!==false,'Queue escaped with safe ID links');
$make('page-two'); $make('page-three');
$all=$repo->moderationQueue(7); $firstPage=$repo->moderationQueue(7,'pending_review',1); $cursor=['accepted_at'=>$firstPage[0]['accepted_at'],'id'=>$firstPage[0]['id']];
$rest=$repo->moderationQueue(7,'pending_review',50,$cursor);
gcheck(array_merge(array_column($firstPage,'id'),array_column($rest,'id'))===array_column($all,'id'),'Private keyset pages cover every row without duplicates');
$pageHtml=ShowcaseViews::queue($firstPage,'/review','/queue');
gcheck(strpos($pageHtml,'Older submissions')!==false && strpos($pageHtml,'before_id=')!==false && strpos($pageHtml,'aria-current="page"')!==false,'Private queue has accessible state and older-page navigation');
foreach ([[],['id'=>'bad','accepted_at'=>'bad'],['id'=>$pending['id'],'accepted_at'=>[]],['id'=>$pending['id'],'accepted_at'=>'2026-11-13T12:00:00Z','extra'=>'x']] as $badCursor) {
    greject(static function () use ($repo,$badCursor) { $repo->moderationQueue(7,'pending_review',20,$badCursor); },InvalidArgumentException::class,'Strict queue cursor');
}
foreach (['//evil.invalid','https://evil.invalid','/../secret','/x"','/x?redirect=evil'] as $path) {
    greject(static function () use ($items,$path) { ShowcaseViews::gallery($items,$path); },InvalidArgumentException::class,'Trusted photo path only');
    greject(static function () use ($repo,$path) { ShowcaseViews::queue($repo->moderationQueue(7),$path); },InvalidArgumentException::class,'Trusted detail path only');
}
// Even a misbehaving trusted policy cannot return a snapshot it withdraws during the call.
$race=$decide($make('race'),'approved');
$withdrawPolicy=static function () use ($decide,$race): bool { $decide($race,'withdrawn'); return true; };
greject(static function () use ($repo,$race,$withdrawPolicy) { $repo->approvedPhoto($race['id'],$withdrawPolicy); },OutOfBoundsException::class,'Final photo read observes withdrawal');
$raceGallery=$decide($make('race-gallery'),'approved');
$withdrawGallery=static function ($snapshot) use ($decide,$raceGallery): bool { if ($snapshot['id']===$raceGallery['id']) { $decide($raceGallery,'withdrawn'); } return true; };
gcheck(!in_array($raceGallery['id'],array_column($repo->approvedGallery($withdrawGallery),'id'),true),'Final gallery read observes withdrawal');
$decide($approved,'withdrawn');
gcheck($repo->approvedGallery($allow)===[],'Withdrawal removes public listing');
greject(static function () use ($repo,$approved,$allow) { $repo->approvedPhoto($approved['id'],$allow); },OutOfBoundsException::class,'Withdrawal removes future image responses');
// Pin both consent identity and catalog integrity after the trusted policy callback.
$db->prepare('INSERT INTO showcase_consent_versions(version,text_sha256,consent_text) VALUES(?,?,?)')->execute(['test-v2',hash('sha256',$consent),$consent]);
foreach (['gallery','photo'] as $surface) {
    foreach (['version','catalog-hash'] as $mutation) {
        $target=$decide($make('consent-'.$surface.'-'.$mutation),'approved');
        $policy=static function ($snapshot) use ($db,$target,$mutation): bool {
            if ($snapshot['id']!==$target['id']) { return false; }
            if ($mutation==='version') {
                $db->prepare('UPDATE showcase_submissions SET consent_version=? WHERE id=?')->execute(['test-v2',$target['id']]);
            } else {
                $db->prepare('UPDATE showcase_consent_versions SET text_sha256=? WHERE version=?')->execute([str_repeat('0',64),'test-v1']);
            }
            return true;
        };
        if ($surface==='gallery') {
            gcheck($repo->approvedGallery($policy)===[],'Final gallery rejects changed consent '.$mutation);
        } else {
            greject(static function () use ($repo,$target,$policy) { $repo->approvedPhoto($target['id'],$policy); },OutOfBoundsException::class,'Final photo rejects changed consent '.$mutation);
        }
        $db->prepare('UPDATE showcase_consent_versions SET text_sha256=? WHERE version=?')->execute([hash('sha256',$consent),'test-v1']);
        $decide($target,'withdrawn');
    }
}
$corrupt=$decide($make('corrupt'),'approved');
$update=$db->prepare('UPDATE showcase_submissions SET photo_jpeg=? WHERE id=?'); $update->bindValue(1,str_repeat('x',strlen($photo->bytes())),PDO::PARAM_LOB); $update->bindValue(2,$corrupt['id']); $update->execute();
greject(static function () use ($repo,$corrupt,$allow) { $repo->approvedPhoto($corrupt['id'],$allow); },UnexpectedValueException::class,'Corrupt derivative never served');
$db->exec("UPDATE showcase_consent_versions SET consent_text='Changed permission'");
greject(static function () use ($repo,$allow) { $repo->approvedGallery($allow); },UnexpectedValueException::class,'Corrupt consent prevents listing');
greject(static function () use ($repo,$corrupt,$allow) { $repo->approvedPhoto($corrupt['id'],$allow); },UnexpectedValueException::class,'Corrupt consent prevents image');
echo "Showcase gallery/moderation: $gchecks assertions passed.\n";
