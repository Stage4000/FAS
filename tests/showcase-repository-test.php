<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/showcase/ShowcasePhoto.php';
require_once __DIR__ . '/../src/showcase/ShowcaseSubmission.php';
require_once __DIR__ . '/../src/showcase/ShowcaseRepository.php';

use FAS\Showcase\ShowcasePhoto;
use FAS\Showcase\ShowcaseSubmission;
use FAS\Showcase\ShowcaseRepository;

const SR_TEXT = 'Synthetic reviewed photo permission. Test fixture only.';
$srChecks = 0;
function srSqlTransactionActive(PDO $db): bool
{
    try { $db->exec('BEGIN DEFERRED'); }
    catch (PDOException $error) {
        if (strpos($error->getMessage(), 'cannot start a transaction within a transaction') === false) throw $error;
        return true;
    }
    $db->exec('ROLLBACK');
    return false;
}
function srCheck(bool $ok, string $message): void {
    global $srChecks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$srChecks;
}
function srReject(callable $operation, string $type, string $message): void {
    try { $operation(); } catch (Throwable $e) {
        srCheck($e instanceof $type, $message . ': unexpected ' . get_class($e));
        srCheck(strpos($e->getMessage(), 'person@example.invalid') === false, 'Error does not disclose private email');
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function srDb(string $path = ':memory:'): PDO {
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA busy_timeout=2500');
    return $db;
}
function srPhoto(int $color = 0x125577): ShowcasePhoto {
    $im = imagecreatetruecolor(16, 12);
    imagefilledrectangle($im, 0, 0, 15, 11, $color);
    ob_start(); imagepng($im); $png = ob_get_clean();
    return ShowcasePhoto::fromBytes($png);
}
function srSubmission(array $replace = [], string $version = 'test-v1', string $text = SR_TEXT, ?ShowcasePhoto $photo = null): ShowcaseSubmission {
    return ShowcaseSubmission::prepare(array_replace(['display_name' => "O'Neil <b>Test</b>", 'email' => 'person@example.invalid',
        'caption' => "'); DROP TABLE showcase_submissions; --", 'consent' => true], $replace), $photo ?? srPhoto(), $version, $text);
}
function srRepo(PDO $db, int $count = 100, int $bytes = 10000000, ?callable $authorize = null): ShowcaseRepository {
    return new ShowcaseRepository($db, $authorize ?? static function (int $actor, string $action, array $snapshot): bool {
        return $actor === 7 && in_array($action, ['review','moderate'], true) && isset($snapshot['id']);
    }, $count, $bytes);
}
function srKey(string $suffix): string { return str_pad($suffix, 40, '_'); }
function srUsage(PDO $db): array {
    return $db->query('SELECT COUNT(*) AS entries,COALESCE(SUM(photo_bytes),0) AS bytes FROM main.showcase_submissions')->fetch(PDO::FETCH_ASSOC);
}
function srDecision(ShowcaseRepository $repo, array $receipt, string $state, int $actor = 7): array {
    $r = $repo->readForModeration($receipt['id'], 7);
    return $repo->moderate($r['id'], $actor, $r['version'], $r['photo_sha256'], $r['consent_text_sha256'], $state, 'Synthetic review decision');
}

// Separate processes compete for one final slot in a synthetic file-backed DB.
if (($argv[1] ?? '') === '--concurrent-worker') {
    $db = srDb($argv[2]);
    $repo = srRepo($db, 1);
    file_put_contents($argv[3], 'ready');
    $deadline = microtime(true) + 10;
    while (!is_file($argv[4]) && microtime(true) < $deadline) { usleep(10000); }
    if (!is_file($argv[4])) { fwrite(STDERR, 'Barrier timed out'); exit(3); }
    try { $repo->accept(srSubmission(), SR_TEXT, srKey($argv[5])); echo 'accepted'; }
    catch (OverflowException $e) { echo 'full'; }
    exit;
}

$db = srDb();
srReject(static function () use ($db): void { srRepo($db); }, LogicException::class, 'Construction never creates missing tables');
srCheck((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'showcase_%'")->fetchColumn() === 0, 'No implicit schema');
ShowcaseRepository::initializeSchema($db);
ShowcaseRepository::initializeSchema($db);
$repo = srRepo($db);
$submission = srSubmission();
$photoBytes = $submission->photo()->metadata()['bytes'];
$before = time();
$first = $repo->accept($submission, SR_TEXT, srKey('first'));
srCheck(array_keys($first) === ['id','status','version','accepted_at'], 'Receipt whitelist excludes private fields');
srCheck($first['status'] === 'pending_review' && $first['version'] === 1, 'Pending initial state');
srCheck(preg_match('/\A[a-f0-9]{32}\z/', $first['id']) === 1, 'Opaque generated identity');
srCheck(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $first['accepted_at']) === 1, 'UTC timestamp');
srCheck(strtotime($first['accepted_at']) >= $before && strtotime($first['accepted_at']) <= time(), 'Server acceptance time');
$review = $repo->readForModeration($first['id'], 7);
srCheck($review['photo_jpeg'] === $submission->photo()->bytes(), 'Exact normalized derivative atomically stored');
srCheck($review['contact_email'] === 'person@example.invalid' && $review['caption'] === $submission->privateRecord()['caption'], 'Private fields and SQL-like plain text preserved');
srCheck($review['consent_text'] === SR_TEXT && $review['consent_text_sha256'] === hash('sha256', SR_TEXT), 'Exact consent archive');
srCheck($repo->accept($submission, SR_TEXT, srKey('first')) === $first, 'Exact retry keeps identity and time');
srCheck(srUsage($db) === ['entries' => 1, 'bytes' => $photoBytes], 'Retry consumes no capacity');
foreach ([srSubmission(['email' => 'other@example.invalid']), srSubmission(['caption' => 'changed']), srSubmission(['display_name' => 'Changed']),
    srSubmission([], 'test-v2'), srSubmission([], 'test-v1', SR_TEXT . ' Changed'), srSubmission([], 'test-v1', SR_TEXT, srPhoto(0xFF0000))] as $changed) {
    $text = $changed->privateRecord()['consent_text_sha256'] === hash('sha256', SR_TEXT) ? SR_TEXT : SR_TEXT . ' Changed';
    srReject(static function () use ($repo, $changed, $text): void { $repo->accept($changed, $text, srKey('first')); }, DomainException::class, 'Material retry conflict');
}
srReject(static function () use ($repo, $submission): void { $repo->accept($submission, 'Wrong wording', srKey('wrong')); }, InvalidArgumentException::class, 'Mismatched consent archive');
srReject(static function () use ($repo): void { $repo->accept(srSubmission([], 'test-v1', 'Different valid wording'), 'Different valid wording', srKey('consent-conflict')); }, DomainException::class, 'Version wording immutable');
srCheck(srUsage($db)['entries'] === 1, 'Conflicts do not insert');
foreach (['', 'short', str_repeat('x',129), str_repeat('a',32) . "\n", 'person@example.invalid'] as $badKey) {
    srReject(static function () use ($repo,$submission,$badKey): void { $repo->accept($submission,SR_TEXT,$badKey); }, InvalidArgumentException::class, 'Invalid retry identity');
}
foreach ([[0,1],[-1,1],[1000001,1],[1,0],[1,-1]] as $limits) {
    srReject(static function () use ($db,$limits): void { srRepo($db,$limits[0],$limits[1]); }, InvalidArgumentException::class, 'Invalid explicit budgets');
}
$fullRepo = srRepo($db, 1, $photoBytes);
srCheck($fullRepo->accept($submission, SR_TEXT, srKey('first')) === $first, 'Retry succeeds at exact full capacity');
srReject(static function () use ($fullRepo,$submission): void { $fullRepo->accept($submission,SR_TEXT,srKey('second')); }, OverflowException::class, 'Count boundary enforced');
$bytesRepo = srRepo($db, 100, $photoBytes * 2 - 1);
srReject(static function () use ($bytesRepo,$submission): void { $bytesRepo->accept($submission,SR_TEXT,srKey('second')); }, OverflowException::class, 'Byte boundary enforced');
$tinyRepo = srRepo($db, 100, $photoBytes - 1);
srReject(static function () use ($tinyRepo,$submission): void { $tinyRepo->accept($submission,SR_TEXT,srKey('tiny')); }, OverflowException::class, 'Single item over budget');
$exactRepo = srRepo($db, 100, $photoBytes * 2);
$second = $exactRepo->accept($submission, SR_TEXT, srKey('second'));
srCheck(srUsage($db) === ['entries' => 2, 'bytes' => $photoBytes * 2], 'Exact byte boundary accepted');

// No private values in callback snapshots; strict true, active authorization required.
$callbackCalls = [];
$guarded = srRepo($db, 100, 10000000, static function ($actor,$action,$snapshot) use (&$callbackCalls,$db): bool {
    $callbackCalls[] = [$actor,$action,$snapshot,srSqlTransactionActive($db)]; return $actor === 7;
});
srReject(static function () use ($guarded,$first): void { $guarded->readForModeration($first['id'],8); }, RuntimeException::class, 'Unauthorized review');
srReject(static function () use ($repo,$first,$review): void { $repo->moderate($first['id'],8,1,$review['photo_sha256'],$review['consent_text_sha256'],'approved','review'); }, RuntimeException::class, 'Unauthorized decision');
$truthy = srRepo($db,100,10000000,static function () { return 1; });
srReject(static function () use ($truthy,$first): void { $truthy->readForModeration($first['id'],7); }, RuntimeException::class, 'Truthy authorization is rejected');
foreach ([[0,1,$review['photo_sha256'],$review['consent_text_sha256'],'approved','reason'],[7,0,$review['photo_sha256'],$review['consent_text_sha256'],'approved','reason'],
    [7,1,'bad',$review['consent_text_sha256'],'approved','reason'],[7,1,$review['photo_sha256'],$review['consent_text_sha256'],'published','reason'],
    [7,1,$review['photo_sha256'],$review['consent_text_sha256'],'approved',''],[7,1,$review['photo_sha256'],$review['consent_text_sha256'],'approved',"bad\nreason"]] as $args) {
    srReject(static function () use ($repo,$first,$args): void { $repo->moderate($first['id'],...$args); }, InvalidArgumentException::class, 'Malformed review request');
}
foreach ([[2,$review['photo_sha256'],$review['consent_text_sha256']],[1,str_repeat('0',64),$review['consent_text_sha256']],[1,$review['photo_sha256'],str_repeat('0',64)]] as $snapshot) {
    srReject(static function () use ($repo,$first,$snapshot): void { $repo->moderate($first['id'],7,$snapshot[0],$snapshot[1],$snapshot[2],'approved','reason'); }, DomainException::class, 'Stale or wrong reviewed snapshot');
}
$approved = srDecision($guarded,$first,'approved');
srCheck($approved['version'] === 2 && $approved['status'] === 'approved', 'Approval increments exactly once');
$lastCall = end($callbackCalls);
srCheck($lastCall[1] === 'moderate' && $lastCall[3] === true, 'Authorization rechecked inside writer transaction');
srCheck(array_keys($lastCall[2]) === ['id','status','version','photo_sha256','consent_text_sha256'], 'Callback sees only bounded review identity');
$audit = $db->query('SELECT * FROM showcase_moderation_audit')->fetch(PDO::FETCH_ASSOC);
srCheck($audit['actor_id'] === 7 && $audit['from_status'] === 'pending_review' && $audit['to_status'] === 'approved' && $audit['version'] === 2, 'Audit binds actor, state and version');
srCheck($audit['photo_sha256'] === $review['photo_sha256'] && $audit['consent_text_sha256'] === $review['consent_text_sha256'], 'Audit binds reviewed content');
$active = true;
$revocable = srRepo($db,100,10000000,static function () use (&$active): bool { return $active; });
$beforeRevocation = $revocable->readForModeration($first['id'],7);
$active = false;
srReject(static function () use ($revocable,$beforeRevocation): void { $revocable->moderate($beforeRevocation['id'],7,$beforeRevocation['version'],$beforeRevocation['photo_sha256'],$beforeRevocation['consent_text_sha256'],'withdrawn','reason'); }, RuntimeException::class, 'Authority revoked since review is rechecked');
srCheck($repo->readForModeration($first['id'],7)['status'] === 'approved', 'Revoked actor cannot change state');
srReject(static function () use ($repo,$first,$review): void { $repo->moderate($first['id'],7,1,$review['photo_sha256'],$review['consent_text_sha256'],'approved','reason'); }, DomainException::class, 'Duplicate old action fails');
foreach (['approved','rejected'] as $state) {
    srReject(static function () use ($repo,$approved,$state): void { srDecision($repo,$approved,$state); }, DomainException::class, 'Approved item cannot be restored or reapproved');
}
$withdrawn = srDecision($repo,$approved,'withdrawn');
srCheck($withdrawn['version'] === 3 && $withdrawn['status'] === 'withdrawn', 'Approved withdrawal');
foreach (['approved','rejected','withdrawn'] as $state) {
    srReject(static function () use ($repo,$withdrawn,$state): void { srDecision($repo,$withdrawn,$state); }, DomainException::class, 'Withdrawn is terminal');
}
$rejected = srDecision($repo,$second,'rejected');
foreach (['approved','rejected'] as $state) {
    srReject(static function () use ($repo,$rejected,$state): void { srDecision($repo,$rejected,$state); }, DomainException::class, 'Rejected cannot be approved');
}
srDecision($repo,$rejected,'withdrawn');
srCheck(srUsage($db) === ['entries' => 2, 'bytes' => $photoBytes * 2], 'Withdrawn and rejected records retain quota');
srReject(static function () use ($exactRepo,$submission): void { $exactRepo->accept($submission,SR_TEXT,srKey('still-full')); }, OverflowException::class, 'Withdrawal is not deletion or quota reclaim');
$third = $repo->accept($submission, SR_TEXT, srKey('third'));
srDecision($repo,$third,'withdrawn');
srReject(static function () use ($repo): void { $repo->readForModeration(str_repeat('0',32),7); }, OutOfBoundsException::class, 'Missing record');

// Failing insertion rolls back newly archived consent and no false receipt escapes.
$db->exec('CREATE UNIQUE INDEX synthetic_unique_email ON showcase_submissions(contact_email) WHERE consent_version = \'failure-v1\'');
$failedVersion = srSubmission([], 'failure-v1');
$repo->accept($failedVersion,SR_TEXT,srKey('failure-setup'));
srReject(static function () use ($repo,$failedVersion): void { $repo->accept($failedVersion,SR_TEXT,srKey('failure-insert')); }, PDOException::class, 'Insertion failure is atomic');
srCheck(!srSqlTransactionActive($db), 'Failed insertion releases owned transaction');
// Independent synthetic database to force failure after archiving a new version.
$atomicDb = srDb(); ShowcaseRepository::initializeSchema($atomicDb); $atomicRepo = srRepo($atomicDb);
$atomicFirst = $atomicRepo->accept($submission, SR_TEXT, srKey('atomic-first'));
$atomicDb->exec('CREATE UNIQUE INDEX synthetic_one_row ON showcase_submissions((1))');
srReject(static function () use ($atomicRepo): void { $atomicRepo->accept(srSubmission([], 'brand-new-v2'),SR_TEXT,srKey('atomic-fail')); }, PDOException::class, 'Failed insert rolls back new consent archive');
srCheck((int)$atomicDb->query('SELECT COUNT(*) FROM showcase_consent_versions')->fetchColumn() === 1 && srUsage($atomicDb)['entries'] === 1, 'No partial acceptance or consent');
$atomicApproved = srDecision($atomicRepo,$atomicFirst,'approved');
$atomicDb->exec('CREATE UNIQUE INDEX synthetic_one_audit ON showcase_moderation_audit((1))');
srReject(static function () use ($atomicRepo,$atomicApproved): void { srDecision($atomicRepo,$atomicApproved,'withdrawn'); }, PDOException::class, 'Audit failure rolls back decision');
$afterAuditFailure = $atomicRepo->readForModeration($atomicFirst['id'],7);
srCheck($afterAuditFailure['status'] === 'approved' && $afterAuditFailure['version'] === 2, 'Failed audit leaves old state/version');
srCheck((int)$atomicDb->query('SELECT COUNT(*) FROM showcase_moderation_audit')->fetchColumn() === 1, 'No partial duplicate audit');
$thrower = srRepo($atomicDb,100,10000000,static function (): bool { throw new RuntimeException('Authorization lookup unavailable'); });
srReject(static function () use ($thrower,$atomicFirst,$review): void { $thrower->moderate($atomicFirst['id'],7,2,$review['photo_sha256'],$review['consent_text_sha256'],'withdrawn','reason'); }, RuntimeException::class, 'Throwing authorization fails closed');
srCheck(!srSqlTransactionActive($atomicDb), 'Authorization exception rolls back acquired transaction');
srCheck($atomicRepo->readForModeration($atomicFirst['id'],7)['status'] === 'approved', 'Authorization error preserves state');

// Caller-owned transactions must never be committed or rolled back.
foreach (['pdo','sql','deferred','plain'] as $start) {
    if ($start === 'pdo') { $db->beginTransaction(); } else { $db->exec($start === 'sql' ? 'BEGIN IMMEDIATE' : ($start === 'deferred' ? 'BEGIN DEFERRED' : 'BEGIN')); }
    $db->exec('CREATE TABLE caller_owned_test(value INTEGER)');
    $db->exec('INSERT INTO caller_owned_test VALUES(42)');
    srReject(static function () use ($repo,$submission): void { $repo->accept($submission,SR_TEXT,srKey('ambient')); }, LogicException::class, 'Ambient transaction rejected');
    srReject(static function () use ($db): void { ShowcaseRepository::initializeSchema($db); }, LogicException::class, 'Initialization rejects ambient transaction');
    srCheck(srSqlTransactionActive($db) && (int)$db->query('SELECT value FROM caller_owned_test')->fetchColumn() === 42, 'Caller transaction untouched');
    $db->exec('ROLLBACK');
}

// Failed ownership probes must never release a caller transaction or proceed.
class SRProbePDO extends PDO {
    public $fail = ''; public $rollbacks = 0;
    #[\ReturnTypeWillChange]
    public function exec($sql) {
        if ($sql === 'ROLLBACK') ++$this->rollbacks;
        if ($sql === $this->fail) throw new RuntimeException('synthetic probe failure');
        return parent::exec($sql);
    }
}
foreach (['BEGIN DEFERRED','ROLLBACK'] as $failure) {
    $probe = new SRProbePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $probe->exec('PRAGMA foreign_keys=ON');$probe->fail=$failure;
    srReject(static function () use ($probe): void { ShowcaseRepository::initializeSchema($probe); }, $failure === 'BEGIN DEFERRED' ? LogicException::class : RuntimeException::class, 'Probe errors reject initialization');
    srCheck($probe->rollbacks === ($failure === 'BEGIN DEFERRED' ? 0 : 1), 'Only a successful owned probe attempts rollback');
    srCheck((int)$probe->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'showcase_%'")->fetchColumn() === 0, 'Failed probe creates no schema');
    $probe->fail=''; if (srSqlTransactionActive($probe)) $probe->exec('ROLLBACK');
}

// Incompatible connection settings are rejected explicitly without changing them.
foreach ([[PDO::ATTR_STRINGIFY_FETCHES,true],[PDO::ATTR_CASE,PDO::CASE_UPPER],[PDO::ATTR_ORACLE_NULLS,PDO::NULL_EMPTY_STRING],[PDO::ATTR_ERRMODE,PDO::ERRMODE_SILENT]] as $setting) {
    $badDb = srDb(); ShowcaseRepository::initializeSchema($badDb); $badDb->setAttribute($setting[0],$setting[1]);
    srReject(static function () use ($badDb): void { srRepo($badDb); }, LogicException::class, 'Incompatible PDO settings');
    srCheck($badDb->getAttribute($setting[0]) === $setting[1], 'Caller settings preserved');
}
$noFk = srDb(); $noFk->exec('PRAGMA foreign_keys=OFF');
srReject(static function () use ($noFk): void { ShowcaseRepository::initializeSchema($noFk); }, LogicException::class, 'Foreign keys required');
srCheck((int)$noFk->query('PRAGMA foreign_keys')->fetchColumn() === 0, 'Foreign-key policy not silently changed');
$noJournal = srDb(); $noJournal->exec('PRAGMA journal_mode=OFF');
srReject(static function () use ($noJournal): void { ShowcaseRepository::initializeSchema($noJournal); }, LogicException::class, 'Journaling OFF cannot provide atomic rollback');
srCheck(strtolower($noJournal->query('PRAGMA journal_mode')->fetchColumn()) === 'off', 'Journaling setting not silently changed');
$partial = srDb(); $partial->exec('CREATE TABLE showcase_submissions(dummy TEXT)');
srReject(static function () use ($partial): void { ShowcaseRepository::initializeSchema($partial); }, LogicException::class, 'Incompatible partial schema rejected');
srCheck((int)$partial->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'showcase_%'")->fetchColumn() === 1, 'Partial existing schema unmodified');
foreach (['main','temp'] as $triggerSchema) {
  foreach (['showcase_submissions','SHOWCASE_SUBMISSIONS','ShowCase_Submissions'] as $triggerTarget) {
    $triggerDb = srDb(); ShowcaseRepository::initializeSchema($triggerDb); $triggerRepo = srRepo($triggerDb);
    $triggerDb->exec('CREATE ' . ($triggerSchema === 'temp' ? 'TEMP ' : '') . 'TRIGGER unsafe_review AFTER INSERT ON main.' . $triggerTarget . " BEGIN UPDATE showcase_submissions SET status='approved' WHERE id=NEW.id; END");
    srReject(static function () use ($triggerRepo,$submission): void { $triggerRepo->accept($submission,SR_TEXT,srKey('unsafe')); }, LogicException::class, 'Unexpected main/temp trigger blocked');
    srCheck(srUsage($triggerDb)['entries'] === 0, 'Trigger cannot silently approve acceptance');
  }
}
$shadowDb = srDb(); ShowcaseRepository::initializeSchema($shadowDb); $shadowRepo = srRepo($shadowDb);
$shadowDb->exec('CREATE TEMP TABLE showcase_submissions(dummy TEXT)');
$shadowReceipt = $shadowRepo->accept($submission,SR_TEXT,srKey('shadow'));
srCheck($shadowRepo->readForModeration($shadowReceipt['id'],7)['status'] === 'pending_review', 'Explicit main schema defeats table shadowing');
srCheck((int)$shadowDb->query('SELECT COUNT(*) FROM temp.showcase_submissions')->fetchColumn() === 0, 'Shadow table remains untouched');
$reduced = srRepo($shadowDb,1,1);
srCheck($reduced->accept($submission,SR_TEXT,srKey('shadow')) === $shadowReceipt, 'Exact retry survives later budget reduction');
ShowcaseRepository::initializeSchema($shadowDb);
srCheck(srUsage($shadowDb)['entries'] === 1, 'Reinitialization preserves accepted data');

// Post-review storage corruption is rejected before approval or byte disclosure.
$corruptDb = srDb(); ShowcaseRepository::initializeSchema($corruptDb); $corruptRepo = srRepo($corruptDb);
$corrupt = $corruptRepo->accept($submission,SR_TEXT,srKey('corrupt'));
$good = $corruptRepo->readForModeration($corrupt['id'],7);
$corruptBytes = str_repeat('x',$photoBytes);
$corruptStmt = $corruptDb->prepare('UPDATE showcase_submissions SET photo_jpeg=?');
$corruptStmt->bindValue(1,$corruptBytes,PDO::PARAM_LOB); $corruptStmt->execute();
srReject(static function () use ($corruptRepo,$corrupt): void { $corruptRepo->readForModeration($corrupt['id'],7); }, UnexpectedValueException::class, 'Corrupt derivative not returned');
srReject(static function () use ($corruptRepo,$corrupt,$good): void { $corruptRepo->moderate($corrupt['id'],7,1,$good['photo_sha256'],$good['consent_text_sha256'],'approved','reason'); }, UnexpectedValueException::class, 'Corrupt derivative not approved');
srCheck($corruptDb->query('SELECT status FROM showcase_submissions')->fetchColumn() === 'pending_review', 'Corruption failure retains pending state');
srReject(static function () use ($corruptRepo,$submission): void { $corruptRepo->accept($submission,SR_TEXT,srKey('corrupt')); }, UnexpectedValueException::class, 'Retry cannot affirm corrupt durable bytes');
$corruptDb->exec("UPDATE showcase_consent_versions SET consent_text='Corrupted wording'");
srReject(static function () use ($corruptRepo,$corrupt): void { $corruptRepo->readForModeration($corrupt['id'],7); }, UnexpectedValueException::class, 'Corrupt consent archive rejected');
$revokedCorrupt = $corruptRepo->moderate($corrupt['id'],7,1,$good['photo_sha256'],$good['consent_text_sha256'],'withdrawn','Revoke despite storage corruption');
srCheck($revokedCorrupt['status'] === 'withdrawn' && $revokedCorrupt['version'] === 2, 'Bound authorized withdrawal remains possible after storage corruption');

// Real concurrent writers on a disposable database, no application config or data.
$temporary = [];
$processes = [];
$fileDb = null;
$otherDb = null;
$otherRepo = null;
try {
    $file = tempnam(sys_get_temp_dir(),'fas-showcase-test-');
    if ($file === false) { throw new RuntimeException('Cannot allocate synthetic database'); }
    $temporary[] = $file;
    $temporary[] = $file . '-journal'; $temporary[] = $file . '-wal'; $temporary[] = $file . '-shm';
    $go = $file . '-go'; $temporary[] = $go;
    $fileDb = srDb($file); ShowcaseRepository::initializeSchema($fileDb);
    $fileDb->exec('PRAGMA synchronous=OFF');
    srReject(static function () use ($fileDb): void { srRepo($fileDb); }, LogicException::class, 'Persistent unsafe synchronization rejected');
    $fileDb->exec('PRAGMA synchronous=FULL');
    $fileDb->exec('PRAGMA journal_mode=MEMORY');
    srReject(static function () use ($fileDb): void { srRepo($fileDb); }, LogicException::class, 'Persistent memory journal rejected');
    $fileDb->exec('PRAGMA journal_mode=DELETE');
    $handles = [];
    foreach ([1,2] as $worker) {
        $ready = $file . '-ready-' . $worker; $temporary[] = $ready;
        $pipes = [];
        $process = proc_open([PHP_BINARY,__FILE__,'--concurrent-worker',$file,$ready,$go,'worker-' . $worker],
            [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start concurrency fixture'); }
        fclose($pipes[0]);
        $processes[] = $process;
        $handles[] = [$process,$pipes,$ready];
    }
    $deadline = microtime(true) + 10;
    while ((!is_file($handles[0][2]) || !is_file($handles[1][2])) && microtime(true) < $deadline) { usleep(10000); }
    srCheck(is_file($handles[0][2]) && is_file($handles[1][2]), 'Both independent writers ready');
    file_put_contents($go,'go');
    $outputs = [];
    foreach ($handles as $handle) {
        $outputs[] = stream_get_contents($handle[1][1]);
        $error = stream_get_contents($handle[1][2]);
        fclose($handle[1][1]); fclose($handle[1][2]);
        $exit = proc_close($handle[0]);
        srCheck($exit === 0 && $error === '', 'Concurrent worker completed cleanly: ' . $error);
    }
    $processes = [];
    sort($outputs);
    srCheck($outputs === ['accepted','full'], 'Exactly one concurrent writer takes last slot');
    srCheck(srUsage($fileDb)['entries'] === 1, 'Concurrent admission cannot exceed budget');
    $otherDb = srDb($file); $otherDb->exec('PRAGMA busy_timeout=0'); $otherRepo = srRepo($otherDb);
    $fileDb->exec('BEGIN IMMEDIATE');
    $fileDb->exec('CREATE TABLE caller_lock_marker(value INTEGER)');
    srReject(static function () use ($otherRepo,$submission): void { $otherRepo->accept($submission,SR_TEXT,srKey('lock')); }, PDOException::class, 'Contended BEGIN fails safely');
    srCheck(srSqlTransactionActive($fileDb) && !srSqlTransactionActive($otherDb), 'Failed BEGIN never rolls back another writer');
    $fileDb->exec('ROLLBACK');
    // A reader permits BEGIN IMMEDIATE and writes, but blocks COMMIT in DELETE mode.
    $fileDb->exec('BEGIN');
    $fileDb->query('SELECT COUNT(*) FROM showcase_submissions')->fetchColumn();
    $commitSubmission = srSubmission([], 'commit-failure-v1');
    srReject(static function () use ($otherRepo,$commitSubmission): void { $otherRepo->accept($commitSubmission,SR_TEXT,srKey('commit-busy')); }, PDOException::class, 'Busy COMMIT rolls back accepted data and consent');
    srCheck(!srSqlTransactionActive($otherDb) && srSqlTransactionActive($fileDb), 'Failed commit releases only owned writer transaction');
    srCheck((int)$otherDb->query("SELECT COUNT(*) FROM showcase_consent_versions WHERE version='commit-failure-v1'")->fetchColumn() === 0 && srUsage($otherDb)['entries'] === 1, 'Busy commit leaves no partial data');
    $fileDb->exec('ROLLBACK');
    $retryAfterCommit = $otherRepo->accept($commitSubmission,SR_TEXT,srKey('commit-busy'));
    srCheck($retryAfterCommit['status'] === 'pending_review' && srUsage($otherDb)['entries'] === 2, 'Retry succeeds after reader releases lock');
    $otherRepo = null; $otherDb = null;
} finally {
    foreach ($processes as $process) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
    if ($fileDb instanceof PDO && srSqlTransactionActive($fileDb)) { $fileDb->exec('ROLLBACK'); }
    $otherRepo = null; $otherDb = null;
    $fileDb = null;
    foreach ($temporary as $path) { if (is_file($path)) { unlink($path); } }
}
echo "Showcase repository: {$srChecks} assertions passed.\n";
