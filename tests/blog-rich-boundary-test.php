<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/blog/BlogRepository.php';
use FAS\Blog\BlogContent;
use FAS\Blog\BlogRichDocument;
use FAS\Blog\BlogRepository;

$richBoundaryChecks=0;
function rbCheck(bool $value,string $label):void {
    global $richBoundaryChecks;
    if(!$value)throw new RuntimeException('FAIL: '.$label);
    ++$richBoundaryChecks;
}
function rbReject(callable $fn,string $label,string $class=InvalidArgumentException::class):void {
    try{$fn();}catch(Throwable $error){rbCheck($error instanceof $class,$label.' exception type: '.get_class($error));return;}
    throw new RuntimeException('FAIL: '.$label.' was accepted');
}
function rbDocument(array $blocks):array{return ['version'=>1,'blocks'=>$blocks];}
function rbParagraph(string $text='Visible text'):array{return ['type'=>'paragraph','children'=>[['text'=>$text]]];}
function rbJson(array $document):string{return json_encode($document,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function rbInput(array $document):array{return ['title'=>'Rich boundary fixture','slug'=>'rich-boundary','excerpt'=>'Synthetic review content','author_name'=>'Fixture','body_format'=>'rich_v1','body'=>rbJson($document),'reviewed'=>true];}

$empty=rbDocument([]);
rbCheck(BlogRichDocument::validate($empty)===$empty,'empty document supported for drafts');
rbCheck(!BlogRichDocument::hasContent($empty),'empty document has no publishable content');
rbCheck(BlogRichDocument::validate(rbJson($empty))===$empty,'array and JSON inputs canonicalize identically');
foreach([null,1,true,[],['version'=>'1','blocks'=>[]],['version'=>1.0,'blocks'=>[]],['version'=>2,'blocks'=>[]],['version'=>1],['version'=>1,'blocks'=>null],['version'=>1,'blocks'=>[1=>rbParagraph()]],['version'=>1,'blocks'=>[],'html'=>'<script>']] as $bad){rbReject(fn()=>BlogRichDocument::validate($bad),'malformed document shape');}
foreach(['{broken','null','[]','{"version":1,"blocks":[],"onclick":"evil"}',str_repeat(' ',400001),'{"version":1,"blocks":'.str_repeat('[',25).'0'.str_repeat(']',25).'}'] as $bad){rbReject(fn()=>BlogRichDocument::validate($bad),'malformed/deep/oversize JSON');}

$all=rbDocument([
    ['type'=>'paragraph','children'=>[['text'=>'<script>& "quoted"\n','bold'=>true,'italic'=>true,'underline'=>true,'href'=>'https://example.com/?x=1&y=2']]],
    ['type'=>'heading','level'=>2,'children'=>[['text'=>'Heading two']]],
    ['type'=>'heading','level'=>3,'children'=>[['text'=>'Heading three']]],
    ['type'=>'blockquote','children'=>[['text'=>'Quoted <img src=x onerror=evil>']]],
    ['type'=>'bullet_list','items'=>[[['text'=>'Bullet']],[['text'=>'Second']]]],
    ['type'=>'ordered_list','items'=>[[['text'=>'First numbered']]]],
    ['type'=>'image','src'=>'/gallery/synthetic.jpg','alt'=>'<bad> "alt"','caption'=>'<script>caption & more</script>'],
]);
$canonical=BlogRichDocument::validate($all);
rbCheck(BlogRichDocument::validate($canonical)===$canonical,'validation is idempotent');
rbCheck(BlogRichDocument::validate(rbJson($canonical))===$canonical,'canonical JSON survives a second decode');
$html=BlogRichDocument::render($all);
rbCheck(strpos($html,'<script>')===false && strpos($html,'<bad>')===false && strpos($html,'&lt;script&gt;')!==false,'all text and captions escape HTML');
rbCheck(strpos($html,'<a href="https://example.com/?x=1&amp;y=2"><u><em><strong>')!==false,'safe href escaped with fixed nesting');
rbCheck(strpos($html,'alt="&lt;bad&gt; &quot;alt&quot;"')!==false && substr_count($html,'<img ')===1,'image attributes escaped and only image block renders image');
rbCheck(strpos($html,'<h2>')!==false && strpos($html,'<h3>')!==false && strpos($html,'<blockquote>')!==false && strpos($html,'<ol>')!==false && strpos($html,'<ul>')!==false,'all supported block types render');
rbCheck(strpos(BlogRichDocument::render(rbDocument([rbParagraph("first\nsecond\r\nthird")])),"first<br>second<br>third")!==false,'intentional text line breaks normalize and render without doubled DOM whitespace');
rbCheck(BlogContent::render(rbJson($all),'rich_v1')===$html,'content renderer dispatches rich format');
rbReject(fn()=>BlogContent::render('text','unknown'),'unknown render format');
rbReject(fn()=>BlogContent::validate(['body_format'=>'unknown']),'unknown stored format');

$normalized=BlogRichDocument::validate(rbDocument([['type'=>'paragraph','children'=>[['text'=>'','bold'=>true],['text'=>'x','bold'=>false,'italic'=>false,'underline'=>false,'href'=>'']]]]));
rbCheck($normalized===rbDocument([rbParagraph('x')]),'empty runs, false marks and empty href canonicalize away');
foreach(['onclick','style','html','class','color','children'] as $attribute){
    $bad=rbDocument([['type'=>'paragraph','children'=>[['text'=>'x',$attribute=>'injected']]]]);
    rbReject(fn()=>BlogRichDocument::validate($bad),'unknown run attribute '.$attribute);
}
foreach([
    ['type'=>'html','html'=>'<script>'],
    ['type'=>'paragraph','children'=>[['text'=>'x']],'onclick'=>'bad'],
    ['type'=>'heading','level'=>1,'children'=>[]],
    ['type'=>'heading','level'=>4,'children'=>[]],
    ['type'=>'heading','level'=>'2','children'=>[]],
    ['type'=>'paragraph','children'=>[['text'=>'x','bold'=>1]]],
    ['type'=>'paragraph','children'=>[['text'=>'x','italic'=>'true']]],
    ['type'=>'paragraph','children'=>[['text'=>'x','underline'=>null]]],
    ['type'=>'paragraph','children'=>[['text'=>['html'=>'x']]]],
    ['type'=>'paragraph','children'=>['named'=>['text'=>'x']]],
    ['type'=>'paragraph','children'=>null],
    ['type'=>'bullet_list','items'=>[]],
    ['type'=>'ordered_list','items'=>['named'=>[['text'=>'x']]]],
    ['type'=>'image','src'=>'/gallery/a.jpg','alt'=>'x','onerror'=>'evil'],
] as $block){rbReject(fn()=>BlogRichDocument::validate(rbDocument([$block])),'invalid block/mark/shape');}

foreach(["\0","\x01","\x0b","\x7f","\xc0\xaf"] as $bad){
    rbReject(fn()=>BlogRichDocument::validate(rbDocument([rbParagraph($bad)])),'invalid UTF-8/control in rich text');
    rbReject(fn()=>BlogRichDocument::validate(rbDocument([['type'=>'image','src'=>'/gallery/a.jpg','alt'=>$bad]])),'invalid UTF-8/control in image alt');
}
rbCheck(BlogRichDocument::validate(rbDocument([rbParagraph(str_repeat('a',60000))]))['blocks'][0]['children'][0]['text']===str_repeat('a',60000),'exact 60000 text boundary accepted');
rbReject(fn()=>BlogRichDocument::validate(rbDocument([rbParagraph(str_repeat('a',60001))])),'text beyond 60000 rejected');
rbReject(fn()=>BlogRichDocument::validate(rbDocument([rbParagraph(str_repeat('a',30001)),rbParagraph(str_repeat('b',30000))])),'global text bound spans blocks');
rbCheck(count(BlogRichDocument::validate(rbDocument(array_fill(0,500,rbParagraph('x'))))['blocks'])===500,'exact block boundary accepted');
rbReject(fn()=>BlogRichDocument::validate(rbDocument(array_fill(0,501,rbParagraph('x')))),'excessive blocks rejected');
$runs=array_fill(0,2000,['text'=>'x']);
rbCheck(count(BlogRichDocument::validate(rbDocument([['type'=>'paragraph','children'=>$runs]]))['blocks'][0]['children'])===2000,'exact run boundary accepted');
rbReject(fn()=>BlogRichDocument::validate(rbDocument([['type'=>'paragraph','children'=>array_fill(0,2001,['text'=>'x'])]])),'excessive runs in one block');
rbReject(fn()=>BlogRichDocument::validate(rbDocument([['type'=>'paragraph','children'=>array_fill(0,1001,['text'=>'x'])],['type'=>'paragraph','children'=>array_fill(0,1000,['text'=>'x'])]])),'run bound spans blocks');
rbCheck(count(BlogRichDocument::validate(rbDocument([['type'=>'bullet_list','items'=>array_fill(0,100,[['text'=>'x']])]]))['blocks'][0]['items'])===100,'exact list item boundary');
rbReject(fn()=>BlogRichDocument::validate(rbDocument([['type'=>'ordered_list','items'=>array_fill(0,101,[['text'=>'x']])]])),'excessive list items rejected');
rbCheck(preg_match_all('/./us',BlogRichDocument::validate(rbDocument([rbParagraph(str_repeat('é',60000))]))['blocks'][0]['children'][0]['text'])===60000,'Unicode length counts characters');
rbCheck(BlogRichDocument::validate(rbDocument([rbParagraph(str_repeat('🎨',60000))]))['blocks'][0]['children'][0]['text']===str_repeat('🎨',60000),'exact four-byte Unicode text boundary fits rich envelope');
$oversizeRuns=array_fill(0,201,['text'=>'x','href'=>'https://example.com/'.str_repeat('a',1980)]);
rbReject(fn()=>BlogRichDocument::validate(rbDocument([['type'=>'paragraph','children'=>$oversizeRuns]])),'serialized rich size enforced including repeated link metadata');

foreach(['javascript:alert(1)','JaVaScRiPt:alert(1)','data:text/html,evil','//evil.example','/\\evil.example','https://user:pass@example.com','https://example.com/ bad',"/x\nalert",'javascript&#58;alert(1)','mailto:a@example.com','relative/path',str_repeat('a',2001),1,null] as $href){
    rbReject(fn()=>BlogRichDocument::validate(rbDocument([['type'=>'paragraph','children'=>[['text'=>'x','href'=>$href]]]])),'unsafe or invalid rich link');
}
foreach(['/','/products/example?x=1&y=2','http://example.com','https://example.com/a#b','/x%22%3E%3Cscript%3E'] as $href){
    $linkDoc=rbDocument([['type'=>'paragraph','children'=>[['text'=>'<link>','href'=>$href]]]]);
    rbCheck(strpos(BlogRichDocument::render($linkDoc),'&lt;link&gt;</a>')!==false,'safe link stays escaped and navigable');
}

$image=['type'=>'image','src'=>'/gallery/synthetic.jpg','alt'=>'Synthetic image'];
rbCheck(BlogRichDocument::validate(rbDocument([$image]))['blocks'][0]['caption']==='','missing caption gets canonical empty string');
rbCheck(count(BlogRichDocument::validate(rbDocument(array_fill(0,20,$image)))['blocks'])===20,'exact image boundary accepted');
rbReject(fn()=>BlogRichDocument::validate(rbDocument(array_fill(0,21,$image))),'excessive images rejected');
foreach(['/gallery/../secret.jpg','/gallery/x.svg','/gallery/x.jpg?x=1','//evil.example/x.jpg','https://evil.example/x.jpg','/gallery//x.jpg','/gallery/x.jpg" onerror="evil','/gallery/a%2ejpg','/gallery/a\\b.jpg'] as $src){rbReject(fn()=>BlogRichDocument::validate(rbDocument([array_replace($image,['src'=>$src])])),'unsafe image source');}
foreach([''," \t\n",str_repeat('a',161),null] as $alt){rbReject(fn()=>BlogRichDocument::validate(rbDocument([array_replace($image,['alt'=>$alt])])),'invalid alt');}
rbReject(fn()=>BlogRichDocument::validate(rbDocument([array_replace($image,['caption'=>str_repeat('a',321)])])),'caption boundary');
rbReject(fn()=>BlogRichDocument::validate(rbDocument([rbParagraph(str_repeat('x',59999)),$image])),'image alt contributes to global text bound');
rbCheck(BlogRichDocument::imageSources(rbDocument([$image,$image,array_replace($image,['src'=>'/gallery/other.webp'])]))===['/gallery/synthetic.jpg','/gallery/other.webp'],'image source enumeration deduplicates in order');
foreach([false,0,1,'true',null] as $result){rbCheck(BlogRichDocument::render(rbDocument([$image]),fn()=>$result)==='','image callback requires exact boolean true');}
$seen=[];$allowed=BlogRichDocument::render(rbDocument([$image]),function($src)use(&$seen){$seen[]=$src;return true;});
rbCheck($seen===['/gallery/synthetic.jpg'] && strpos($allowed,'<img ')!==false,'image guard receives exact validated source');
rbCheck(BlogContent::render(rbJson(rbDocument([$image])),'rich_v1',fn()=>false)==='','content dispatcher forwards image policy');
rbCheck(BlogRichDocument::hasContent(rbDocument([$image])),'accessible image counts as article content');
foreach([$empty,rbDocument([rbParagraph('')]),rbDocument([rbParagraph(" \t\n")])] as $doc){rbReject(fn()=>BlogContent::validate(rbInput($doc),true),'empty or whitespace-only article cannot publish');}
foreach(["\u{00a0}","\u{2003}","\u{200b}","\u{feff}"] as $invisible){
    rbReject(fn()=>BlogContent::validate(rbInput(rbDocument([rbParagraph($invisible)])),true),'Unicode blank-only article cannot publish');
    rbReject(fn()=>BlogRichDocument::validate(rbDocument([array_replace($image,['alt'=>$invisible])])),'Unicode blank-only image alt is not a description');
    foreach(['title','excerpt','author_name'] as $field){
        rbReject(fn()=>BlogContent::validate(array_replace(rbInput(rbDocument([rbParagraph()])),[$field=>$invisible]),true),'Unicode blank-only required metadata '.$field);
    }
    rbReject(fn()=>BlogContent::validate(array_replace(rbInput(rbDocument([rbParagraph()])),['cover_image'=>'/gallery/cover.jpg','cover_alt'=>$invisible]),true),'Unicode blank-only cover alt');
    rbReject(fn()=>BlogContent::validate(array_replace(rbInput(rbDocument([rbParagraph()])),['body_format'=>'markdown','body'=>$invisible]),true),'Unicode blank-only legacy Markdown cannot publish');
    foreach(['seo_title','seo_description'] as $optional){
        $optionalInput=array_replace(rbInput(rbDocument([rbParagraph()])),[$optional=>$invisible]);
        rbCheck(BlogContent::validate($optionalInput,true)[$optional]==='' && BlogContent::validate($optionalInput,false)[$optional]==='','Invisible-only optional SEO normalizes to empty for public fallback: '.$optional);
    }
}
foreach(['seo_title','seo_description'] as $optional){rbCheck(BlogContent::validate(array_replace(rbInput(rbDocument([rbParagraph()])),[$optional=>"Visible\u{200b} text"]),true)[$optional]==="Visible\u{200b} text",'Visible optional SEO preserves intentional inner formatting: '.$optional);}
rbCheck(BlogContent::validate(rbInput(rbDocument([rbParagraph("Words\u{00a0}stay spaced")])),true)['body']===rbJson(rbDocument([rbParagraph("Words\u{00a0}stay spaced")])),'intentional Unicode spacing within visible content is preserved');
rbCheck(BlogContent::validate(rbInput($empty),false)['body']===rbJson($empty),'empty rich draft retains canonical document');

// Existing Markdown display must survive conversion without activating HTML or punctuation.
foreach([
    '',"Plain **literal bold** and _literal italic_ & <img src=x onerror=bad>",
    "## Heading\n\nText [link](https://example.com/?a=1&b=2) and [bad](javascript:alert)",
    "- First\n- Second\n\n### End\nMore\nlines",
    "### H\n- [one](/one)\n- literal [broken](https://example.com/a(b))\ntext",
    "Unicode café 🎨 [label é](/gallery/a.jpg)",
] as $markdown){
    $converted=BlogRichDocument::fromMarkdown($markdown);
    $legacy=BlogContent::render($markdown);$rich=BlogRichDocument::render($converted);
    rbCheck(preg_replace('/>\s+</','><',trim($legacy))===preg_replace('/>\s+</','><',trim($rich)),'Markdown conversion preserves supported output');
}
$longList=BlogRichDocument::fromMarkdown(implode("\n",array_fill(0,101,'- Keep every item')));
rbCheck(substr_count(BlogRichDocument::render($longList),'<li>Keep every item</li>')===101,'legacy long list conversion preserves every item across bounded blocks');
rbCheck(BlogRichDocument::fromMarkdown(str_repeat('🎨',60000))['blocks'][0]['children'][0]['text']===str_repeat('🎨',60000),'maximum Unicode legacy paragraph converts without loss');
rbReject(fn()=>BlogRichDocument::fromMarkdown(implode("\n\n",array_fill(0,501,'Paragraph'))),'legacy over-limit block conversion fails explicitly rather than truncating');

// Storage projection and snapshot safety with mixed legacy/rich posts.
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA foreign_keys=ON');
BlogRepository::install($db);$repo=new BlogRepository($db,fn()=>true);
$input=rbInput($all);$post=$repo->createDraft($input,7,str_repeat('r',32));
rbCheck($post['draft']['body_format']==='rich_v1' && $post['draft']['body']===rbJson($canonical),'storage carries canonical rich format');
$equivalent=$input;$equivalent['body']=json_encode($all,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
rbCheck($repo->createDraft($equivalent,7,str_repeat('r',32))['id']===$post['id'],'idempotent create ignores JSON whitespace and escaped Unicode');
$post=$repo->save($post['id'],1,'publish',$input,7);$public=$repo->findPublished('rich-boundary');
rbCheck($public['content']['body_format']==='rich_v1' && BlogContent::render($public['content']['body'],$public['content']['body_format'])===$html,'published rich projection round-trips renderer');
$changed=rbInput(rbDocument([rbParagraph('PRIVATE RICH DRAFT')]));$repo->save($post['id'],2,'draft',$changed,8);
rbCheck($repo->findPublished('rich-boundary')===$public && strpos(json_encode($repo->listPublished()),'PRIVATE')===false,'rich draft never changes public snapshot');
rbCheck(!isset($public['draft'],$public['revision'],$public['history'],$public['request_key_sha256']),'rich projection excludes private fields');
$legacy=['title'=>'Legacy','slug'=>'legacy-boundary','excerpt'=>'Fixture','author_name'=>'Fixture','body'=>'## Legacy\nBody','reviewed'=>true];
$old=$repo->createDraft($legacy,7,str_repeat('l',32));$repo->save($old['id'],1,'publish',$legacy,7);
$row=$db->query('SELECT draft_json,published_json FROM blog_posts WHERE id='.$old['id'])->fetch(PDO::FETCH_ASSOC);
foreach(['draft_json','published_json'] as $field){$oldData=json_decode($row[$field],true,16,JSON_THROW_ON_ERROR);unset($oldData['body_format']);$db->prepare('UPDATE blog_posts SET '.$field.'=? WHERE id=?')->execute([json_encode($oldData,JSON_THROW_ON_ERROR),$old['id']]);}
rbCheck($repo->findPublished('legacy-boundary')['content']['body_format']==='markdown','pre-upgrade stored JSON defaults to legacy Markdown');
rbCheck($repo->listPublished()['total']===2 && count($repo->publishedSitemap())===2,'mixed format public list and sitemap work');
$corrupt=json_decode($db->query('SELECT published_json FROM blog_posts WHERE id='.$post['id'])->fetchColumn(),true,16,JSON_THROW_ON_ERROR);$corrupt['body']='{"version":1,"blocks":[{"type":"html","html":"evil"}]}';
$db->prepare('UPDATE blog_posts SET published_json=? WHERE id=?')->execute([json_encode($corrupt,JSON_THROW_ON_ERROR),$post['id']]);
rbReject(fn()=>$repo->findPublished('rich-boundary'),'corrupt rich snapshot fails closed');
rbCheck($repo->save($post['id'],3,'withdraw',[],7)['published']===null,'corrupt rich publication remains withdrawable');

echo "Blog rich boundary: {$richBoundaryChecks} assertions passed.\n";
