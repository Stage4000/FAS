"""Native HTTP + cookie-session + exact current AdminAuth class, synthetic dependencies.
No TLS/browser/real accounts or production bootstrap. All files are isolated in tempdir.
"""
from pathlib import Path
import tempfile,subprocess,shutil,os,socket,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,json,secrets,re,hashlib
root=Path(__file__).resolve().parents[1];key=secrets.token_hex(32);checks=0
php='php'
def check(value,label):
 global checks
 assert value,label;checks+=1
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args):return None
with tempfile.TemporaryDirectory(prefix='showcase-admin-browser-') as d:
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
 proc=subprocess.Popen([php,'-d','session.save_path='+str(t/'sessions'),'-S',f'127.0.0.1:{port}',str(root/'tests/showcase-admin-http-router.php')],env=dict(os.environ,TEST_KEY=key,TEST_DATA=d),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:time.sleep(.03)
  from playwright.sync_api import sync_playwright
  with sync_playwright() as pw:
   browser=pw.chromium.launch(executable_path=os.environ.get('SHOWCASE_CHROMIUM') or shutil.which('chromium') or pw.chromium.executable_path,headless=True,ignore_default_args=['--disable-back-forward-cache'],args=['--no-sandbox'])
   context=browser.new_context(extra_http_headers={'X-Fixture':key},viewport={'width':1280,'height':900})
   context.add_init_script("addEventListener('pageshow',function(e){if(e.persisted && document.body.textContent.includes('private@example.invalid'))sessionStorage.setItem('showcase-history-leak','yes');});")
   page=context.new_page();base=f'http://127.0.0.1:{port}'
   check(page.goto(base+'/admin/queue.php').status==403,'unauthenticated browser denied')
   page.goto(base+'/fixture-login');page.goto(base+'/admin/queue.php')
   check(page.get_by_role('heading',name='Review customer photos').count()==1,'queue heading')
   check(page.locator('script').count()==1 and page.locator('script[data-showcase-history-guard]').count()==1,'only fixed history guard; name cannot create script')
   page.get_by_role('link',name='<script>Fixture</script>').click()
   check(page.get_by_role('heading',name='Review customer photo',exact=True).count()==1,'detail navigation')
   check(page.locator('img').evaluate('(i)=>i.complete && i.naturalWidth===5')==True,'private image loaded')
   stale=page.evaluate('Object.fromEntries(new FormData(document.querySelector("form")))')
   check(page.locator('input[name=reason]').evaluate('(i)=>i.checkValidity()')==False,'required reason')
   page.get_by_role('button',name='Approve',exact=True).click()
   check(page.url.endswith('/admin/detail.php?id='+id),'invalid form stays on detail')
   check(page.get_by_text('No decisions recorded.',exact=True).count()==1,'invalid form no write')
   page.get_by_label('Reason for this decision (required)').fill('Browser approval')
   page.get_by_role('button',name='Approve',exact=True).click();page.wait_for_url('**/admin/detail.php?id='+id)
   check(page.locator('strong').first.inner_text()=='approved','real form approved via redirect')
   check(page.get_by_role('button',name='Approve',exact=True).count()==0,'approved cannot approve again')
   check(page.locator('ol li').count()==1,'one audit entry')
   page.reload();check(page.locator('ol li').count()==1,'refresh does not resubmit')
   # Browser request uses same cookies; stale snapshot must not produce another write.
   stale.update(reason='Repeated click',decision='approved')
   duplicate=context.request.post(base+'/admin/moderate.php',form=stale)
   check(duplicate.status==409,'stale/repeated snapshot rejected')
   page.reload();check(page.locator('ol li').count()==1,'duplicate did not write')
   # Back/forward after state change must expose fresh review, not stale choices.
   page.go_back(wait_until='commit')
   page.wait_for_function("document.readyState==='complete' && document.querySelector('script[data-showcase-history-guard]')!==null")
   page.go_forward(wait_until='commit')
   page.wait_for_function("document.readyState==='complete' && document.querySelector('script[data-showcase-history-guard]')!==null")
   page.reload()
   check(page.get_by_role('button',name='Approve',exact=True).count()==0,'history returns current state')
   page.get_by_label('Reason for this decision (required)').fill('Browser withdrawal')
   page.get_by_role('button',name='Withdraw',exact=True).click();page.wait_for_url('**/admin/detail.php?id='+id)
   check(page.locator('strong').first.inner_text()=='withdrawn','withdrawal form')
   check(page.locator('form').count()==0,'withdrawn no further form')
   check(page.locator('ol li').count()==2,'two audit entries')
   page.goto(base+'/fixture-logout');page.go_back(wait_until='commit')
   page.get_by_text('Administrator access required.',exact=True).wait_for()
   check(page.evaluate("sessionStorage.getItem('showcase-history-leak')") is None,'no private DOM on persisted pageshow')
   check('private@example.invalid' not in page.locator('body').inner_text(),'back after logout does not reveal cached contact')
   check(page.reload().status==403,'reload after logout denied')
   check(context.request.get(base+'/admin/photo.php?id='+id).status==403,'photo after logout denied')
   mobile=browser.new_context(extra_http_headers={'X-Fixture':key},viewport={'width':390,'height':844})
   mp=mobile.new_page();mp.goto(base+'/fixture-login');mp.goto(base+'/admin/detail.php?id='+id)
   check(mp.locator('strong').first.inner_text()=='withdrawn','mobile current detail')
   check(mp.evaluate('document.documentElement.scrollWidth<=innerWidth'),'mobile no horizontal overflow synthetic short content')
   mobile.close();context.close();browser.close()
  print(f'Native Chromium admin: {checks} assertions passed; loopback HTTP only, no TLS/production acceptance.')
 finally:proc.terminate();proc.wait(timeout=5)
