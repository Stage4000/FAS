"""Isolated UI HTTP integration using actual blog model/CSRF and a synthetic auth adapter.
No production configuration or credentials are loaded. Defaults to actual repository authentication/layout; --adapter is optional.
"""
from pathlib import Path
import tempfile, shutil, subprocess, socket, time, urllib.request, urllib.error, urllib.parse, http.cookiejar, re, html, json, sqlite3, os, sys
ROOT=Path(__file__).resolve().parents[1]
MODEL=ROOT/'src/blog'
SITE=Path(tempfile.mkdtemp(prefix='fas-blog-admin-test-'))
for directory in ['admin/includes','src/blog','src/utils']:(SITE/directory).mkdir(parents=True,exist_ok=True)
for source in (ROOT/'admin').glob('*.php'):shutil.copy2(source,SITE/'admin'/source.name)
shutil.copytree(ROOT/'admin/js',SITE/'admin/js',dirs_exist_ok=True)
shutil.copytree(ROOT/'admin/css',SITE/'admin/css',dirs_exist_ok=True)
for source in MODEL.glob('*.php'):shutil.copy2(source,SITE/'src/blog'/source.name)
shutil.copy2(ROOT/'src/utils/CSRF.php',SITE/'src/utils/CSRF.php')
(SITE/'admin/includes/nav.php').write_text('')
(SITE/'admin/includes/footer.php').write_text('')
(SITE/'admin/auth.php').write_text('''<?php
namespace FAS\\Config { class Database { public static function getInstance(){return new self;} public function getConnection(){$db=new \\PDO('sqlite:'.__DIR__.'/../fixture.sqlite'); $db->exec('PRAGMA foreign_keys=ON'); return $db;} } }
namespace FAS\\Utils { class Timezone { public static function toUserDateTime($v){return (string)$v;} } }
namespace { session_start(); class AdminAuth { public $lastError=''; public function isLoggedIn(){return ($_SESSION['fixture_admin']??false)===true && !file_exists(__DIR__.'/../revoked');} public function requireActiveAdmin(){if(!$this->isLoggedIn()){http_response_code(403);exit('Denied');}return ['id'=>1];} public function verifyCurrentPassword($id,$password){return $password==='fixture-only';} } }
''')
(SITE/'login.php').write_text("<?php session_start(); $_SESSION['fixture_admin']=true; echo 'fixture login';")
(SITE/'init.php').write_text("<?php require __DIR__.'/src/blog/BlogRepository.php'; $db=new PDO('sqlite:'.__DIR__.'/fixture.sqlite'); $db->exec('PRAGMA foreign_keys=ON'); FAS\\Blog\\BlogRepository::install($db); ")
real='--adapter' not in sys.argv
env={key:value for key,value in os.environ.items() if key.upper() in ['PATH','SYSTEMROOT','WINDIR','TEMP','TMP','COMSPEC','PATHEXT']}
if real:
    for relative in ['admin/auth.php','admin/includes/nav.php','admin/includes/footer.php','includes/admin-accounts.php','includes/security.php','src/security/ClientIp.php','src/security/SecurityStore.php','src/utils/Timezone.php']:
        destination=SITE/relative;destination.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/relative,destination)
    (SITE/'src/config').mkdir(exist_ok=True)
    (SITE/'src/config/Database.php').write_text("<?php namespace FAS\\Config; class Database {public static function getInstance(){return new self;} public function getConnection(){$db=new \\PDO('sqlite:'.__DIR__.'/../../fixture.sqlite'); $db->exec('PRAGMA foreign_keys=ON');return $db;}}")
    password=subprocess.check_output(['php','-r','echo password_hash("fixture-only",PASSWORD_DEFAULT);'],text=True)
    with sqlite3.connect(SITE/'fixture.sqlite') as db:
        db.execute('CREATE TABLE admin_users(id INTEGER PRIMARY KEY,username TEXT,password_hash TEXT,email TEXT,role TEXT,is_active INTEGER,last_login TEXT)')
        db.execute("INSERT INTO admin_users VALUES(1,'fixture',?,'fixture@example.invalid','admin',1,NULL)",[password])
    (SITE/'login.php').write_text("<?php require __DIR__.'/admin/auth.php'; $a=new AdminAuth(); if(!$a->login('fixture','fixture-only')){http_response_code(403);echo $a->lastError;exit;} echo 'fixture login';")
    env['FAS_SECURITY_DB_PATH']=str(Path(tempfile.mkdtemp(prefix='fas-blog-security-'))/'security.sqlite')
    for asset in ['public/js/timezone.js','admin/css/admin-notifications.css','admin/js/pwa-installer.js']:
        (SITE/asset).parent.mkdir(parents=True,exist_ok=True);(SITE/asset).write_text('')
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
log=(SITE/'server.log').open('w')
def ini_path_argument(name, path):
    # PHP parses -d values as INI, even when subprocess bypasses the shell.
    value=Path(path).as_posix().replace('\"', '\\\"')
    return name+'=\"'+value+'\"'
