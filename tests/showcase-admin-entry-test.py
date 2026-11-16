"""Native HTTP + cookie-session + exact current AdminAuth class, synthetic dependencies.
No TLS/browser/real accounts or production bootstrap. All files are isolated in tempdir.
"""
from pathlib import Path
from html.parser import HTMLParser
import tempfile,subprocess,os,socket,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,json,secrets,re,hashlib
root=Path(__file__).resolve().parents[1];key=secrets.token_hex(32);checks=0
php='php'
def check(value,label):
 global checks
 assert value,label;checks+=1
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
 check(''.join(parsed.titles)=='Review customer photos | Flip & Strip',label+' title')
 check(any(tag=='meta' and attrs.get('charset')=='utf-8' for tag,attrs in parsed.tags),label+' charset')
 check(any(tag=='meta' and attrs.get('name')=='viewport' for tag,attrs in parsed.tags),label+' viewport')
 check(not any(tag in ['iframe','link','base'] for tag,attrs in parsed.tags),label+' no new resources')
 check(all(attrs=={'data-showcase-history-guard':None} for tag,attrs in parsed.tags if tag=='script'),label+' only fixed history guard')
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args):return None
with tempfile.TemporaryDirectory(prefix='showcase-admin-native-') as d:
 t=Path(d)
 for folder in ['admin','includes','src/utils','sessions']:(t/folder).mkdir(parents=True,exist_ok=True)
 auth=(root/'tests/fixtures/admin-auth-main.php').read_bytes()
 assert hashlib.sha1(b'blob '+str(len(auth)).encode()+b'\0'+auth).hexdigest()=='f387c7c6657d247e0709951b17f5da45f160aa74'
 (t/'admin/auth.php').write_bytes(auth)
 (t/'includes/security.php').write_text('<?php // no live security bootstrap')
 (t/'src/utils/Timezone.php').write_text('<?php namespace FAS\\Utils; class Timezone {static function apply(){}}')
 (t/'includes/admin-accounts.php').write_text('''<?php
 function fas_admin_account($db,$id){$a=json_decode(file_get_contents(__DIR__.'/../account.json'),true);return $id===7?$a:null;}
 function fas_admin_session_token($admin){return $admin['fixture_token'];}
 ''')
 account={'is_active':1,'role':'admin','fixture_token':'synthetic-v1','username':'Fixture','email':'fixture@example.invalid'}
 def save_account(a):(t/'account.json').write_text(json.dumps(a))
 save_account(account)
 setup=''.join('require '+json.dumps(str(root/f'src/showcase/Showcase{name}.php'))+';' for name in ['Photo','Submission','Repository'])
 setup+='''$d=new PDO('sqlite:'.$argv[1].'/store.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec('PRAGMA foreign_keys=ON');FAS\\Showcase\\ShowcaseRepository::initializeSchema($d);$r=new FAS\\Showcase\\ShowcaseRepository($d,fn()=>true,50,10000000);$im=imagecreatetruecolor(5,3);ob_start();imagepng($im);$p=FAS\\Showcase\\ShowcasePhoto::fromBytes(ob_get_clean());$s=FAS\\Showcase\\ShowcaseSubmission::prepare(['display_name'=>'<script>Fixture</script>','email'=>'private@example.invalid','caption'=>'Synthetic caption','consent'=>true],$p,'test-v1','Synthetic consent.');echo json_encode($r->accept($s,'Synthetic consent.',str_repeat('r',64)));'''
 receipt=json.loads(subprocess.run([php,'-r',setup,d],check=True,capture_output=True,text=True).stdout);id=receipt['id']
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 router=t/'entry-router.php';router.write_text(r'''<?php
 $key=getenv('TEST_KEY');if(PHP_SAPI!=='cli-server'||!preg_match('/\A[a-f0-9]{64}\z/D',(string)$key)||!hash_equals($key,$_SERVER['HTTP_X_FIXTURE']??'')){http_response_code(404);exit;}
 $mode=$_SERVER['HTTP_X_MODE']??'off';if($mode!=='plain'&&$mode!=='off')$_SERVER['HTTPS']='on';
 putenv('FAS_SHOWCASE_ADMIN_BOOTSTRAP='.($mode==='off'?'':($mode==='missing'?'/missing/private.php':($mode==='symlink'?getenv('TEST_DATA').'/linked.php':getenv('TEST_DATA').'/entry-bootstrap.php'))));
 $endpoint=basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'.php');
 if(!in_array($endpoint,['showcase-queue','showcase-detail','showcase-photo','showcase-moderate'],true)){http_response_code(404);exit;}
 require getenv('TEST_SOURCE').'/admin/'.$endpoint.'.php';
 ''')
 boot=t/'entry-bootstrap.php';boot.write_text(r'''<?php
 $mode=$_SERVER['HTTP_X_MODE']??'';
 echo 'hidden-bootstrap-output';header('Location: /must-not-leak');
 if($mode==='null')return null;
 if($mode==='throw')throw new RuntimeException('private-secret');
 if($mode==='invalid')return ['controller'=>new stdClass(),'csrf'=>str_repeat('x',64)];
 session_start(['use_strict_mode'=>1,'use_only_cookies'=>1,'cookie_secure'=>$mode==='unsafe'?0:1,'cookie_httponly'=>1,'cookie_samesite'=>'Lax']);
 require __DIR__.'/admin/auth.php';
 if($mode!=='unauth')$_SESSION=['admin_id'=>7,'admin_logged_in'=>true,'admin_session_token'=>'synthetic-v1'];
 $auth=(new ReflectionClass(AdminAuth::class))->newInstanceWithoutConstructor();
 $authorization=new FAS\Showcase\ShowcaseAdminAuthorization($auth);
 $db=new PDO('sqlite:'.__DIR__.'/store.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec('PRAGMA foreign_keys=ON');
 $repo=new FAS\Showcase\ShowcaseRepository($db,$authorization,50,10000000);
 $request=new FAS\Showcase\ShowcaseRequest($repo,'test-v1','Synthetic consent.',fn()=>true);
 $paths=[];foreach(['queue','detail','photo','moderate'] as $e)$paths[$e]='/admin/showcase-'.$e.'.php';
 $controller=new FAS\Showcase\ShowcaseAdminController($repo,$request,[$authorization,'actor'],fn()=>true,$paths);
 return ['controller'=>$controller,'csrf'=>$mode==='badcsrf'?'':str_repeat('x',64)];
 ''');(t/'linked.php').symlink_to(boot)
 proc=subprocess.Popen([php,'-d','session.save_path='+str(t/'sessions'),'-S',f'127.0.0.1:{port}',str(router)],env=dict(os.environ,TEST_KEY=key,TEST_DATA=d,TEST_SOURCE=str(root)),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def call(mode,path='queue',fields=None,extra=None):
  headers={'X-Fixture':key,'X-Mode':mode};headers.update(extra or {})
  req=urllib.request.Request(f'http://127.0.0.1:{port}/admin/showcase-'+path+'.php'+('?id='+id if path in ['detail','photo'] else ''),headers=headers,data=None if fields is None else urllib.parse.urlencode(fields).encode())
  try:response=urllib.request.build_opener(NoRedirect()).open(req,timeout=5)
  except urllib.error.HTTPError as e:response=e
  with response:return response.status,response.read(),response.headers
 try:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:time.sleep(.03)
  for mode,status in [('off',404),('plain',403),('missing',503),('symlink',503),('null',404),('throw',503),('invalid',503),('unsafe',503),('badcsrf',503),('unauth',403)]:
   result=call(mode);document(result[1],mode);check(result[0]==status,mode+' status');check(b'hidden-bootstrap-output' not in result[1] and b'private-secret' not in result[1],mode+' buffered');check(result[2].get('Location') is None,mode+' no redirect');check(result[2]['Cache-Control']=='private, no-store',mode+' no cache')
   if mode in ['off','plain','missing','symlink','null','throw','invalid']:check(result[2].get('Set-Cookie') is None,mode+' no session')
  check(call('plain',extra={'X-Forwarded-Proto':'https'})[0]==403,'forwarded header cannot enable TLS')
  queue=call('tls');document(queue[1],'queue');check(queue[0]==200 and b'Review customer photos' in queue[1],'queue wrapper');check(all(v.lower() in queue[2]['Set-Cookie'].lower() for v in ['secure','httponly','samesite=lax']),'secure native session flags')
  detail=call('tls','detail');document(detail[1],'detail');check(detail[0]==200 and b'private@example.invalid' in detail[1],'detail wrapper');check(b'/admin/showcase-moderate.php' in detail[1],'fixed form action')
  photo=call('tls','photo');check(photo[0]==200 and photo[1].startswith(b'\xff\xd8'),'photo wrapper');check(photo[2]['Content-Type']=='image/jpeg','photo MIME');check(not photo[1].endswith(b'</html>'),'photo not wrapped');check(int(photo[2]['Content-Length'])==len(photo[1]),'photo length')
  values=dict(re.findall(r'name="([a-z0-9_]+)" value="([^"]*)"',detail[1].decode()));values.update(decision='approved',reason='Entry test')
  rejected=call('tls','moderate');check(rejected[0]==405,'GET moderate rejected');document(rejected[1],'method error')
  approved=call('tls','moderate',values);check(approved[0]==303,'moderation wrapper');check(approved[2]['Location']=='/admin/showcase-detail.php?id='+id,'controlled PRG');check(approved[1]==b'','redirect body remains empty');stale=call('tls','moderate',values);check(stale[0]==409,'stale decision');document(stale[1],'conflict')
  print(f'Admin entry: {checks} assertions passed (HTTPS state simulated, not TLS/browser acceptance).')
 finally:proc.terminate();proc.wait(timeout=5)
