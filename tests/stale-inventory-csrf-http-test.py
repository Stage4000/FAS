"""Stale-inventory CSRF and campaign normalization regression using real local auth and synthetic SQLite.
No production configuration, inventory, payments, emails or external HTTP calls.
PHP_BIN selects installed PHP; FAS_AUDIT_SOURCE selects a full source fixture.
FAS_STALE_PAGE_SOURCE optionally overlays the independently reviewed page.
STOCK_EXPECT_BASELINE=1 expects original missing-token and lowercase-code defects.
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


with tempfile.TemporaryDirectory(prefix='fas-coupon-authorization-') as tmp:
    base = Path(tmp)
    site = base / 'site'
    site.mkdir()
    for folder in ['admin', 'src', 'includes']:
        shutil.copytree(ROOT / folder, site / folder, ignore=shutil.ignore_patterns(
            'config.php', 'config.local.php', 'config.production.php', 'shipping.php',
            'config.json', 'settings.json', 'uploads', 'backups', 'exports', '*.db', '*.sqlite', '*.log'))
    if os.environ.get('FAS_STALE_PAGE_SOURCE'):
        shutil.copyfile(os.environ['FAS_STALE_PAGE_SOURCE'], site/'admin/stale-inventory.php')
    (site / 'api').mkdir()
    shutil.copyfile(ROOT/'api/validate-coupon.php', site/'api/validate-coupon.php')
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
    def reset():
        sql('DELETE FROM coupons')
        sql("INSERT INTO coupons(id,code,description,discount_type,discount_value) VALUES(1,'PRIVATEFIXTURE','Synthetic private coupon','percentage',10)")
    def snapshot():
        return sql('SELECT * FROM coupons ORDER BY id')
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
    def request(path='/admin/stale-inventory.php', data=None, who=None):
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
    baseline = os.environ.get('STOCK_EXPECT_BASELINE') == '1'
    try:
        for _ in range(60):
            if server.poll() is not None: raise RuntimeError('Local PHP server exited')
            try:
                request('/admin/login.php'); break
            except OSError: time.sleep(.05)
        else: raise RuntimeError('Local PHP server did not start')
        payload={'action':'create_coupon_campaign','discount':'5','campaign_code':'SYNTHSTOCK5','max_uses':'10','expires_at':'2030-01-01T12:00'}
        before=snapshot();status,_,_=request(data=payload)
        check(status==302 and snapshot()==before,'Anonymous mutation remains blocked',status=status)
        admin=client();ok,csrf=login(admin);check(ok,'Real authentication accepts synthetic admin')
        status,_,page=request(who=admin)
        check(status==200,'Actual stale-inventory page renders',status=status)
        check('Could not load stale inventory recommendations' not in page,'Full synthetic product schema renders recommendations')
        forms=re.findall(r'<form\b[^>]*method="POST"[^>]*>(.*?)</form>',page,re.S|re.I)
        check(len(forms)==2,'Both actual mutation form templates render')
        check(all(token(form)==csrf for form in forms) if not baseline else all(token(form) is None for form in forms),'Rendered mutation forms carry current session tokens' if not baseline else 'Baseline forms lack CSRF tokens')
        other=client();other_ok,other_csrf=login(other)
        check(other_ok and other_csrf!=csrf,'Independent authenticated session has distinct token')
        for supplied in [None,'invalid-synthetic-token','array-token',other_csrf]:
            sql('DELETE FROM coupons');before=snapshot();post=dict(payload)
            if supplied is not None:post['csrf_token[]' if supplied=='array-token' else 'csrf_token']=supplied
            status,_,_=request(data=post,who=admin);mutated=snapshot()!=before
            check((status==200 and mutated) if baseline else (status==403 and not mutated),'Missing/invalid token coupon control',token=supplied,status=status,mutated=mutated)
            sql('UPDATE products SET sale_price=NULL WHERE id=1')
            post={'action':'apply_markdowns','selected_products[]':'1','recommended_sale_price[1]':'50'}
            if supplied is not None:post['csrf_token[]' if supplied=='array-token' else 'csrf_token']=supplied
            status,_,_=request(data=post,who=admin);price=sql('SELECT sale_price FROM products WHERE id=1')[0][0]
            check((status==200 and price==50) if baseline else (status==403 and price is None),'Missing/invalid token markdown control',token=supplied,status=status,sale_price=price)
        sql('DELETE FROM coupons');post=dict(payload,csrf_token=csrf);status,_,_=request(data=post,who=admin)
        check(status==200 and len(sql("SELECT code FROM coupons WHERE code='SYNTHSTOCK5'"))==1,'Valid token coupon creation stays available',status=status)
        sql('UPDATE products SET sale_price=NULL WHERE id=1');status,_,_=request(data={'action':'apply_markdowns','selected_products[]':'1','recommended_sale_price[1]':'50','csrf_token':csrf},who=admin)
        check(status==200 and sql('SELECT sale_price FROM products WHERE id=1')[0][0]==50,'Valid token markdown stays available',status=status)
        sql('DELETE FROM coupons')
        status,_,body=request(data=dict(payload,campaign_code='spring10',csrf_token=csrf),who=admin)
        expected='10' if baseline else 'SPRING10'
        check(status==200 and sql('SELECT code FROM coupons')==[(expected,)],'Actual controller normalization preserves intended lowercase letters' if not baseline else 'Baseline lowercase letters are lost',status=status)
        before=snapshot();status,_,body=request(data=dict(payload,campaign_code='Spring10',csrf_token=csrf),who=admin)
        check((status==200 and snapshot()!=before) if baseline else (status==200 and snapshot()==before and 'already exists' in body),'Normalized duplicate follows existing duplicate path' if not baseline else 'Baseline mixed case creates a different code',status=status)
        before=snapshot();status,_,body=request(data=dict(payload,campaign_code='---',csrf_token=csrf),who=admin)
        check(status==200 and snapshot()==before and 'Enter a coupon code' in body,'Invalid normalized code preserves coupon data',status=status)
        error_forms=re.findall(r'<form\b[^>]*method="POST"[^>]*>(.*?)</form>',body,re.S|re.I)
        check(len(error_forms)==2 and (baseline or all(token(form)==csrf for form in error_forms)),'Error response preserves usable form tokens')
        status,_,body=request(data=dict(payload,campaign_code='RETRY5',csrf_token=csrf),who=admin)
        check(status==200 and sql("SELECT code FROM coupons WHERE code='RETRY5'")==[('RETRY5',)],'Valid retry after error succeeds')
        before=snapshot();status,_,body=request(data=dict(payload,campaign_code='RETRY5',csrf_token=csrf),who=admin)
        check(status==200 and snapshot()==before and 'already exists' in body,'Repeated coupon submission does not create a duplicate')
        sql('UPDATE products SET sale_price=NULL WHERE id=1')
        status,_,_=request(data={'action':'apply_markdowns','selected_products[]':'1','recommended_sale_price[1]':'120','csrf_token':csrf},who=admin)
        check(status==200 and sql('SELECT sale_price FROM products WHERE id=1')[0][0] is None,'Existing markdown price limit stays enforced')
        sql("UPDATE admin_users SET is_active=0 WHERE id=1");before=snapshot();status,_,_=request(data=dict(payload,campaign_code='INACTIVE',csrf_token=csrf),who=admin)
        check(status==403 and snapshot()==before,'Inactive session remains blocked',status=status)
    finally:
        server.terminate();server.wait(timeout=10);server_log.close()
    report={'scope':'Actual source/auth/CSRF/SQLite on loopback, synthetic data only; no cross-site browser or production exploit claim','expect_baseline_defect':baseline,'checks':len(checks),'passed':sum(c['passed'] for c in checks),'failed':[c for c in checks if not c['passed']],'results':checks}
    print(json.dumps(report,indent=2))
    if report['failed']:raise SystemExit(1)