session_dir=Path(tempfile.mkdtemp(prefix='fas-blog-test-sessions-# ! '))
session_path_argument=ini_path_argument('session.save_path',session_dir)
actual_session_path=subprocess.check_output(['php','-d',session_path_argument,'-r','echo ini_get("session.save_path");'],text=True,env=env)
assert actual_session_path==session_dir.as_posix(), 'PHP INI session path must survive spaces, # and ! exactly'

session_name='FASBLOGTEST'+os.urandom(8).hex()
server=subprocess.Popen(['php','-d',session_path_argument,'-d','session.name='+session_name,'-d','allow_url_fopen=0','-d','disable_functions=mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open','-S',f'127.0.0.1:{port}','-t',str(SITE)],stdout=log,stderr=log,env=env)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect)
admin=client();anon=client();checks=[];skipped=[]
def req(path,data=None,who=admin):
    try:r=who.open(urllib.request.Request(f'http://127.0.0.1:{port}'+path,data=None if data is None else urllib.parse.urlencode(data).encode()),timeout=10)
    except urllib.error.HTTPError as e:r=e
    with r:return r.status,dict(r.headers),r.read().decode()
def check(value,label):
    assert value,label
    checks.append(label)
def field(body,name):return html.unescape(re.search('name="'+name+'" value="([^"]*)"',body)[1])
def tokens(path):
    body=req(path)[2]
    return {name:field(body,name) for name in ['csrf_token','revision']+(['request_key'] if '?' not in path else [])}
