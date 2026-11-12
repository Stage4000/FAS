<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/blog/BlogRepository.php';
use FAS\Blog\BlogContent;
use FAS\Blog\BlogRepository;

$checks = 0;
function check(bool $value, string $label): void {
    global $checks;
    if (!$value) { throw new RuntimeException('FAIL: ' . $label); }
    $checks++;
    echo "PASS: $label\n";
}
function rejects(callable $f, string $label, string $type = Throwable::class): void {
    try { $f(); } catch (Throwable $e) { check($e instanceof $type, $label . ' [' . get_class($e) . ']'); return; }
    check(false, $label . ' did not reject');
}
function database(bool $install = true): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys=ON');
    if ($install) { BlogRepository::install($db); }
    return $db;
}
function data(string $slug = 'test-post'): array {
    return ['title'=>'Test post','slug'=>$slug,'excerpt'=>'Public excerpt','body'=>'Public body',
        'author_name'=>'Review fixture','reviewed'=>true];
}
function digest(PDO $db): string {
    return hash('sha256', serialize([$db->query('SELECT * FROM main.blog_posts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $db->query('SELECT * FROM main.blog_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]));
}

$db = database(false);
$repo = new BlogRepository($db, fn() => true);
check(!$repo::installed($db) && $repo->listPublished() === ['items'=>[],'total'=>0] && $repo->findPublished('missing') === null && $repo->publishedSitemap() === [], 'empty public storage remains empty');
foreach (['','Bad Slug','feed','../private',str_repeat('a',121),"\xc0\xaf"] as $invalidSlug) {
    check($repo->findPublished($invalidSlug) === null,'invalid public slug gives not-found');
}
check((int)$db->query("SELECT count(*) FROM sqlite_master")->fetchColumn() === 0, 'public reads do not install schema');
BlogRepository::install($db);
BlogRepository::install($db);
check(BlogRepository::installed($db), 'installation is explicit and idempotent');
$p = $repo->createDraft(data(), 7, str_repeat('a',32));
check($p['revision'] === 1 && count($p['history']) === 1 && $p['history'][0]['actor_id'] === 7, 'create and audit revision agree');
$before = digest($db);
$retry = $repo->createDraft(data(), 8, str_repeat('a',32));
check($retry['id'] === $p['id'] && digest($db) === $before, 'identical creation retry leaves data and audit unchanged');
rejects(fn() => $repo->createDraft(data('changed'),7,str_repeat('a',32)), 'changed creation retry rejected', DomainException::class);
check(digest($db) === $before, 'creation conflict leaves database unchanged');
check($repo->listPublished()['total'] === 0, 'draft cannot leak through published list');
$p = $repo->save($p['id'],1,'publish',data(),7);
check($p['revision'] === 2 && $p['state'] === 'Published' && $p['slug'] === 'test-post', 'reviewed publish writes live snapshot');
$public = $repo->findPublished('test-post');
$private = data(); $private['title'] = 'PRIVATE DRAFT MARKER'; $private['body'] = 'PRIVATE BODY MARKER';
$p = $repo->save($p['id'],2,'draft',$private,8);
check($p['state'] === 'Unpublished changes' && $repo->findPublished('test-post') === $public, 'draft save leaves published content and timestamps untouched');
check(strpos(json_encode([$repo->listPublished(),$repo->publishedSitemap()]),'PRIVATE') === false, 'private markers absent from public projections');
check(array_keys($public) === ['id','slug','content','published_at','first_published_at','updated_at'], 'public projection omits revision, audit and internal keys');
$before = digest($db);
rejects(fn() => $repo->save($p['id'],2,'publish',data(),7), 'stale revision publish rejected',DomainException::class);
rejects(fn() => $repo->save($p['id'],3,'draft',data('changed'),7), 'canonical slug cannot change in draft',InvalidArgumentException::class);
rejects(fn() => $repo->save($p['id'],3,'publish',array_replace(data(),['reviewed'=>1]),7), 'publish review must be strict boolean true',InvalidArgumentException::class);
check(digest($db) === $before, 'rejected saves leave snapshots and audit unchanged');

// A unique extra index makes the audit insert fail after the post update.
// Successful rollback must restore the post and keep the original audit rows.
$db->exec("CREATE UNIQUE INDEX fail_audit_insert ON blog_history(post_id,actor_id) WHERE actor_id=8");
rejects(fn() => $repo->save($p['id'],3,'draft',$private,8), 'audit insertion failure aborts save',PDOException::class);
check(digest($db) === $before, 'failed audit rolls back revision, draft and metadata');
$db->exec('DROP INDEX fail_audit_insert');
$p = $repo->save($p['id'],3,'withdraw',[],7);
check($p['state'] === 'Withdrawn' && $p['slug'] === 'test-post' && $p['first_published_at'] === $public['first_published_at'], 'withdraw keeps reserved identity and original publication date');
check($repo->listPublished()['total'] === 0 && $repo->findPublished('test-post') === null && $repo->publishedSitemap() === [], 'withdraw removes all public projections');
$other = $repo->createDraft(data(),7,str_repeat('b',32));
rejects(fn() => $repo->save($other['id'],1,'publish',data(),7), 'withdrawn slug remains reserved',DomainException::class);
rejects(fn() => $repo->save($p['id'],4,'draft',data('changed'),7), 'withdraw cannot unlock canonical slug',InvalidArgumentException::class);
$p = $repo->save($p['id'],4,'publish',$private,7);
check($p['first_published_at'] === $public['first_published_at'] && $p['published']['title'] === 'PRIVATE DRAFT MARKER', 'republish retains first publication date and publishes reviewed snapshot');

$authCalls = [];
$denied = new BlogRepository($db,function($actor,$action) use (&$authCalls) { $authCalls[] = [$actor,$action]; return false; });
$before = digest($db);
rejects(fn() => $denied->createDraft(data('denied'),8,str_repeat('d',32)), 'create requires current authorization',RuntimeException::class);
rejects(fn() => $denied->save($p['id'],5,'draft',$private,8), 'save requires current authorization',RuntimeException::class);
rejects(fn() => $denied->getAdmin($p['id'],8), 'private read requires authorization',RuntimeException::class);
check($authCalls === [[8,'draft'],[8,'draft'],[8,'review']] && digest($db) === $before, 'authorization receives exact actor/action and denial leaves data unchanged');
$truthy = new BlogRepository($db,fn() => 1);
rejects(fn() => $truthy->getAdmin($p['id'],8), 'truthy nonboolean authorization fails closed',RuntimeException::class);

$db->exec('PRAGMA query_only=ON');
check($repo->findPublished('test-post')['content']['title'] === 'PRIVATE DRAFT MARKER' && count($repo->publishedSitemap()) === 1 && $repo->listPublished()['total'] === 1, 'public projections work on query-only connection');
$db->exec('PRAGMA query_only=OFF');
$db->exec("CREATE TEMP TABLE blog_posts (id INTEGER, secret TEXT)");
$db->exec("INSERT INTO temp.blog_posts VALUES (1,'SHADOW LEAK')");
check($repo->listPublished()['total'] === 1 && strpos(json_encode($repo->listPublished()),'SHADOW') === false, 'main-qualified queries resist temporary shadow table');
$db->exec('CREATE TEMP TRIGGER block_blog AFTER UPDATE ON main.blog_posts BEGIN SELECT 1; END');
rejects(fn() => $repo->listPublished(), 'temporary trigger on real table rejected',LogicException::class);
$db->exec('DROP TRIGGER temp.block_blog');
$db->beginTransaction();
rejects(fn() => $repo->listPublished(), 'caller-owned PDO transaction rejected',LogicException::class);
$db->rollBack();

$partial = database(false); $partial->exec('CREATE TABLE blog_posts (id INTEGER)');
rejects(fn() => BlogRepository::installed($partial), 'partial storage rejected',LogicException::class);
rejects(fn() => BlogRepository::install($partial), 'installation never repairs or replaces unknown schema',LogicException::class);
check((int)$partial->query("SELECT count(*) FROM sqlite_master WHERE name='blog_history'")->fetchColumn() === 0, 'failed installation leaves partial database unchanged');
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);
rejects(fn() => $repo->listPublished(), 'altered fetch mode rejected',LogicException::class);
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);

