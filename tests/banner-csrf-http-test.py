"""Banner mutation CSRF regression using real local auth and synthetic SQLite.
No production configuration, inventory, payments, emails or external HTTP calls.
PHP_BIN selects installed PHP; FAS_AUDIT_SOURCE selects a full source fixture.
FAS_BANNER_PAGE_SOURCE optionally overlays the independently reviewed page.
BANNER_EXPECT_BASELINE=1 expects original missing-token defect.
"""
from pathlib import Path
import html, http.cookiejar, json, os, re, shutil, socket, sqlite3, subprocess, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT = Path(os.environ.get('FAS_AUDIT_SOURCE', Path(__file__).resolve().parents[1])).resolve()
PHP = os.environ.get('PHP_BIN', 'php')
checks = []


def check(ok, name, **observed):
    checks.append({'name': name, 'passed': bool(ok), **observed})


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


def client():
    return urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)


with tempfile.TemporaryDirectory(prefix='fas-banner-request-') as tmp:
    base = Path(tmp)
    site = base / 'site'
    site.mkdir()
    for folder in ['admin', 'src', 'includes']:
        shutil.copytree(ROOT / folder, site / folder, ignore=shutil.ignore_patterns(
            'config.php', 'config.local.php', 'config.production.php', 'shipping.php',
            'config.json', 'settings.json', 'uploads', 'backups', 'exports', '*.db', '*.sqlite', '*.log'))
    if os.environ.get('FAS_BANNER_PAGE_SOURCE'):
        shutil.copyfile(os.environ['FAS_BANNER_PAGE_SOURCE'], site/'admin/banners.php')
    (site / 'database').mkdir()
    (site / 'admin/css').mkdir(exist_ok=True)
    (site / 'admin/css/admin-notifications.css').touch()
    (site / 'public/js').mkdir(parents=True)
    (site / 'public/js/timezone.js').touch()
    (base / 'sessions').mkdir()
    (site / 'src/config/config.php').write_text("<?php return ['database'=>['path'=>__DIR__.'/../../database/test.sqlite'],'site'=>['timezone'=>'UTC'],'security'=>['admin_password_salt'=>'synthetic-only']];\n")
    dbfile = site / 'database/test.sqlite'
    password = 'Synthetic-coupon-password-123'
    password_hash = subprocess.check_output([PHP, '-r', 'echo password_hash($argv[1],PASSWORD_DEFAULT);', password], text=True)
    with sqlite3.connect(dbfile) as db:
        db.executescript("""
        CREATE TABLE admin_users(id INTEGER PRIMARY KEY,username TEXT,password_hash TEXT,email TEXT,
            role TEXT,is_active INTEGER,last_login TEXT);
        CREATE TABLE coupons(id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT UNIQUE,description TEXT,
            discount_type TEXT,discount_value REAL,minimum_purchase REAL DEFAULT 0,max_uses INTEGER,
            times_used INTEGER DEFAULT 0,expires_at TEXT,is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        """)
        db.executescript((ROOT/'database/schema.sqlite.sql').read_text())
        db.execute("INSERT INTO products(id,name,price,sale_price,quantity,is_active,show_on_website,created_at,updated_at) VALUES(1,'Synthetic stale item',100,NULL,1,1,1,'2020-01-01 00:00:00','2020-01-01 00:00:00')")
        db.execute("INSERT INTO admin_users VALUES(1,'fixture-admin',?,'fixture@example.invalid','admin',1,NULL)", [password_hash])
    def sql(query, args=()):
        with sqlite3.connect(dbfile) as db:
            return db.execute(query, args).fetchall()
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    origin = 'http://127.0.0.1:' + str(port)
    env = {'PATH':os.environ.get('PATH','')} 
    env['FAS_SECURITY_DB_PATH'] = str(base / 'private/security.sqlite')
    env.pop('FAS_TRUSTED_PROXY_CIDRS', None)
    server_log = open(base / 'server.log', 'w')
    server = subprocess.Popen([PHP, '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect', '-d', 'allow_url_fopen=0', '-d',
        'session.save_path="' + str(base / 'sessions') + '"', '-S', '127.0.0.1:' + str(port), '-t', str(site)],
        env=env, stdout=server_log, stderr=server_log)
    def request(path='/admin/banners.php', data=None, who=None):
        who = who or client()
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data).encode()
        try:
            response = who.open(urllib.request.Request(origin + path, data=data), timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, dict(response.headers), response.read().decode()
    def token(body):
        match = re.search(r'name="csrf_token" value="([^"]+)"', body)
        return html.unescape(match[1]) if match else None
    def login(who):
        csrf = token(request('/admin/login.php', who=who)[2])
        status, _, _ = request('/admin/login.php', {
            'username': 'fixture-admin', 'password': password, 'csrf_token': csrf}, who)
        return status == 302, csrf
    baseline = os.environ.get('BANNER_EXPECT_BASELINE') == '1'
    try:
        for _ in range(60):
            if server.poll() is not None: raise RuntimeError('Local PHP server exited')
            try:
                request('/admin/login.php'); break
            except OSError: time.sleep(.05)
        else: raise RuntimeError('Local PHP server did not start')
        payload={'action':'create','message':'Synthetic new banner','bg_color':'info','text_color':'white','is_active':'1','is_dismissible':'1'}
        status,_,_=request(data=payload)
        check(status==302,'Anonymous mutation remains blocked',status=status)
        admin=client();ok,csrf=login(admin);check(ok,'Real authentication accepts synthetic admin')
        status,_,page=request(who=admin)
        check(status==200 and 'Database setup error:' not in page,'Actual banner page initializes synthetic schema',status=status)
        def seed():
            sql('DELETE FROM banners')
            sql("INSERT INTO banners(id,message) VALUES(1,'Synthetic original banner')")
        def snapshot():return sql('SELECT * FROM banners ORDER BY id')
        seed();status,_,page=request(who=admin)
        forms=re.findall(r'<form\b[^>]*method="POST"[^>]*>(.*?)</form>',page,re.S|re.I)
        check(len(forms)==3,'All three actual mutation form templates render')
        check(all(token(f) is None for f in forms) if baseline else all(token(f)==csrf for f in forms),'Baseline forms lack CSRF tokens' if baseline else 'All rendered forms carry current session token')
        other=client();ok,other_csrf=login(other);check(ok and other_csrf!=csrf,'Foreign authenticated session token differs')
        for action in ['create','update','delete']:
            for supplied in [None,'wrong-token','array-token',other_csrf]:
                seed();before=snapshot();post=dict(payload,action=action,id='1')
                if supplied is not None:post['csrf_token[]' if supplied=='array-token' else 'csrf_token']=supplied
                status,_,_=request(data=post,who=admin);after=snapshot()
                check(status==200 and after!=before if baseline else status==403 and after==before,'Missing or invalid token '+action,token=supplied,status=status,mutated=after!=before)
        seed()
        for action in ['create','update','delete']:
            before=snapshot();status,_,body=request(data=dict(payload,action=action,id='1',csrf_token=csrf),who=admin)
            check(status==200 and snapshot()!=before,'Valid token '+action+' succeeds',status=status)
            fs=re.findall(r'<form\b[^>]*method="POST"[^>]*>(.*?)</form>',body,re.S|re.I)
            check(len(fs)>=2 and (baseline or all(token(f)==csrf for f in fs)),'Sibling forms remain usable after '+action)
        # Rendered form tokens rather than login-token assumptions.
        if not baseline:
            for action in ['create','update','delete']:
                seed();status,_,page=request(who=admin)
                fs=re.findall(r'<form\b[^>]*method="POST"[^>]*>(.*?)</form>',page,re.S|re.I)
                f=next(f for f in fs if 'name="action" value="'+action+'"' in f)
                before=snapshot();status,_,_=request(data=dict(payload,action=action,id='1',csrf_token=token(f)),who=admin)
                check(status==200 and snapshot()!=before,'Actual rendered '+action+' token submits successfully')
        seed();before=snapshot();status,_,_=request('/admin/banners.php?action=delete&id=1',who=admin)
        check(status==200 and snapshot()==before,'GET does not dispatch a mutation')
        status,_,_=request(data={'action':'unknown','csrf_token':csrf},who=admin)
        check(status==200 and snapshot()==before,'Unknown action does not mutate')
        status,_,_=request(data={'action':'delete','id':'1','csrf_token':csrf},who=admin)
        before=snapshot();status,_,_=request(data={'action':'delete','id':'1','csrf_token':csrf},who=admin)
        check(status==200 and snapshot()==before,'Repeated delete remains harmless')
        status,_,body=request(who=admin)
        fs=re.findall(r'<form\b[^>]*method="POST"[^>]*>(.*?)</form>',body,re.S|re.I)
        check(len(fs)==2 and (baseline or all(token(f)==csrf for f in fs)),'Empty-list create and edit forms preserve tokens')
        sql("UPDATE admin_users SET is_active=0 WHERE id=1");before=snapshot();status,_,_=request(data=dict(payload,csrf_token=csrf),who=admin)
        check(status==403 and snapshot()==before,'Inactive session cannot mutate',status=status)
    finally:
        server.terminate();server.wait(timeout=10);server_log.close()
    report={'scope':'Actual auth/controller/model/CSRF on loopback with synthetic SQLite only; no production or browser exploit claim','baseline_expected_defect':baseline,'checks':len(checks),'passed':sum(c['passed'] for c in checks),'failed':[c for c in checks if not c['passed']],'results':checks}
    print(json.dumps(report,indent=2))
    if report['failed']:raise SystemExit(1)
