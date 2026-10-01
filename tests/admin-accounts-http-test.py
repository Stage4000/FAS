"""Multi-admin lifecycle against an isolated SQLite site. No real accounts or email."""
from pathlib import Path
import http.cookiejar, json, os, re, shutil, socket, sqlite3, subprocess, sys, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(tempfile.mkdtemp(prefix='fas-admin-accounts-'))
SITE = BASE/'site'
SITE.mkdir()
for folder in ['src','admin','includes','public','api','scripts']:
    shutil.copytree(ROOT/folder, SITE/folder, ignore=shutil.ignore_patterns(
        'config.php','config.local.php','config.production.php','applepay.php','shipping.php',
        'config.json','settings.json','uploads','backups','exports','*.db','*.sqlite','*.log'))
shutil.copy2(ROOT/'src/config/config.example.php', SITE/'src/config/config.php')
(SITE/'database').mkdir()
(SITE/'gallery').mkdir()
shutil.copytree(ROOT/'gallery/favicons', SITE/'gallery/favicons')
shutil.copy2(ROOT/'gallery/FLIPANDSTRIP.COM_d00a_018a.jpg',SITE/'gallery/FLIPANDSTRIP.COM_d00a_018a.jpg')
(SITE/'access-probe.php').write_text("<?php require __DIR__.'/includes/storefront-access.php'; require __DIR__.'/src/payments/ApplePayContext.php'; echo json_encode(['preview'=>fasCanViewHiddenProducts(),'wallet'=>\\FAS\\Payments\\ApplePayContext::allowed(['enabled'=>true,'admin_only'=>true])]);",encoding='utf-8')
DB = SITE/'database/flipandstrip.db'
OWNER_PASSWORD = 'Owner-test-only-123'
SECOND_PASSWORD = 'Second-test-only-123'
hashed = subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',OWNER_PASSWORD],text=True)
with sqlite3.connect(DB) as db:
    db.executescript((ROOT/'database/schema.sqlite.sql').read_text(encoding='utf-8'))
    db.execute("INSERT INTO admin_users(id,username,email,full_name,password_hash) VALUES(1,'owner-fixture','owner@example.invalid','Fixture Owner',?)",[hashed])
env = os.environ.copy()
env['FAS_SECURITY_DB_PATH'] = str(BASE/'private/security.sqlite')
env.pop('FAS_TRUSTED_PROXY_CIDRS',None)
def maintenance(*args):
    return subprocess.check_output(['php',str(SITE/'scripts/security-maintenance.php'),*args],env=env,text=True)
maintenance('init');maintenance('activate','--verified')
def rule(name,capacity=100):
    code = 'require '+json.dumps(str(SITE/'src/security/SecurityStore.php').replace('\\','/'))+'; $s=\\FAS\\Security\\SecurityStore::open();$s->saveRule($argv[1],(int)$argv[2],3600,"enforce","127.0.0.1",1);'
    subprocess.check_call(['php','-r',code,name,str(capacity)],env=env)
for name in ['admin_accounts','login_pair','login_account','login_ip','reauth_account','reauth_ip']: rule(name)
(BASE/'sessions').mkdir()
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
ORIGIN = 'http://127.0.0.1:'+str(port)
log = open(BASE/'server.log','w')
server = subprocess.Popen(['php','-d','disable_functions=mail','-d','session.save_path='+str(BASE/'sessions'),'-S','127.0.0.1:'+str(port),'-t',str(SITE)],env=env,stdout=log,stderr=log)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect)
owner=client()
def request(path,data=None,who=owner):
    if isinstance(data,dict):data=urllib.parse.urlencode(data).encode()
    try:r=who.open(urllib.request.Request(ORIGIN+path,data=data),timeout=15)
    except urllib.error.HTTPError as e:r=e
    with r:return r.status,dict(r.headers),r.read().decode()
def csrf(who=owner):return re.search(r'name="csrf_token" value="([^"]+)"',request('/admin/login.php',who=who)[2])[1]
def login(who,username,password):return request('/admin/login.php',{'csrf_token':csrf(who),'username':username,'password':password},who)
def sql(query,args=()):
    with sqlite3.connect(DB) as db:return db.execute(query,args).fetchall()