foreach (["\0", "\x01", "\x7f", "\xc0\xaf"] as $bad) { rejects(fn() => BlogContent::validate(['title'=>$bad]), 'invalid text encoding/control rejected',InvalidArgumentException::class); }
check(BlogContent::validate(['title'=>str_repeat('??',160)])['title'] === str_repeat('??',160), 'length limit counts unicode characters');
rejects(fn() => BlogContent::validate(['title'=>str_repeat('??',161)]),'unicode length over limit rejected',InvalidArgumentException::class);
rejects(fn() => BlogContent::validate(['body'=>str_repeat('a',60001)]),'body limit enforced',InvalidArgumentException::class);
foreach (['/gallery/../x.jpg','/gallery/x.svg','https://example.com/x.jpg','/gallery//x.jpg','/gallery/x.jpg?x=1'] as $bad) { rejects(fn() => BlogContent::validate(['cover_image'=>$bad]), 'unsafe cover path rejected',InvalidArgumentException::class); }
check(BlogContent::validate(['cover_image'=>'/gallery/sub/photo-01.WEBP'])['cover_image'] === '/gallery/sub/photo-01.WEBP','valid gallery path retained');
foreach ([['1'],[0],[2147483648],range(1,7)] as $bad) { rejects(fn() => BlogContent::validate(['related_product_ids'=>$bad]), 'invalid product IDs rejected',InvalidArgumentException::class); }
check(BlogContent::validate(['related_product_ids'=>[3,3,2]])['related_product_ids'] === [3,2],'related IDs deduplicated without coercion');

