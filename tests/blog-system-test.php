<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/blog/BlogContent.php';
require_once __DIR__ . '/../src/blog/BlogRepository.php';
use FAS\Blog\BlogContent;
use FAS\Blog\BlogRepository;
$checks = 0;
function blogCheck(bool $ok,string $why): void { global $checks; if (!$ok) { throw new RuntimeException($why); } ++$checks; }
function blogReject(callable $fn,string $class,string $why): void { try { $fn(); } catch (Throwable $e) { blogCheck($e instanceof $class,$why . ': ' . get_class($e)); return; } throw new RuntimeException('Expected failure: ' . $why); }
function blogDb(string $path = ':memory:'): PDO { $db = new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $db->exec('PRAGMA foreign_keys=ON'); $db->exec('PRAGMA busy_timeout=0'); return $db; }
function blogRepo(PDO $db,?callable $auth = null): BlogRepository { return new BlogRepository($db,$auth ?? static function(int $id,string $action): bool { return $id===7 && in_array($action,['review','draft','publish','withdraw'],true); }); }
function blogInput(array $replace = []): array { return array_replace(BlogContent::empty(),['title'=>'Synthetic parts guide','slug'=>'synthetic-parts-guide','excerpt'=>'A synthetic factual-review fixture.','body'=>"## Check the label\n\nRead the actual part number.\n\n- Check notes\n- Ask about missing parts\n\n[Catalog](/products/motorcycle)",'author_name'=>'Test editor','related_product_ids'=>[10,20],'reviewed'=>true],$replace); }
function blogKey(string $id): string { return str_pad($id,40,'_'); }

// Content renderer and validation.
$content = BlogContent::validate(blogInput(),true);
blogCheck(count($content)===11 && !isset($content['reviewed']), 'Strict content projection');
blogCheck(BlogContent::validate([])===BlogContent::empty(), 'Incomplete draft allowed');
foreach (['title','slug','excerpt','body','author_name'] as $field) { blogReject(static function() use($field):void { BlogContent::validate(blogInput([$field=>'']),true); },InvalidArgumentException::class,'Required publish field '.$field); }
foreach (['Bad-Slug','trailing-','-leading','two--hyphens','../escape','feed','hello world',str_repeat('a',121)] as $slug) { blogReject(static function() use($slug):void { BlogContent::validate(blogInput(['slug'=>$slug])); },InvalidArgumentException::class,'Invalid slug'); }
foreach (['title'=>160,'excerpt'=>320,'body'=>60000,'author_name'=>80,'seo_title'=>110,'seo_description'=>160,'cover_alt'=>160] as $field=>$limit) {
    blogCheck(BlogContent::validate([$field=>str_repeat('�',$limit)])[$field]===str_repeat('�',$limit),'Unicode exact limit '.$field);
    blogReject(static function() use($field,$limit):void { BlogContent::validate([$field=>str_repeat('�',$limit+1)]); },InvalidArgumentException::class,'Unicode over limit');
    blogReject(static function() use($field):void { BlogContent::validate([$field=>[]]); },InvalidArgumentException::class,'Array field rejected');
}
foreach (["\xff", "bad\0text", "bad\x1ftext"] as $bad) { blogReject(static function() use($bad):void { BlogContent::validate(['body'=>$bad]); },InvalidArgumentException::class,'Invalid/control text'); }
blogCheck(BlogContent::validate(['related_product_ids'=>[1,1,2]])['related_product_ids']===[1,2],'Related IDs deduplicate');
foreach ([[0],[-1],['1'],[true],range(1,7),'1,2'] as $ids) { blogReject(static function() use($ids):void { BlogContent::validate(['related_product_ids'=>$ids]); },InvalidArgumentException::class,'Invalid related IDs'); }
foreach (['https://evil.invalid/image.jpg','//evil.invalid/a.jpg','/gallery/../x.jpg','/gallery/a.svg','/gallery/a.jpg?x=1','/gallery//a.jpg'] as $image) { blogReject(static function() use($image):void { BlogContent::validate(['cover_image'=>$image]); },InvalidArgumentException::class,'Unsafe cover rejected'); }
blogReject(static function():void { BlogContent::validate(blogInput(['cover_image'=>'/gallery/example.jpg']),true); },InvalidArgumentException::class,'Cover alt required');
blogCheck(BlogContent::validate(blogInput(['cover_image'=>'/gallery/example.jpg','cover_alt'=>'Synthetic part']),true)['cover_image']==='/gallery/example.jpg','Safe cover with alt');
$html = BlogContent::render($content['body']);
blogCheck(strpos($html,'<h2>Check the label</h2>')!==false && strpos($html,'<ul>')!==false && strpos($html,'<a href="/products/motorcycle">Catalog</a>')!==false,'Small Markdown renders accessible structure');
$attack = '<script>alert(1)</script><img src=x onerror=alert(2)> [X](javascript:alert) [Y](data:text/html,evil) [Z](//evil.invalid) [Q](/\\evil.invalid)';
$safe = BlogContent::render($attack);
blogCheck(strpos($safe,'<script')===false && strpos($safe,'<img')===false && strpos($safe,'<a ')===false && strpos($safe,'&lt;script&gt;')!==false,'HTML and unsafe link schemes stay literal');
foreach (['https://example.invalid/source','http://example.invalid/source','/product/10/example'] as $link) { blogCheck(strpos(BlogContent::render('[Reference]('.$link.')'),'<a href=')!==false,'Safe reference link'); }
blogCheck(strpos(BlogContent::render('[Bad](https://user:pass@example.invalid)'),'<a ')===false,'Credential URLs rejected');
blogCheck(strpos(BlogContent::render('[<img>](https://example.invalid/?a=1&b=2)'),'<a href="https://example.invalid/?a=1&amp;b=2">&lt;img&gt;</a>')!==false,'Link labels and attributes escaped');

