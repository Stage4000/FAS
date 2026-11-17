"""Isolated HTTP transport checks. HTTPS server state simulated, not actual TLS/browser."""
from pathlib import Path
from html.parser import HTMLParser
import tempfile, subprocess, os, socket, time, urllib.request, urllib.error, json, secrets
root=Path(__file__).resolve().parents[1]; key=secrets.token_hex(32); n=0
def check(ok,label):
 global n
 assert ok,label
 n+=1
def document(body,label):
 class Tags(HTMLParser):
  def __init__(self): super().__init__(); self.tags=[]; self.declarations=[]; self.titles=[]; self.in_title=False
  def handle_decl(self,value): self.declarations.append(value.lower())
  def handle_starttag(self,tag,attrs):
   self.tags.append((tag,dict(attrs)))
   if tag=='title': self.in_title=True
  def handle_endtag(self,tag):
   if tag=='title': self.in_title=False
  def handle_data(self,data):
   if self.in_title:self.titles.append(data)
 parsed=Tags(); parsed.feed(body.decode('utf-8'))
 check(parsed.declarations==['doctype html'],label+' doctype')
 check([attrs.get('lang') for tag,attrs in parsed.tags if tag=='html']==['en'],label+' language')
 check(sum(tag=='main' for tag,attrs in parsed.tags)==1,label+' main')
 check(''.join(parsed.titles)=='Customer rides and projects | Flip & Strip',label+' title')
 check(any(tag=='meta' and attrs.get('charset')=='utf-8' for tag,attrs in parsed.tags),label+' charset')
 check(any(tag=='meta' and attrs.get('name')=='viewport' for tag,attrs in parsed.tags),label+' viewport')
 check(not any(tag in ['script','iframe','link','base'] for tag,attrs in parsed.tags),label+' no new resources')
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args): return None
with tempfile.TemporaryDirectory(prefix='showcase-public-entry-') as temp:
 t=Path(temp); (t/'sessions').mkdir()
 setup=''.join('require '+json.dumps(str(root/f'src/showcase/Showcase{x}.php'))+';' for x in ['Photo','Submission','Repository'])
 setup+=r'''$d=new PDO('sqlite:'.$argv[1].'/db.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec('PRAGMA foreign_keys=ON');FAS\Showcase\ShowcaseRepository::initializeSchema($d);$r=new FAS\Showcase\ShowcaseRepository($d,fn()=>true,50,10000000);$im=imagecreatetruecolor(5,3);ob_start();imagepng($im);$p=FAS\Showcase\ShowcasePhoto::fromBytes(ob_get_clean());$s=FAS\Showcase\ShowcaseSubmission::prepare(['display_name'=>'<b>Ride</b>','email'=>'secret@example.invalid','caption'=>'Test','consent'=>true],$p,'test-v1','Synthetic consent');$x=$r->accept($s,'Synthetic consent',str_repeat('x',64));$row=$r->readForModeration($x['id'],1);$r->moderate($row['id'],1,$row['version'],$row['photo_sha256'],$row['consent_text_sha256'],'approved','Test');echo $row['id'];'''
 id=subprocess.run(['php','-r',setup,temp],check=True,capture_output=True,text=True).stdout
 boot=t/'bootstrap.php';boot.write_text(r'''<?php
 $mode=$_SERVER['HTTP_X_MODE']??'';
 echo 'secret-output';header('Location: /secret');header('X-Secret: private');setcookie('private','hidden');
 if($mode==='null')return null;
 if($mode==='throw')throw new RuntimeException('secret-error');
 if($mode==='invalid')return new stdClass();
 if($mode==='session')session_start();
 $db=new PDO('sqlite:'.__DIR__.'/db.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');
 $repo=new FAS\Showcase\ShowcaseRepository($db,fn()=>false,50,10000000);
 return new FAS\Showcase\ShowcasePublicController($repo,fn()=>$mode!=='closed','test-v1',hash('sha256','Synthetic consent'),'/showcase-photo.php');
 '''); (t/'link.php').symlink_to(boot)
 router=t/'router.php';router.write_text(r'''<?php
 $key=getenv('TEST_KEY');if(PHP_SAPI!=='cli-server'||!preg_match('/\A[a-f0-9]{64}\z/D',(string)$key)||!hash_equals($key,$_SERVER['HTTP_X_FIXTURE']??'')){http_response_code(404);exit;}
 $mode=$_SERVER['HTTP_X_MODE']??'off'; if(!in_array($mode,['off','plain'],true))$_SERVER['HTTPS']='on';
 putenv('FAS_SHOWCASE_PUBLIC_BOOTSTRAP='.($mode==='off'?'':getenv('TEST_DATA').'/'.($mode==='missing'?'missing.php':($mode==='link'?'link.php':'bootstrap.php'))));
 $name=basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));if(!in_array($name,['showcase-gallery.php','showcase-photo.php'],true)){http_response_code(404);exit;}
 require getenv('TEST_SOURCE').'/'.$name;
 ''')
 with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
 proc=subprocess.Popen(['php','-d','session.save_path='+str(t/'sessions'),'-S',f'127.0.0.1:{port}',str(router)],env=dict(os.environ,TEST_KEY=key,TEST_DATA=temp,TEST_SOURCE=str(root)),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def call(mode,endpoint='gallery',query='',method='GET',extra=None):
  h={'X-Fixture':key,'X-Mode':mode};h.update(extra or {})
  req=urllib.request.Request(f'http://127.0.0.1:{port}/showcase-{endpoint}.php'+query,headers=h,method=method)
  try: r=urllib.request.build_opener(NoRedirect()).open(req,timeout=5)
  except urllib.error.HTTPError as e:r=e
  with r:return r.status,r.read(),r.headers
 try:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:time.sleep(.03)
  for mode,expected in [('off',404),('plain',403),('missing',503),('link',503),('null',404),('throw',503),('invalid',503),('session',503),('closed',404),('tls',200)]:
   status,body,h=call(mode);check(status==expected,mode+' status');document(body,mode)
   check(b'secret' not in body,mode+' no secrets')
   for header in ['Set-Cookie','Location','X-Secret']:check(h.get(header) is None,mode+' no '+header)
   check(h['Cache-Control']=='private, no-store',mode+' no store')
  s,b,h=call('tls');check(b'&lt;b&gt;Ride&lt;/b&gt;' in b,'escaped gallery');check(id.encode() in b,'approved linked')
  s,b,h=call('tls','photo','?id='+id);check(s==200 and b.startswith(b'\xff\xd8'),'exact JPEG route');check(h['Content-Type']=='image/jpeg','JPEG type');check(int(h['Content-Length'])==len(b),'JPEG length')
  check(call('tls','photo','?id=bad')[0]==404,'bad ID');check(b'<!doctype' not in b and not b.endswith(b'</html>'),'JPEG has no HTML wrapper');check(call('tls',query='?limit=99')[0]==404,'bounded gallery')
  check(call('tls',method='POST')[0]==405,'no writes');check(call('tls',method='HEAD')[0]==405,'no HEAD')
  check(call('plain',extra={'X-Forwarded-Proto':'https'})[0]==403,'no forwarded TLS spoof')
  print(f'Public entry: {n} assertions passed (HTTPS simulated, not TLS/browser).')
 finally:proc.terminate();proc.wait(timeout=5)