foreach ([
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '[click](javascript:alert(1))',
    '[click](JaVaScRiPt:alert(1))',
    '[click](data:text/html,evil)',
    '[click](//example.com)',
    '[click](/\\example.com)',
    '[click](javascript&#58;alert)',
    '[click](https://user:pass@example.com)',
] as $bad) {
    $html = BlogContent::render($bad);
    check(!preg_match('/<(script|img|a)\b/i',$html), 'unsafe markup/link escaped: ' . $bad);
}
check(BlogContent::render('[<b>](https://example.com/?x=1&y=2)') === '<p><a href="https://example.com/?x=1&amp;y=2">&lt;b&gt;</a></p>' . "\n", 'safe URL and label are attribute/text escaped');
check(BlogContent::render("## Heading\n\n- First\n- Second\n\nText") === "<h2>Heading</h2>\n<ul>\n<li>First</li>\n<li>Second</li>\n</ul>\n<p>Text</p>\n",'document block output remains balanced');

// Persistent database checks use only a new disposable fixture, never site data.
$path = tempnam(sys_get_temp_dir(),'fas-blog-review-');
if ($path === false) { throw new RuntimeException('Could not create isolated fixture.'); }
$first = $second = $reader = $firstRepo = $secondRepo = $readRepo = null;
try {
    $first = new PDO('sqlite:' . $path);
    $first->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $first->exec('PRAGMA foreign_keys=ON; PRAGMA synchronous=FULL');
    BlogRepository::install($first);
    $firstRepo = new BlogRepository($first,fn() => true);
    $live = $firstRepo->createDraft(data('persistent'),7,str_repeat('p',32));
    $firstRepo->save($live['id'],1,'publish',data('persistent'),7);
    $reader = new PDO('sqlite:' . $path,null,null,[PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
    $reader->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $reader->exec('PRAGMA foreign_keys=ON');
    $readRepo = new BlogRepository($reader,fn() => false);
    check($readRepo->findPublished('persistent')['content']['body'] === 'Public body','public methods work with physically read-only database handle');
    rejects(fn() => $reader->exec("UPDATE blog_posts SET revision=100"),'readonly handle genuinely prevents writes',PDOException::class);
    $second = new PDO('sqlite:' . $path);
    $second->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $second->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=0');
    $secondRepo = new BlogRepository($second,fn() => true);
    $before = digest($first);
    $first->exec('BEGIN IMMEDIATE');
    rejects(fn() => $secondRepo->save($live['id'],2,'draft',data('persistent'),7),'competing writer cannot enter another immediate transaction',PDOException::class);
    $first->exec('ROLLBACK');
    check(digest($first) === $before,'lock conflict leaves content and audit unchanged');
    $firstRepo->save($live['id'],2,'draft',data('persistent'),7);
    rejects(fn() => $secondRepo->save($live['id'],2,'publish',data('persistent'),7),'separate connection rejects stale revision after competing commit',DomainException::class);
    check($secondRepo->createDraft(data('persistent'),7,str_repeat('p',32))['id'] === $live['id'],'creation idempotency holds across connections after later edits');
    $second->exec('PRAGMA synchronous=NORMAL');
    rejects(fn() => $secondRepo->listPublished(),'nondurable persistent synchronization rejected',LogicException::class);
    $second->exec('PRAGMA synchronous=FULL; PRAGMA journal_mode=MEMORY');
    rejects(fn() => $secondRepo->listPublished(),'memory journal rejected on persistent database',LogicException::class);
} finally {
    $firstRepo = $secondRepo = $readRepo = $first = $second = $reader = null;
    if (is_file($path)) { unlink($path); }
}
echo "\n$checks checks passed.\n";