checks=[]
def check(ok,message):
    if not ok:raise AssertionError(message)
    checks.append(message)
PATH='/admin/administrators.php'
passed=False
try:
    for _ in range(50):
        try:request('/admin/login.php');break
        except OSError:time.sleep(.1)
    check(request(PATH)[0]==302,'Anonymous account management redirects to login')
    check(request('/admin/init-admin.php')[0]==404,'Initial administrator setup cannot run over HTTP')
    check(login(owner,'owner-fixture',OWNER_PASSWORD)[0]==302,'Existing administrator signs in')
    token=csrf()
    def change(action,**data):return request(PATH,{'csrf_token':token,'action':action,**data})
    new=dict(username='second-admin',full_name='Second Admin',email='second@example.invalid',new_password=SECOND_PASSWORD,confirm_password=SECOND_PASSWORD)
    check('Administrators' in request('/admin/settings.php')[2],'Sidebar links to administrator management')
    check(change('create',**new)[0]==403,'Account creation requires password confirmation')
    check(change('reauth',password=OWNER_PASSWORD)[0]==303,'Current password unlocks account management')
    check(request(PATH,{'action':'create',**new})[0]==403,'Account creation requires CSRF')
    check(change('deactivate',id=1)[0]==200 and sql('SELECT is_active FROM admin_users WHERE id=1')[0][0]==1,'Last administrator cannot be deactivated')
    check(change('create',**new)[0]==303,'Second administrator can be created')
    secondId=sql("SELECT id FROM admin_users WHERE username='second-admin'")[0][0]
    check(sql('SELECT role,is_active FROM admin_users WHERE id=?',[secondId])[0]==('admin',1),'New account has explicit full admin access')
    check(sql('SELECT password_hash FROM admin_users WHERE id=?',[secondId])[0][0]!=SECOND_PASSWORD,'New password stored as a hash')
    check(change('create',**{**new,'username':'SECOND-ADMIN','email':'different@example.invalid'})[0]==200 and len(sql('SELECT id FROM admin_users'))==2,'Case-variant duplicate usernames rejected')
    check(change('create',**{**new,'username':'third-admin','email':'SECOND@example.invalid'})[0]==200 and len(sql('SELECT id FROM admin_users'))==2,'Case-variant duplicate emails rejected')
    check(change('create',**{**new,'username':'third-admin','email':'third@example.invalid','new_password':'short','confirm_password':'short'})[0]==200 and len(sql('SELECT id FROM admin_users'))==2,'Weak account passwords rejected')
    check(change('create',**{**new,'username':'third-admin','email':'third@example.invalid','confirm_password':'Mismatch-value'})[0]==200 and len(sql('SELECT id FROM admin_users'))==2,'Mismatched account passwords rejected')
    status,headers,body=request(PATH+'?edit='+str(secondId))
    check(status==200 and 'no-store' in headers['Cache-Control'] and SECOND_PASSWORD not in body and hashed not in body,'Account page is private and does not expose credentials')
    check(change('update',id=secondId,username='second-admin',full_name='<script>Test</script>',email='second@example.invalid')[0]==303,'Administrator details can be edited')
    check('&lt;script&gt;Test&lt;/script&gt;' in request(PATH)[2],'Account names are escaped')
    check(change('deactivate',id=1)[0]==200 and sql('SELECT is_active FROM admin_users WHERE id=1')[0][0]==1,'Self-deactivation rejected even with another active administrator')
    second=client();secondOther=client()
    check(login(second,'second-admin',SECOND_PASSWORD)[0]==302 and login(secondOther,'second-admin',SECOND_PASSWORD)[0]==302,'Administrator supports independent simultaneous sessions')
    check(request('/admin/orders.php',who=second)[0]==200,'Second administrator can access existing panel routes')
    check(all(json.loads(request('/access-probe.php',who=second)[2]).values()),'Active admin can use hidden-product preview and admin-only wallet gate')
    check(change('deactivate',id=secondId)[0]==303,'Another administrator can be deactivated')
    check(request('/admin/orders.php',who=second)[0]==403 and request('/admin/settings.php',who=secondOther)[0]==403,'Deactivation revokes both existing sessions on ordinary and sensitive routes')
    check(not any(json.loads(request('/access-probe.php',who=second)[2]).values()),'Deactivation also revokes storefront admin privileges')
    check(login(client(),'second-admin',SECOND_PASSWORD)[0]==200,'Inactive administrator cannot sign in')
    check(change('activate',id=secondId)[0]==303,'Administrator can be reactivated')
    check(request('/admin/orders.php',who=second)[0]==403,'Reactivation does not revive old sessions')
    check(login(second,'second-admin',SECOND_PASSWORD)[0]==302,'Reactivated administrator can sign in again')
    replacement='Replacement-test-123'
    check(change('reset_password',id=secondId,new_password=replacement,confirm_password=replacement)[0]==303,'Another administrator password can be reset')
    check(request('/admin/orders.php',who=second)[0]==403,'Password reset revokes existing session')
    check(login(client(),'second-admin',SECOND_PASSWORD)[0]==200,'Old password no longer authenticates')
    check(login(second,'second-admin',replacement)[0]==302,'Reset password authenticates')
    other=client();check(login(other,'second-admin',replacement)[0]==302,'Another session signs in before own password change')
    own='Own-password-test-123'
    check(request('/admin/password.php',{'csrf_token':csrf(second),'current_password':replacement,'new_password':own,'confirm_password':own},second)[0]==200,'Own password change succeeds')
    check(request('/admin/orders.php',who=second)[0]==200 and request('/admin/orders.php',who=other)[0]==403,'Own password change retains current session and revokes other sessions')
    check(request(PATH,{'csrf_token':token,'action':'deactivate','id[]':str(secondId)})[0]==200 and sql('SELECT is_active FROM admin_users WHERE id=?',[secondId])[0][0]==1,'Malformed target IDs cannot deactivate an account')
    # Non-admin identities never enter the panel, including old session-only routes.
    sql("UPDATE admin_users SET role='viewer' WHERE id=?",[secondId])
    check(request('/admin/orders.php',who=second)[0]==403 and login(client(),'second-admin',own)[0]==200,'Non-admin role cannot use existing or new sessions')
    sql("UPDATE admin_users SET role='admin' WHERE id=?",[secondId])
    rule('admin_accounts',1)
    change('update',id=secondId,username='second-admin',full_name='Second Admin',email='second@example.invalid')
    status,headers,body=change('deactivate',id=secondId)
    check(status==429 and int(headers.get('Retry-After',0))>0 and sql('SELECT is_active FROM admin_users WHERE id=?',[secondId])[0][0]==1,'Account changes enforce a shared rate limit before writes')
    check(request(PATH)[0]==200,'Rate-limited administrator retains read access')
    events=sql('SELECT actor_id,target_id,action FROM admin_account_events')
    check(all(e[0]==1 for e in events) and any(e[1]==secondId and e[2]=='reset_password' for e in events),'Account audit records actor, target and action')
    check(SECOND_PASSWORD not in str(events) and replacement not in str(events),'Account audit excludes passwords')
    # Simulate a pre-upgrade PHP session, which has no credential binding.
    for file in (BASE/'sessions').glob('sess_*'):
        content=file.read_text()
        file.write_text(re.sub(r'admin_session_token\|s:\d+:"[^"]*";','',content))
    check(request('/admin/orders.php')[0]==403,'Legacy sessions must sign in after upgrade')
    check(login(owner,'owner-fixture',OWNER_PASSWORD)[0]==302,'Existing credentials continue to work after upgrade')
    rule('admin_accounts',10)
    print('PASS',len(checks),'multi-admin HTTP assertions')
    passed=True
finally:
    if '--keep' in sys.argv and passed:
        print(json.dumps(dict(origin=ORIGIN,site=str(SITE),base=str(BASE),pid=server.pid)))
    else:server.terminate();server.wait(timeout=10);log.close()
