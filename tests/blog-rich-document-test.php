<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../src/blog/BlogRepository.php';
use FAS\Blog\{BlogContent,BlogRichDocument,BlogRepository};
$checks=0;
function richCheck(bool $ok,string $message):void{global$checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function richReject(callable$operation,string$message):void{try{$operation();}catch(InvalidArgumentException$e){richCheck(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
function richDoc(array$blocks):array{return['version'=>1,'blocks'=>$blocks];}
function richP(array$runs):array{return['type'=>'paragraph','children'=>$runs];}
function richJson(array$doc):string{return json_encode($doc,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
$literal='## literal - [not a link](/path) **not bold** _underscore_ \\ <script>alert(1)</script> & "quotes"';
$document=richDoc([
 ['type'=>'heading','level'=>2,'children'=>[['text'=>'Section','bold'=>true]]],
 richP([['text'=>$literal],['text'=>'Marked','bold'=>true,'italic'=>true,'underline'=>true,'href'=>'https://example.invalid/?a=1&b=2']]),
 ['type'=>'heading','level'=>3,'children'=>[['text'=>'Details']]],
 ['type'=>'bullet_list','items'=>[[['text'=>'One']],[['text'=>'Two','italic'=>true]]]],
 ['type'=>'ordered_list','items'=>[[['text'=>'First']],[['text'=>'Second']]]],
 ['type'=>'blockquote','children'=>[['text'=>"Quoted\nline"]]],
 ['type'=>'image','src'=>'/gallery/example.jpg','alt'=>'A synthetic <part>','caption'=>'Synthetic image caption'],
]);
$canonical=BlogRichDocument::validate($document);
richCheck($canonical===$document,'Canonical structured document stable');
richCheck(BlogRichDocument::validate(richJson($canonical))===$canonical,'JSON roundtrip exact');
richCheck(BlogRichDocument::hasContent($canonical),'Document has visible content');
$html=BlogRichDocument::render($canonical);
foreach(['<h2><strong>Section</strong></h2>','<h3>Details</h3>','<ol>','<ul>','<blockquote><p>Quoted<br>','<u><em><strong>Marked</strong></em></u>','<figcaption>Synthetic image caption</figcaption>','alt="A synthetic &lt;part&gt;"','href="https://example.invalid/?a=1&amp;b=2"']as$part){richCheck(strpos($html,$part)!==false,'Expected safe structure '.$part);}
richCheck(strpos($html,'<script>')===false&&strpos($html,'**not bold**')!==false&&strpos($html,'[not a link](/path)')!==false,'Literal punctuation and HTML never become formatting');
richCheck(BlogRichDocument::render(richDoc([richP([['text'=>"One\r\nTwo\nThree"]])]))==="<p>One<br>Two<br>Three</p>\n",'Line break has one DOM representation for editor roundtrip');
richCheck(BlogContent::render(richJson($document),'rich_v1')===$html,'Shared renderer exact');
richCheck(BlogRichDocument::imageSources($document)===['/gallery/example.jpg'],'Image inventory safe/complete');
$seen=[];$filtered=BlogContent::render(richJson($document),'rich_v1',static function(string$path)use(&$seen):bool{$seen[]=$path;return false;});
richCheck($seen===['/gallery/example.jpg']&&strpos($filtered,'<figure>')===false&&strpos($filtered,'<h2>')!==false,'Missing-image predicate omits whole figure only');
richCheck(strpos(BlogContent::render(richJson($document),'rich_v1',static function(){return 1;}),'<img')===false,'Image predicate requires true');
richCheck(BlogRichDocument::validate(richDoc([richP([['text'=>'x','bold'=>false,'href'=>''],['text'=>'']])]))===richDoc([richP([['text'=>'x']])]),'False/empty formatting canonicalized');
$input=['title'=>'Rich fixture','slug'=>'rich-fixture','excerpt'=>'Synthetic editor roundtrip','author_name'=>'Fixture editor','body_format'=>'rich_v1','body'=>richJson($document)];
$validated=BlogContent::validate($input,true);
richCheck($validated['body_format']==='rich_v1'&&$validated['body']===richJson($canonical),'Storage retains canonical rich format');
richCheck(BlogContent::validate(['body_format'=>'rich_v1','body'=>''])['body']===richJson(richDoc([])),'Empty draft normalized');
richReject(static function()use($input):void{BlogContent::validate(array_replace($input,['body'=>richJson(richDoc([]))]),true);},'Empty rich publication rejected');
richCheck(!BlogRichDocument::hasContent(BlogRichDocument::validate(richDoc([richP([['text'=>'  ']])]))),'Whitespace-only document empty');
foreach([null,true,1,[],['version'=>1],['version'=>1,'blocks'=>'x'],['version'=>1.0,'blocks'=>[]],['version'=>2,'blocks'=>[]],['version'=>1,'blocks'=>[],'html'=>'<b>x</b>'],'{broken',str_repeat('x',400001)]as$bad){richReject(static function()use($bad):void{BlogRichDocument::validate($bad);},'Malformed root rejected');}
foreach([
 ['type'=>'script','children'=>[['text'=>'bad']]],['type'=>'paragraph','children'=>[],'onclick'=>'evil'],['type'=>'heading','level'=>1,'children'=>[]],['type'=>'heading','level'=>'2','children'=>[]],
 ['type'=>'paragraph','children'=>null],['type'=>'paragraph','children'=>['key'=>['text'=>'x']]],['type'=>'bullet_list','items'=>[]],['type'=>'ordered_list','items'=>array_fill(0,101,[])],
 ['type'=>'image','src'=>'https://example.invalid/x.jpg','alt'=>'x'],['type'=>'image','src'=>'/gallery/../x.jpg','alt'=>'x'],['type'=>'image','src'=>'/gallery/a.svg','alt'=>'x'],
 ['type'=>'image','src'=>'/gallery/a.jpg','alt'=>''],['type'=>'image','src'=>'/gallery/a.jpg','alt'=>'x','onload'=>'evil'],
]as$bad){richReject(static function()use($bad):void{BlogRichDocument::validate(richDoc([$bad]));},'Malformed block rejected');}
foreach([
 ['text'=>null],['text'=>[]],['text'=>"bad\0text"],['text'=>"\xff"],['text'=>'x','bold'=>'true'],['text'=>'x','italic'=>1],['text'=>'x','underline'=>null],['text'=>'x','href'=>null],
 ['text'=>'x','style'=>'color:red'],['text'=>'x','href'=>'javascript:alert(1)'],['text'=>'x','href'=>'data:text/html,hi'],['text'=>'x','href'=>'//evil.invalid'],['text'=>'x','href'=>'/\\evil.invalid'],
 ['text'=>'x','href'=>'https://user:pass@example.invalid'],['text'=>'x','href'=>str_repeat('a',2001)],
]as$run){richReject(static function()use($run):void{BlogRichDocument::validate(richDoc([richP([$run])]));},'Invalid run rejected');}
richCheck(BlogRichDocument::validate(richDoc([richP([['text'=>str_repeat('�',60000)]])]))['blocks'][0]['children'][0]['text']===str_repeat('�',60000),'Visible Unicode text limit inclusive');
richCheck(BlogRichDocument::fromMarkdown(str_repeat('??',60000))['blocks'][0]['children'][0]['text']===str_repeat('??',60000),'Four-byte legacy text retains complete content');
$longList=BlogRichDocument::fromMarkdown(implode("\n",array_fill(0,101,'- item')));richCheck(count($longList['blocks'])===2&&count($longList['blocks'][0]['items'])===100&&count($longList['blocks'][1]['items'])===1,'Long legacy bullet list splits without losing items');
richReject(static function():void{BlogRichDocument::validate(richDoc([richP([['text'=>str_repeat('x',30001)],['text'=>str_repeat('x',30000)]])]));},'Aggregate text limit');
richReject(static function():void{BlogRichDocument::validate(richDoc(array_fill(0,501,richP([]))));},'Block limit');
richReject(static function():void{BlogRichDocument::validate(richDoc([richP(array_fill(0,2001,['text'=>'x']))]));},'Formatting segment limit');
richReject(static function():void{BlogRichDocument::validate(richDoc(array_fill(0,21,['type'=>'image','src'=>'/gallery/a.jpg','alt'=>'x'])));},'Image limit');
foreach(["## Heading\n\nParagraph [link](/product/1/example).\n\n- One\n- Two",$literal,"# Literal heading\n- one\n\n### Another\n\n<img src=x>"]as$markdown){
 $converted=BlogRichDocument::fromMarkdown($markdown);$old=BlogContent::render($markdown);$new=BlogRichDocument::render($converted);
 richCheck(preg_replace('/>\s+</','><',trim($old))===preg_replace('/>\s+</','><',trim($new)),'Legacy display preserved when entering visual editor');
}
// Formatting and punctuation survive real draft/save/publication/withdrawal lifecycle.
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');BlogRepository::install($db);
$repo=new BlogRepository($db,static function(int$id):bool{return$id===7;});$post=$repo->createDraft($input,7,str_repeat('r',40));
richCheck($post['draft']['body']===$validated['body']&&$repo->listPublished()['total']===0,'Rich draft persists privately');
$published=$repo->save($post['id'],1,'publish',$input+['reviewed'=>true],7);$public=$repo->findPublished('rich-fixture');
richCheck(BlogContent::render($public['content']['body'],$public['content']['body_format'])===$html,'Public rich output equals reviewed preview');
$changed=$input;$changed['body']=richJson(richDoc([richP([['text'=>'A PRIVATE draft change']])]));$repo->save($post['id'],2,'draft',$changed,7);
richCheck($repo->findPublished('rich-fixture')===$public,'Rich draft edit never changes snapshot');
$repo->save($post['id'],3,'withdraw',[],7);richCheck($repo->findPublished('rich-fixture')===null,'Rich withdrawal hides article');
// Random literal formatting punctuation cannot acquire executable structure.
mt_srand(1203);$alphabet=['<','>','&','"',"'",'[',']','(',')','#','-','*','_','\\','�','x'];
for($i=0;$i<100;$i++){$text='';for($j=0;$j<40;$j++)$text.=$alphabet[mt_rand(0,count($alphabet)-1)];$rendered=BlogRichDocument::render(richDoc([richP([['text'=>$text]])]));richCheck($rendered==='<p>'.htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</p>\n",'Literal fuzz stays escaped');}
echo "Blog rich document: {$checks} assertions passed.\n";
