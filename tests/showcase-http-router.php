<?php
declare(strict_types=1);
// Synthetic loopback fixture only. Never include from or deploy as an application route.
$key = getenv('FAS_SHOWCASE_FIXTURE_KEY');
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($key) || strlen($key) !== 64 || !is_string($_SERVER['HTTP_X_SHOWCASE_FIXTURE'] ?? null)
    || !hash_equals($key, $_SERVER['HTTP_X_SHOWCASE_FIXTURE'])) { http_response_code(404); exit; }
foreach (['Photo','Submission','Repository','Request','Form','Views'] as $name) { require_once __DIR__ . '/../src/showcase/Showcase' . $name . '.php'; }
use FAS\Showcase\ShowcaseRepository;
use FAS\Showcase\ShowcaseRequest;
use FAS\Showcase\ShowcaseForm;
use FAS\Showcase\ShowcaseViews;
session_start();
header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');
// Keyed synthetic diagnostic only; never part of an application route.
header('X-Showcase-Post-Limit: ' . ini_get('post_max_size'));
header('X-Showcase-Request-Length: ' . ($_SERVER['CONTENT_LENGTH'] ?? '0'));
header('X-Showcase-Empty-Native: ' . ($_POST === [] && $_FILES === [] ? '1' : '0'));
$db = new PDO('sqlite:' . getenv('FAS_SHOWCASE_FIXTURE_DB'), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/fixture/bootstrap') {
    if (!isset($_SESSION['csrf'])) { $_SESSION['csrf']=bin2hex(random_bytes(32)); $_SESSION['form']=bin2hex(random_bytes(32)); $_SESSION['active']=true; }
    echo json_encode(['csrf'=>$_SESSION['csrf'],'form'=>$_SESSION['form']]); exit;
}
if ($path === '/fixture/new-form') { $_SESSION['form']=bin2hex(random_bytes(32)); echo json_encode(['form'=>$_SESSION['form']]); exit; }
if ($path === '/fixture/revoke') { $_SESSION['active']=false; echo '{}'; exit; }
if ($path === '/fixture/restore') { $_SESSION['active']=true; echo '{}'; exit; }
if ($path === '/fixture/counts') {
    echo json_encode(['submissions'=>(int)$db->query('SELECT COUNT(*) FROM showcase_submissions')->fetchColumn(),
        'audit'=>(int)$db->query('SELECT COUNT(*) FROM showcase_moderation_audit')->fetchColumn()]); exit;
}
$repo = new ShowcaseRepository($db, static function ($actor,$action,$snapshot): bool { return ($_SESSION['active'] ?? false) === true && $actor===7; },10,20000000);
$permission = 'Synthetic permission fixture. Not approved legal wording.';
$request = new ShowcaseRequest($repo,'test-v1',$permission,static function ($action): bool {
    return ($_SERVER['HTTP_X_FIXTURE_GATE'] ?? 'allow') === 'allow' && ($action === 'submit' || ($_SESSION['active'] ?? false) === true);
});
$publicPolicy=static function ($snapshot): bool { return ($_SERVER['HTTP_X_FIXTURE_PUBLICATION'] ?? '')==='allow' && $snapshot['consent_version']==='test-v1'; };
try {
    if ($path === '/submit') {
        $files=$_FILES;
        // Test malformed native metadata without weakening production provenance checks.
        if (isset($files['photo']) && ($_SERVER['HTTP_X_FIXTURE_FILE'] ?? '') === 'size') { ++$files['photo']['size']; }
        if (isset($files['photo']) && ($_SERVER['HTTP_X_FIXTURE_FILE'] ?? '') === 'path') { $files['photo']['tmp_name']=__FILE__; }
        $result=$request->submit($_SERVER,$_POST,$files,$_SESSION['csrf'] ?? '',$_SESSION['form'] ?? '');
    } elseif ($path === '/moderate') {
        $result=$request->moderate($_SERVER,$_POST,$_SESSION['csrf'] ?? '',7);
    } elseif ($path === '/review') {
        $result=$repo->readForModeration((string)($_GET['id'] ?? ''),7);
        $result['photo_sha256_actual']=hash('sha256',$result['photo_jpeg']);
        unset($result['photo_jpeg']);
    } elseif ($path === '/gallery') {
        $result=$repo->approvedGallery($publicPolicy);
    } elseif ($path === '/gallery-view') {
        header('Content-Type: text/html; charset=utf-8');
        echo ShowcaseViews::gallery($repo->approvedGallery($publicPolicy),'/gallery-photo'); exit;
    } elseif ($path === '/gallery-photo') {
        $response=$repo->approvedPhoto((string)($_GET['id'] ?? ''),$publicPolicy);
        foreach ($response['headers'] as $name=>$value) { header($name . ': ' . $value); }
        echo $response['body']; exit;
    } elseif ($path === '/queue-view') {
        $state=(string)($_GET['status'] ?? 'pending_review');
        $cursor=isset($_GET['before_time']) || isset($_GET['before_id']) ? ['accepted_at'=>$_GET['before_time'] ?? null,'id'=>$_GET['before_id'] ?? null] : null;
        $rows=$repo->moderationQueue(7,$state,20,$cursor);
        header('Content-Type: text/html; charset=utf-8');
        echo ShowcaseViews::queue($rows,'/review-view','/queue-view',$state); exit;
    } elseif ($path === '/review-view') {
        $row=$repo->readForModeration((string)($_GET['id'] ?? ''),7);
        $audit=$repo->moderationAudit($row['id'],7);
        header('Content-Type: text/html; charset=utf-8');
        echo ShowcaseViews::review($row,$audit,$_SESSION['csrf'] ?? '','/moderate','/private-photo'); exit;
    } elseif ($path === '/private-photo') {
        $row=$repo->readForModeration((string)($_GET['id'] ?? ''),7);
        header('Content-Type: image/jpeg'); header('Content-Length: ' . strlen($row['photo_jpeg']));
        echo $row['photo_jpeg']; exit;
    } elseif ($path === '/form') {
        header('Content-Type: text/html; charset=utf-8');
        echo ShowcaseForm::intake('/submit',$_SESSION['csrf'] ?? '',$_SESSION['form'] ?? '',$permission); exit;
    } else { http_response_code(404); exit; }
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code($e instanceof OutOfBoundsException ? 404 : ($e instanceof DomainException ? 409 : 400));
    echo json_encode(['error'=>get_class($e)]); // No raw exception, path, email, SQL or photo.
}
