import tempfile
import urllib.request,urllib.error,urllib.parse,http.cookiejar,subprocess,socket,time,os,secrets,json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
s=socket.socket();s.bind(('127.0.0.1',0));port=s.getsockname()[1];s.close()
key=secrets.token_hex(32);env=dict(os.environ,SHOWCASE_FIXTURE_KEY=key)
sessions=tempfile.TemporaryDirectory(prefix='showcase-session-')
p=subprocess.Popen(['php','-d','session.save_path='+sessions.name,'-S',f'127.0.0.1:{port}','tests/showcase-session-router.php'],cwd=root,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=(subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0))
checks=0
def check(v):
 global checks
 assert v;checks+=1
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,form=None,now=1000):
 data=None if form is None else urllib.parse.urlencode({'form_id':form}).encode()
 r=urllib.request.Request(f'http://127.0.0.1:{port}/',data=data,headers={'X-Fixture-Key':key,'X-Fixture-Time':str(now)})
 try:
  with c.open(r) as out:return out.status,json.load(out)
 except urllib.error.HTTPError as e:return e.code,json.load(e)
try:
 for _ in range(100):
  try:
   with socket.create_connection(('127.0.0.1',port),timeout=.1):break
  except OSError:time.sleep(.05)
 a=client();b=client();status,one=req(a);check(status==200);status,two=req(a);check(status==200);check(one['form_id']!=two['form_id']);check(req(a)[0]==400)
 check(req(a,one['form_id'])==(200,one));check(req(a,one['form_id'])==(200,one));check(req(a,two['form_id'])==(200,two))
 check(req(b,one['form_id'])==(400,{'count':0}));check(req(b,'unknown')==(400,{'count':0}));check(req(a,'unknown')==(400,{'count':2}))
 check(req(a,one['form_id'],1009)==(200,one));check(req(a,one['form_id'],1010)==(400,{'count':2}));check(req(a,two['form_id'],1010)==(400,{'count':2}))
 status,three=req(a,now=1010);check(status==200);check(three['form_id'] not in [one['form_id'],two['form_id']]);check(req(a,three['form_id'],1010)==(200,three))
 print(f'Session real HTTP: {checks} assertions passed.')
finally:p.terminate();p.wait(timeout=5);sessions.cleanup()