data=dict(title='Fixture article',slug='fixture-article',excerpt='Fixture summary',body='## Heading\n\n<script>alert(1)</script>\n\n[Bad](javascript:alert)',author_name='Fixture author',seo_title='',seo_description='',cover_image='',cover_alt='',related_product_ids='')
try:
    for _ in range(50):
        try:req('/login.php');break
        except OSError:time.sleep(.1)
    for path in ['/admin/blog.php','/admin/blog-edit.php','/admin/blog-preview.php?id=1']:check(req(path,who=anon)[0]==(302 if real else 403),'Anonymous denied '+path)
    check(req('/admin/blog.php')[0]==503,'Missing storage fails closed')
    with sqlite3.connect(SITE/'fixture.sqlite') as db:check(db.execute("SELECT count(*) FROM sqlite_master WHERE name='blog_posts'").fetchone()[0]==0,'HTTP does not install storage')
    subprocess.run(['php',str(SITE/'init.php')],check=True,capture_output=True)
    check('No posts here yet' in req('/admin/blog.php')[2],'Empty state')
    t=tokens('/admin/blog-edit.php');payload={**data,**t,'action':'draft'}
    check(req('/admin/blog-edit.php',{**payload,'csrf_token':'bad'})[0]==403,'Bad CSRF rejects draft')
    check(req('/admin/blog-edit.php',payload)[0]==303,'Create draft redirects')
    check(req('/admin/blog-edit.php',payload)[0]==303,'Duplicate create safely redirects')
    with sqlite3.connect(SITE/'fixture.sqlite') as db:check(db.execute('SELECT count(*) FROM blog_posts').fetchone()[0]==1,'Duplicate creates one row')
    path='/admin/blog-edit.php?id=1';t=tokens(path)
    if real:
        for page_path in ['/admin/blog.php',path,'/admin/blog-preview.php?id=1']:
            nav_body=req(page_path)[2]
            blog_links=re.findall(r'<a href="blog.php" class="list-group-item list-group-item-action ([^"]*)">',nav_body)
            check(len(blog_links)==1 and 'active' in blog_links[0],'Private Blog nav appears once and active '+page_path)
        (SITE/'admin/nav-check.php').write_text("<?php require __DIR__.'/auth.php'; $a=new AdminAuth();$a->requireActiveAdmin();$_SERVER['PHP_SELF']='product-content.php';include __DIR__.'/includes/nav.php';")
        nav_body=req('/admin/nav-check.php')[2]
        check(re.search(r'href="product-quality.php" class="list-group-item list-group-item-action active"',nav_body) is not None,'Existing product-content active mapping remains unchanged')
    check('Pre-launch mode' in req(path)[2] and 'Save reviewed publication snapshot' in req(path)[2],'Absent release flag defaults to private prelaunch wording')
    (SITE/'src/config').mkdir(exist_ok=True)
    (SITE/'src/config/config.php').write_text("<?php return ['blog'=>['public_enabled'=>'true']];")
    check('Pre-launch mode' in req(path)[2],'String true does not enable public release wording')
    (SITE/'src/config/config.php').write_text("<?php return ['blog'=>['public_enabled'=>true]];")
    check('Publish reviewed article' in req(path)[2] and 'Pre-launch mode' not in req(path)[2],'Explicit boolean release flag enables accurate public wording')
    (SITE/'src/config/config.php').write_text("<?php return ['blog'=>['public_enabled'=>false]];")
    status,headers,preview=req('/admin/blog-preview.php?id=1')
    check(status==200 and headers.get('Cache-Control')=='private, no-store' and headers.get('X-Robots-Tag')=='noindex, nofollow','Private preview cache/robots headers')
    check('&lt;script&gt;' in preview and '<script>alert' not in preview and 'href="javascript:' not in preview,'Preview uses safe renderer')
    check('last saved draft only' in req(path)[2] and 'rel="noopener"' in req(path)[2],'Saved preview semantics and new-tab protection')
    check(req(path,{**data,**t,'action':'publish'})[0]==422,'Publish requires review')
    check(req(path,{**data,**t,'action':'publish','reviewed':'1'})[0]==403,'Publish requires reauth')
    status,_,body=req(path,{**data,**t,'action':'publish','reviewed':'1','password':'secret-invalid'})
    check(status==403 and 'secret-invalid' not in body,'Password never repopulated')
    cover={**data,**t,'action':'publish','reviewed':'1','password':'fixture-only','cover_alt':'Fixture image'}
    status,_,body=req(path,{**cover,'cover_image':'/gallery/missing.jpg'})
    check(status==422 and '/gallery/missing.jpg' in body,'Missing cover blocks publication and preserves input')
    (SITE/'gallery').mkdir(exist_ok=True)
    (SITE/'gallery/directory.jpg').mkdir()
    check(req(path,{**cover,'cover_image':'/gallery/directory.jpg'})[0]==422,'Directory cannot be published as cover')
    outside=SITE/'outside.jpg';outside.write_bytes(b'fixture-only')
    try:
        (SITE/'gallery/escape.jpg').symlink_to(outside)
        check(req(path,{**cover,'cover_image':'/gallery/escape.jpg'})[0]==422,'Symlink escape cannot be published as cover')
    except OSError as error:skipped.append('Symlink creation unavailable: '+str(error))
    (SITE/'gallery/fixture.jpg').write_bytes(b'fixture-only')
    check(req(path,{**cover,'cover_image':'/gallery/fixture.jpg'})[0]==303,'Existing readable gallery cover publishes reviewed submitted content')
    check('Reviewed snapshot' in req('/admin/blog.php')[2] and 'Pre-launch mode' in req('/admin/blog.php')[2] and '<td>Published</td>' not in req('/admin/blog.php')[2],'Prelaunch list labels reviewed snapshot and explains release hold')
    check('Status:</strong> Reviewed snapshot' in req(path)[2] and 'Remove this reviewed snapshot.' in req(path)[2] and 'Remove the published article from public view.' not in req(path)[2],'Prelaunch editor status and withdrawal describe snapshot only')
    check('unsaved edits in this form are not saved by this action' in req(path)[2],'Withdrawal explicitly keeps last saved draft rather than unsaved form edits')
    (SITE/'src/config/config.php').write_text("<?php return ['blog'=>['public_enabled'=>true]];")
    check('<td>Published</td>' in req('/admin/blog.php')[2] and 'Remove the published article from public view.' in req(path)[2],'Released mode retains public status and withdrawal wording')
    (SITE/'src/config/config.php').write_text("<?php return ['blog'=>['public_enabled'=>false]];")
    with sqlite3.connect(SITE/'fixture.sqlite') as db:published=db.execute('SELECT published_json FROM blog_posts').fetchone()[0]
    t=tokens(path);changed={**data,'title':'Private changed title'}
    check(req(path,{**changed,**t,'action':'draft'})[0]==303,'Published post accepts private draft')
    check('Draft changes after review' in req(path)[2] and 'Draft changes after review' in req('/admin/blog.php')[2],'Prelaunch private edits are draft changes after review')
    with sqlite3.connect(SITE/'fixture.sqlite') as db:check(db.execute('SELECT published_json FROM blog_posts').fetchone()[0]==published,'Save draft preserves published snapshot')
    status,_,body=req(path,{**changed,**t,'action':'draft','title':'My conflict text'})
    check(status==409 and 'My conflict text' in body and field(body,'revision')==t['revision'],'Conflict retains submitted text and stale token')
    check(req(path,{**changed,**t,'action':'draft'})[0]==409,'Repeated stale save still conflicts')
    t=tokens(path)
    status,_,body=req(path,{**changed,**t,'action':'draft','slug':'different-slug'})
    check(status in [409,422] and 'different-slug' in body,'Published slug cannot change')
    check('readonly' in req(path)[2] and 'URL is locked after first publication' in req(path)[2],'Slug immutability visibly explained')
    check(req(path,{**changed,**t,'action':'withdraw'})[0]==422,'Withdraw requires explicit confirmation')
    check(req(path,{**changed,**t,'action':'withdraw','confirm_withdraw':'1'})[0]==303,'Explicit withdraw works')
    check('Reviewed snapshot removed. Your draft remains saved.' in req(path+'&saved=withdraw')[2],'Prelaunch removal success describes reviewed snapshot')
    with sqlite3.connect(SITE/'fixture.sqlite') as db:check(db.execute('SELECT published_json FROM blog_posts').fetchone()[0] is None,'Withdrawal removes public snapshot')
    t=tokens(path)
    status,_,body=req(path,{**changed,**t,'action':'draft','title':'<img src=x onerror=alert(1)>'})
    check(status==303 or '&lt;img' in body,'Text input safely handled')
    check('<img src=x' not in req('/admin/blog.php')[2],'List escapes title')
    t=tokens(path)
    status,_,body=req(path,{**changed,**t,'action':'draft','related_product_ids':'1,banana','body':'Keep my invalid form text'})
    check(status==422 and 'Keep my invalid form text' in body and '1,banana' in body,'Validation preserves submitted body and related IDs')
    check(req(path,{**changed,**t,'action':'delete'})[0]==422,'Unknown mutation rejected')
    check(req('/admin/blog-edit.php?id=99999')[0]==404 and req('/admin/blog-preview.php?id[]=1')[0]==404,'Missing and malformed IDs return 404')
    check(req('/admin/blog.php?page[]=1')[0]==200,'Malformed pagination safely falls back')
    rich={'version':1,'blocks':[{'type':'paragraph','children':[{'text':'Literal # [x](/path) \\ **stars** <script>alert(1)</script>','bold':True},{'text':'Safe link','href':'https://example.invalid/a_(b)?x=1&y=2'}]},{'type':'heading','level':2,'children':[{'text':'Heading'}]},{'type':'ordered_list','items':[[{'text':'First','italic':True}],[{'text':'Second','underline':True}]]}]}
    t=tokens(path)
    check(req(path,{**changed,**t,'action':'draft','body_format':'rich_v1','body':json.dumps(rich)})[0]==303,'Structured visual document saves through real backend')
    check('id="blog-visual-editor"' in req(path)[2] and 'data-command="bold"' in req(path)[2],'Custom visual canvas and formatting toolbar render')
    rich_preview=req('/admin/blog-preview.php?id=1')[2]
    check('<strong>Literal # [x](/path)' in rich_preview and '&lt;script&gt;' in rich_preview and '<ol>' in rich_preview,'Rich preview preserves literal punctuation and explicit formatting safely')
    t=tokens(path)
    oversized=json.dumps({'version':1,'blocks':[{'type':'paragraph','children':[{'text':'x'*60001}]}]})
    status,_,body=req(path,{**changed,**t,'action':'draft','body_format':'rich_v1','body':oversized})
    check(status==422 and 'source is preserved above' in body and 'x'*1000 in body,'Oversized rich body stays in source fallback without fatal or data loss')
    status,_,body=req(path,{**changed,**t,'action':'draft','body_format':'rich_v1','body':'{broken'})
    check(status==422 and '{broken' in body,'Malformed rich JSON remains available for correction')
    rich_image={'version':1,'blocks':[{'type':'paragraph','children':[{'text':'Reviewed fixture'}]},{'type':'image','src':'/gallery/missing-inline.jpg','alt':'Missing image','caption':''}]}
    check(req(path,{**changed,**t,'action':'publish','reviewed':'1','password':'fixture-only','body_format':'rich_v1','body':json.dumps(rich_image)})[0]==422,'Missing inline gallery image blocks reviewed publication snapshot')
    if real:
        for role,active in [('viewer',1),('admin',0)]:
            with sqlite3.connect(SITE/'fixture.sqlite') as db:db.execute('UPDATE admin_users SET role=?,is_active=? WHERE id=1',[role,active])
            check(req(path)[0]==403 and req(path,{**changed,**t,'action':'draft'})[0]==403,'Real account role/active revocation blocks GET and POST '+str((role,active)))
        with sqlite3.connect(SITE/'fixture.sqlite') as db:db.execute("UPDATE admin_users SET role='admin',is_active=1 WHERE id=1")
        check('name="password"' in req(path)[2],'Real access revocation clears reauthentication')
        with sqlite3.connect(SITE/'fixture.sqlite') as db:db.execute('INSERT INTO admin_account_security(admin_id,session_version) VALUES(1,2)')
    else:(SITE/'revoked').write_text('1')
    check(req(path)[0]==403 and req(path,{**changed,**t,'action':'draft'})[0]==403,'Revoked synthetic session blocked on reads and writes')
    report={'checks':len(checks),'passed':checks,'skipped':skipped,'scope':'Local synthetic HTTP; actual blog model, CSRF, AdminAuth, security, account helpers and layout; isolated Database config' if real else 'Local synthetic HTTP; actual blog model and CSRF; auth/layout adapters, not real authentication integration','browser_tested':False,'fixture':str(SITE)}
    (ROOT/'audit').mkdir(exist_ok=True)
    (ROOT/'audit/blog-admin-http-results.json').write_text(json.dumps(report,indent=2))
    print(json.dumps(report,indent=2))
finally:
    server.terminate();server.wait(timeout=10);log.close()

