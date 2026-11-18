"""Native HTTP + cookie-session + exact current AdminAuth class, synthetic dependencies.
No TLS/browser/real accounts or production bootstrap. All files are isolated in tempdir.
"""
from pathlib import Path
import tempfile,subprocess,os,socket,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,json,secrets,re,hashlib
root=Path(__file__).resolve().parents[1];key=secrets.token_hex(32);checks=0
php='php'
def check(value,label):
 global checks
 assert value,label;checks+=1
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
 jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),NoRedirect())
 proc=subprocess.Popen([php,'-d','session.save_path='+str(t/'sessions'),'-S',f'127.0.0.1:{port}',str(root/'tests/showcase-admin-http-router.php')],env=dict(os.environ,TEST_KEY=key,TEST_DATA=d),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def call(path,fields=None,extra=None,client=opener):
  headers={'X-Fixture':key};headers.update(extra or {})
  data=None if fields is None else urllib.parse.urlencode(fields).encode()
  req=urllib.request.Request(f'http://127.0.0.1:{port}'+path,headers=headers,data=data)
  try:response=client.open(req,timeout=5)
  except urllib.error.HTTPError as e:response=e
  with response:return response.status,response.read(),response.headers
 try:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:time.sleep(.03)
  for path in ['/admin/queue.php','/admin/detail.php?id='+id,'/admin/photo.php?id='+id]:check(call(path)[0]==403,'unauthenticated rejected')
  check(call('/fixture-login')[0]==200,'native login fixture')
  queue=call('/admin/queue.php');check(queue[0]==200,'queue available');check(b'private@example.invalid' not in queue[1],'no contact in queue');check(b'&lt;script&gt;' in queue[1],'escaped name')
  detail=call('/admin/detail.php?id='+id);check(detail[0]==200,'detail available');check(b'private@example.invalid' in detail[1],'private email in authenticated detail only')
  values=dict(re.findall(r'name="([a-z0-9_]+)" value="([^"]*)"',detail[1].decode()));values.update(decision='approved',reason='Synthetic review')
  photo=call('/admin/photo.php?id='+id);check(photo[0]==200 and photo[1].startswith(b'\xff\xd8'),'private verified JPEG');check(photo[2]['Content-Type']=='image/jpeg','JPEG content type')
  for response in [queue,detail,photo]:check(response[2]['Cache-Control']=='private, no-store','private no-store');check(response[2]['X-Content-Type-Options']=='nosniff','nosniff')
  check(call('/admin/moderate.php')[0]==405,'GET cannot decide')
  bad=dict(values,csrf_token='x'*64);check(call('/admin/moderate.php',bad)[0]==400,'wrong CSRF denied')
  other=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect());call('/fixture-login',client=other)
  check(call('/admin/moderate.php',values,client=other)[0]==400,'cross-session CSRF denied')
  check(call('/admin/detail.php?id='+id)[1]==detail[1],'rejected requests made no decision')
  approved=call('/admin/moderate.php',values);check(approved[0]==303,'POST redirects');check(approved[2]['Location']=='/admin/detail.php?id='+id,'fixed same-origin redirect');check(approved[1]==b'','no private body on redirect')
  check(call('/admin/moderate.php',values)[0]==409,'duplicate decision stale')
  after=call('/admin/detail.php?id='+id);check(b'value="2"' in after[1] and b'value="approved"' not in after[1],'updated decision snapshot')
  for changed in [dict(account,is_active=0),dict(account,role='staff'),dict(account,fixture_token='rotated-v2'),None]:
   save_account(changed)
   for path in ['/admin/queue.php','/admin/detail.php?id='+id,'/admin/photo.php?id='+id]:
    denied=call(path);check(denied[0]==403 and b'private@example.invalid' not in denied[1],'live auth revocation')
  save_account(account)
  check(call('/admin/queue.php',extra={'X-Disabled':'1'})[0]==404,'disabled route hidden')
  withdraw=dict(re.findall(r'name="([a-z0-9_]+)" value="([^"]*)"',after[1].decode()));withdraw.update(decision='withdrawn',reason='Synthetic withdrawal')
  check(call('/admin/moderate.php',withdraw)[0]==303,'withdrawal works')
  check(b'<form' not in call('/admin/detail.php?id='+id)[1],'withdrawn cannot approve again')
  check(call('/fixture-logout')[0]==200,'native logout');check(call('/admin/photo.php?id='+id)[0]==403,'photo rejected after logout')
 finally:proc.terminate();proc.wait(timeout=5)

 # Fixture hardening: an unset/empty expected key must not admit an empty header.
 proc=subprocess.Popen([php,'-S',f'127.0.0.1:{port}',str(root/'tests/showcase-admin-http-router.php')],env=dict(os.environ,TEST_KEY='',TEST_DATA=d),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:time.sleep(.03)
  check(call('/fixture-login',extra={'X-Fixture':''})[0]==404,'empty fixture key denies empty header')
 finally:proc.terminate();proc.wait(timeout=5)
 print(f'Admin native HTTP: {checks} assertions passed (synthetic accounts, plain loopback; not TLS/browser).')
