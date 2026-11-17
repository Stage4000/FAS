from pathlib import Path
import tempfile,subprocess,os,socket,time,urllib.request,urllib.error,json,secrets
root=Path(__file__).resolve().parents[1];php='php';key=secrets.token_hex(32);checks=0
def check(v):
 global checks
 assert v;checks+=1
with tempfile.TemporaryDirectory(prefix='showcase-root-smoke-') as d:
 t=Path(d);(t/'public').mkdir();(t/'sessions').mkdir()
 # Trusted fixture only: header selects test scenarios, never deployed routing.
 router=t/'router.php';router.write_text('<?php if(($_SERVER["HTTP_X_FIXTURE"]??"")!==getenv("TEST_KEY")){http_response_code(404);exit;} $mode=$_SERVER["HTTP_X_MODE"]??"off"; putenv("FAS_SHOWCASE_BOOTSTRAP=".($mode==="off"?"":($mode==="bad"?"/missing/private-secret.php":getenv("TEST_BOOTSTRAP"))));if(!in_array($mode,["plain","off"]))$_SERVER["HTTPS"]="on";require '+json.dumps(str(root/'showcase.php'))+';')
 boot=t/'bootstrap.php';boot.write_text('<?php file_put_contents(__DIR__."/boot-called","1");header("X-Bootstrap: secret");header("Location: /not-a-real-destination");setcookie("bootstrap-cookie","secret"); if(($_SERVER["HTTP_X_MODE"]??"") === "throw")throw new RuntimeException("secret"); if(($_SERVER["HTTP_X_MODE"]??"")==="null"){echo "hidden-bootstrap-output";return null;} $d=new PDO("sqlite:".__DIR__."/store.sqlite",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec("PRAGMA foreign_keys=ON");return FAS\\Showcase\\ShowcaseIntakeRuntime::compose(["database"=>__DIR__."/store.sqlite","quota_database"=>__DIR__."/quota.sqlite","public_roots"=>[__DIR__."/public"],"max_submissions"=>20,"max_photo_bytes"=>20000000,"quota_secret"=>str_repeat("x",32),"actor_limit"=>10,"window_seconds"=>10,"actor_capacity"=>20,"global_limit"=>100,"slots"=>[__DIR__."/slot"],"consent_version"=>"test-v1","consent_text"=>"Synthetic permission.","form_ttl"=>10,"form_capacity"=>3],fn()=>true,fn()=>session_id(),fn()=>1000,fn()=>true);')
 provision='require '+json.dumps(str(root/'src/showcase/ShowcaseRepository.php'))+';require '+json.dumps(str(root/'src/showcase/ShowcaseRateAdmission.php'))+';$d=new PDO("sqlite:".$argv[1]."/store.sqlite",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec("PRAGMA foreign_keys=ON");FAS\\Showcase\\ShowcaseRepository::initializeSchema($d);$q=new PDO("sqlite:".$argv[1]."/quota.sqlite");$q->exec(FAS\\Showcase\\ShowcaseRateAdmission::SCHEMA);$q->exec(FAS\\Showcase\\ShowcaseRateAdmission::STATE_SCHEMA);$q->prepare("INSERT INTO showcase_rate_state VALUES(1,?,0,0,0)")->execute([FAS\\Showcase\\ShowcaseRateAdmission::binding(str_repeat("x",32),10,10,20,100)]);'
 subprocess.run([php,'-r',provision,d],check=True,capture_output=True);(t/'slot').write_text('FAS-SHOWCASE-SLOT-v1\n')
 s=socket.socket();s.bind(('127.0.0.1',0));port=s.getsockname()[1];s.close()
 env=dict(os.environ,TEST_KEY=key,TEST_BOOTSTRAP=str(boot));proc=subprocess.Popen([php,'-d','session.save_path='+str(t/'sessions'),'-S',f'127.0.0.1:{port}',str(router)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def call(mode,extra=None,data=None):
  h={'X-Fixture':key,'X-Mode':mode};h.update(extra or {});req=urllib.request.Request(f'http://127.0.0.1:{port}/showcase.php',headers=h,data=data)
  try:r=urllib.request.urlopen(req,timeout=5)
  except urllib.error.HTTPError as e:r=e
  with r:return r.status,r.read().decode(),r.headers
 try:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:time.sleep(.03)
  from html.parser import HTMLParser
  class Document(HTMLParser):
   def __init__(self):
    super().__init__();self.tags=[];self.declarations=[]
   def handle_starttag(self,tag,attrs):self.tags.append((tag,dict(attrs)))
   def handle_decl(self,decl):self.declarations.append(decl.lower())
  def document(body):
   parsed=Document();parsed.feed(body)
   check(parsed.declarations==['doctype html'])
   check([a for t,a in parsed.tags if t=='html']==[{'lang':'en'}])
   check(len([t for t,a in parsed.tags if t=='main'])==1)
   check(len([t for t,a in parsed.tags if t=='title'])==1)
   check(any(t=='meta' and a.get('charset')=='utf-8' for t,a in parsed.tags))
   check(any(t=='meta' and a.get('name')=='viewport' and a.get('content')=='width=device-width, initial-scale=1' for t,a in parsed.tags))
   check(not any(t in ['script','iframe','link','base'] for t,a in parsed.tags))
  for mode,status in [('off',404),('bad',503),('null',404),('throw',503),('plain',403)]:
   code,body,h=call(mode);check(code==status);check(h.get('Set-Cookie') is None);check('private-secret' not in body and 'hidden-bootstrap-output' not in body);check(h.get('Location') is None);check(h.get('X-Bootstrap') is None);document(body)
  (t/'boot-called').unlink(missing_ok=True)
  check(call('plain',{'X-Forwarded-Proto':'https'})[0]==403);check(not (t/'boot-called').exists())
  code,body,h=call('tls');document(body);check(code==200);cookie=h['Set-Cookie'];check(all(x.lower() in cookie.lower() for x in ['Secure','HttpOnly','SameSite=Lax','path=/showcase.php']))
  check('bootstrap-cookie' not in str(h));check(h.get('X-Bootstrap') is None);check(h.get('Location') is None);check('form_id' in body);check(h['Cache-Control']=='private, no-store')
  import re
  fields={k:re.search('name="'+k+'" value="([a-f0-9]+)"',body).group(1) for k in ['csrf_token','form_id']}
  fields.update(display_name='Synthetic',email='private@example.invalid',caption='test',consent='1')
  png=subprocess.run([php,'-r','$i=imagecreatetruecolor(5,3);imagepng($i);'],check=True,capture_output=True).stdout
  boundary='fixture-'+secrets.token_hex(16)
  parts=[f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode() for name,value in fields.items()]
  parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="photo"; filename="synthetic.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+b'\r\n');parts.append(f'--{boundary}--\r\n'.encode());data=b''.join(parts)
  # Explicit fixture cookie bridge: secure-cookie browser behavior is NOT tested.
  headers={'Cookie':cookie.split(';')[0],'Content-Type':'multipart/form-data; boundary='+boundary}
  saved=call('tls',headers,data);check(saved[0]==200 and 'Your submission is saved' in saved[1]);document(saved[1]);check(call('tls',headers,data)[1]==saved[1])
  invalid=call('tls',{'Content-Type':headers['Content-Type']},data);check(invalid[0]==400);document(invalid[1])
  print(f'Root smoke: {checks} assertions passed (native HTTPS flag simulated; not TLS/browser validation).')
 finally:proc.terminate();proc.wait(timeout=5)