// No implicit schema; absent public blog remains empty.
$db = blogDb(); $repo = blogRepo($db);
blogCheck(!BlogRepository::installed($db),'Fresh database uninstalled');
blogCheck($repo->listPublished()===['items'=>[],'total'=>0] && $repo->findPublished('missing')===null && $repo->publishedSitemap()===[],'Uninstalled public methods safe');
blogReject(static function() use($repo):void { $repo->createDraft(blogInput(),7,blogKey('first')); },RuntimeException::class,'Missing schema write fails');
blogCheck((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'blog_%'")->fetchColumn()===0,'Public reads never create schema');
BlogRepository::install($db); BlogRepository::install($db);
$first = $repo->createDraft(blogInput(),7,blogKey('first'));
blogCheck($first['revision']===1 && $first['state']==='Draft' && $first['published']===null,'New draft private');
blogCheck($repo->getAdmin($first['id'],7)===$first,'Admin exact draft readback');
blogCheck($repo->createDraft(blogInput(),7,blogKey('first'))===$first,'Duplicate create returns same row');
blogCheck($repo->listAdmin(7)['total']===1 && count($first['history'])===1,'Duplicate creates no extra rows or history');
blogReject(static function() use($repo):void { $repo->createDraft(blogInput(['title'=>'Changed']),7,blogKey('first')); },DomainException::class,'Create key payload conflict');
blogReject(static function() use($repo):void { $repo->createDraft(blogInput(),7,'short'); },InvalidArgumentException::class,'Invalid creation key');
blogReject(static function() use($repo):void { $repo->listAdmin(8); },RuntimeException::class,'Unauthorized admin list');
blogReject(static function() use($repo,$first):void { $repo->getAdmin($first['id'],8); },RuntimeException::class,'Unauthorized draft read');
blogReject(static function() use($repo):void { $repo->createDraft(blogInput(),8,blogKey('denied')); },RuntimeException::class,'Unauthorized create');
blogCheck($repo->listPublished()['items']===[] && $repo->findPublished('synthetic-parts-guide')===null,'Draft absent publicly');
$published = $repo->save($first['id'],1,'publish',blogInput(),7);
blogCheck($published['revision']===2 && $published['state']==='Published' && $published['slug']==='synthetic-parts-guide','Explicit publish');
$public = $repo->findPublished('synthetic-parts-guide');
blogCheck(array_keys($public)===['id','slug','content','published_at','first_published_at','updated_at'],'Public whitelist');
blogCheck($public['content']['title']==='Synthetic parts guide' && !isset($public['draft'],$public['revision'],$public['history']),'Public snapshot no private metadata');
$sitemap = $repo->publishedSitemap();
blogCheck($sitemap===[['slug'=>'synthetic-parts-guide','updated_at'=>$public['published_at']]],'Sitemap published only');
$draftChange = blogInput(['title'=>'Unpublished new title','body'=>'PRIVATE DRAFT ONLY']);
$edited = $repo->save($first['id'],2,'draft',$draftChange,7);
blogCheck($edited['state']==='Unpublished changes' && $edited['revision']===3,'Editing published post creates pending changes');
blogCheck($repo->findPublished('synthetic-parts-guide')===$public && $repo->publishedSitemap()===$sitemap,'Draft never changes public content/timestamp');
foreach (['draft','publish','withdraw'] as $action) { blogReject(static function() use($repo,$first,$action):void { $repo->save($first['id'],2,$action,blogInput(),7); },DomainException::class,'Stale '.$action); }
blogCheck($repo->getAdmin($first['id'],7)['revision']===3,'Conflict preserves revision');
blogReject(static function() use($repo,$first):void { $repo->save($first['id'],3,'publish',blogInput(['reviewed'=>'1']),7); },InvalidArgumentException::class,'Strict reviewed confirmation');
blogReject(static function() use($repo,$first):void { $repo->save($first['id'],3,'draft',blogInput(['slug'=>'changed-slug']),7); },InvalidArgumentException::class,'Published canonical slug immutable');
$withdrawn = $repo->save($first['id'],3,'withdraw',[],7);
blogCheck($withdrawn['revision']===4 && $withdrawn['state']==='Withdrawn' && $withdrawn['draft']['title']===$draftChange['title'],'Withdraw retains saved draft');
blogCheck($repo->findPublished('synthetic-parts-guide')===null && $repo->listPublished()['total']===0 && $repo->publishedSitemap()===[],'Withdrawal removes every public surface');
blogReject(static function() use($repo,$first):void { $repo->save($first['id'],4,'withdraw',[],7); },DomainException::class,'Duplicate withdrawal rejected');
$second = $repo->createDraft(blogInput(),7,blogKey('second'));
blogReject(static function() use($repo,$second):void { $repo->save($second['id'],1,'publish',blogInput(),7); },DomainException::class,'Withdrawn slug remains reserved');
$restored = $repo->save($first['id'],4,'publish',$draftChange,7);
blogCheck($restored['first_published_at']===$published['first_published_at'] && $restored['revision']===5,'Republish retains original publication identity');
blogCheck($repo->findPublished('synthetic-parts-guide')['content']['title']===$draftChange['title'],'Republish activates reviewed snapshot');
$secondInput = blogInput(['slug'=>'another-guide','title'=>'Another guide']);
$second = $repo->save($second['id'],1,'publish',$secondInput,7);
blogCheck($repo->listPublished(1)['total']===2 && count($repo->listPublished(1)['items'])===1 && count($repo->listPublished(1,1)['items'])===1,'Bounded public pagination');
foreach (['','Bad-Slug','../secret','preview','trailing-','valid-but-missing'] as $slug) { blogCheck($repo->findPublished($slug)===null,'Invalid/unpublished lookup hidden'); }
foreach ([[0,0],[101,0],[12,-1],[12,1000001]] as $range) { blogReject(static function() use($repo,$range):void { $repo->listPublished($range[0],$range[1]); },InvalidArgumentException::class,'Invalid page bounds'); }
blogCheck($repo->getAdmin(999999,7)===null,'Missing admin record');
$active=true; $guarded=blogRepo($db,static function() use(&$active):bool { return $active; }); $guarded->getAdmin($first['id'],7); $active=false;
blogReject(static function() use($guarded,$first):void { $guarded->save($first['id'],5,'withdraw',[],7); },RuntimeException::class,'Revoked actor rejected at write');
blogCheck($repo->getAdmin($first['id'],7)['revision']===5,'Revoked actor unchanged');
$authorizedDuringWrite=false; $inside=blogRepo($db,static function() use(&$authorizedDuringWrite):bool { $authorizedDuringWrite=true; return true; }); $inside->save($first['id'],5,'draft',$draftChange,7);
blogCheck($authorizedDuringWrite,'Current authorization is checked during write; independent connections verify lock ownership');
$truthy=blogRepo($db,static function(){return 1;}); blogReject(static function() use($truthy):void {$truthy->listAdmin(7);},RuntimeException::class,'Authorization requires Boolean true');

// History failure atomically rolls back published output and revision.
$atomicDb=blogDb(); BlogRepository::install($atomicDb); $atomic=blogRepo($atomicDb); $a=$atomic->createDraft(blogInput(),7,blogKey('atomic'));
$atomicDb->exec('CREATE UNIQUE INDEX synthetic_single_history ON blog_history((1))');
blogReject(static function() use($atomic,$a):void {$atomic->save($a['id'],1,'publish',blogInput(),7);},PDOException::class,'Audit insertion failure');
blogCheck($atomic->getAdmin($a['id'],7)['revision']===1 && $atomic->findPublished('synthetic-parts-guide')===null,'Audit failure rolls back publication');
foreach (['pdo','sql'] as $start) {
    $start==='pdo'?$db->beginTransaction():$db->exec('BEGIN IMMEDIATE');
    $tracked = $db->inTransaction();
    $db->exec('CREATE TABLE caller_blog_test(value INTEGER)');
    blogReject(static function() use($repo):void {$repo->createDraft(blogInput(),7,blogKey('ambient'));},$tracked?LogicException::class:PDOException::class,'Ambient transaction untouched');
    blogCheck($db->inTransaction()===$tracked && (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='caller_blog_test'")->fetchColumn()===1,'Caller DDL still present');
    if ($start==='pdo') { $db->rollBack(); } else { $db->exec('ROLLBACK'); }
}
foreach (['main','temp'] as $schema) { foreach (['blog_posts','BLOG_POSTS','Blog_Posts'] as $target) {
    $triggerDb=blogDb(); BlogRepository::install($triggerDb); $triggerRepo=blogRepo($triggerDb);
    $triggerDb->exec('CREATE '.($schema==='temp'?'TEMP ':'').'TRIGGER unsafe_blog AFTER INSERT ON main.'.$target." BEGIN UPDATE blog_posts SET draft_json='{}' WHERE id=NEW.id; END");
    blogReject(static function() use($triggerRepo):void {$triggerRepo->createDraft(blogInput(),7,blogKey('trigger'));},LogicException::class,'Unexpected trigger blocked');
    blogCheck((int)$triggerDb->query('SELECT COUNT(*) FROM main.blog_posts')->fetchColumn()===0,'Trigger never runs');
} }
$partial=blogDb(); $partial->exec('CREATE TABLE blog_posts(dummy TEXT)');
blogReject(static function() use($partial):void {BlogRepository::install($partial);},LogicException::class,'Partial schema not repaired silently');
foreach ([[PDO::ATTR_STRINGIFY_FETCHES,true],[PDO::ATTR_CASE,PDO::CASE_UPPER],[PDO::ATTR_ORACLE_NULLS,PDO::NULL_EMPTY_STRING]] as $setting) {
    $bad=blogDb(); $bad->setAttribute($setting[0],$setting[1]); blogReject(static function() use($bad):void {blogRepo($bad);},LogicException::class,'PDO settings required');
}
$off=blogDb();$off->exec('PRAGMA journal_mode=OFF');blogReject(static function() use($off):void {BlogRepository::install($off);},LogicException::class,'Rollback-capable journal required');
$shadow=blogDb();BlogRepository::install($shadow);$shadowRepo=blogRepo($shadow);$shadow->exec('CREATE TEMP TABLE blog_posts(dummy TEXT)');
$shadowPost=$shadowRepo->createDraft(blogInput(),7,blogKey('shadow'));blogCheck($shadowPost['revision']===1 && (int)$shadow->query('SELECT COUNT(*) FROM temp.blog_posts')->fetchColumn()===0,'Main table qualification');
$db->exec("UPDATE blog_posts SET published_json='{broken' WHERE id=".$first['id']);
blogReject(static function() use($repo):void {$repo->listPublished();},JsonException::class,'Corrupt published content fails closed');
// Withdrawing corrupt public content must still be possible; response uses good draft.
$revoked=$repo->save($first['id'],6,'withdraw',[],7);blogCheck($revoked['published']===null,'Corrupt publication can be withdrawn');
$dates=blogDb();BlogRepository::install($dates);$datesRepo=blogRepo($dates);$datePost=$datesRepo->createDraft(blogInput(),7,blogKey('dates'));$datesRepo->save($datePost['id'],1,'publish',blogInput(),7);
foreach ([['not-a-date','2026-01-01 00:00:00'],['2026-02-30 00:00:00','2026-01-01 00:00:00'],['2026-01-01 00:00:00','2026-02-01 00:00:00'],['2026-03-01 00:00:00','2026-02-30 00:00:00']] as $pair) {
    $dates->prepare('UPDATE blog_posts SET published_at=?,first_published_at=?')->execute($pair);
    blogReject(static function() use($datesRepo):void {$datesRepo->listPublished();},UnexpectedValueException::class,'Corrupt date blocks index');
    blogReject(static function() use($datesRepo):void {$datesRepo->findPublished('synthetic-parts-guide');},UnexpectedValueException::class,'Corrupt date blocks detail');
    blogReject(static function() use($datesRepo):void {$datesRepo->publishedSitemap();},UnexpectedValueException::class,'Corrupt date blocks sitemap');
}
echo "Blog system: {$checks} assertions passed.\n";
