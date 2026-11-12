"""Banner date validation through real local auth/CSRF and synthetic SQLite.
No production config, customer data, payments, email or external HTTP calls.
PHP_BIN selects PHP; FAS_AUDIT_SOURCE selects a complete source fixture.
FAS_BANNER_PAGE_SOURCE optionally selects the banner controller under test.
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
    if True:
        shutil.copyfile(os.environ.get('FAS_BANNER_PAGE_SOURCE',str(ROOT/'admin/banners.php')), site/'admin/banners.php')
    (site / 'database').mkdir()
    (site / 'admin/css').mkdir(exist_ok=True)
    (site / 'admin/css/admin-notifications.css').touch()
    (site / 'public/js').mkdir(parents=True)
    (site / 'public/js/timezone.js').touch()
    (base / 'sessions').mkdir()
    (site / 'src/config/config.php').write_text("<?php return ['database'=>['path'=>__DIR__.'/../../database/test.sqlite'],'site'=>['timezone'=>'America/Los_Angeles'],'security'=>['admin_password_salt'=>'synthetic-only']];\n")
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
    try:
        for _ in range(60):
            if server.poll() is not None:raise RuntimeError('Local PHP server exited')
            try:request('/admin/login.php');break
            except OSError:time.sleep(.05)
        else:raise RuntimeError('Local PHP server did not start')
        admin=client();ok,csrf=login(admin);check(ok,'Real AdminAuth accepts synthetic admin')
        status,_,body=request(who=admin);check(status==200 and token(body)==csrf,'Actual form supplies session CSRF')
        def seed():
            sql('DELETE FROM banners')
            sql("INSERT INTO banners(id,message,starts_at,ends_at,countdown_end) VALUES(1,'Preserve exact row','2027-01-01 08:00:00','2027-01-02 08:00:00','2027-01-02 08:00:00')")
        def snapshot():return sql('SELECT * FROM banners ORDER BY id')
        payload={'message':'Synthetic changed banner','bg_color':'info','text_color':'white','is_active':'1','csrf_token':csrf,'id':'1'}
        seed();before=snapshot();status,_,_=request(data=dict(payload,action='update'))
        check(status==302 and snapshot()==before,'Anonymous mutation blocked')
        for action in ['create','update']:
            for field in ['starts_at','ends_at','countdown_end']:
                for value in ['not-a-date','0','array']:
                    seed();before=snapshot();post=dict(payload,action=action)
                    post[field+'[]' if value=='array' else field]='bad' if value=='array' else value
                    status,_,body=request(data=post,who=admin)
                    check(status==200 and snapshot()==before and 'Invalid date/time' in body,'Reject '+action+' '+field+' '+value,status=status)
            for start,end,bad in [('2027-01-02T00:00','2027-01-01T00:00',True),('2027-01-01T00:00:01','2027-01-01T00:00',True),('2027-01-01T00:00','2027-01-01T00:00',False),('2027-01-01T00:00','2027-01-02T00:00',False),('','2027-01-01T00:00',False),('2027-01-01T00:00','',False),('','',False)]:
                seed();before=snapshot();status,_,body=request(data=dict(payload,action=action,starts_at=start,ends_at=end,countdown_end=''),who=admin)
                check(status==200 and (snapshot()==before if bad else snapshot()!=before) and ('End time must not' in body if bad else 'successfully!' in body),'Interval '+action+' '+str((start,end,bad)),status=status)
            for supplied in [None,'bad-token']:
                seed();before=snapshot();post=dict(payload,action=action,starts_at='bad');post.pop('csrf_token')
                if supplied:post['csrf_token']=supplied
                status,_,_=request(data=post,who=admin);check(status==403 and snapshot()==before,'CSRF precedes '+action+' validation')
            seed();status,_,body=request(data=dict(payload,action=action,starts_at='2027-01-01T00:00',ends_at='2027-01-02T08:00:00Z',countdown_end='2027-01-02T00:00:00-08:00'),who=admin)
            row=sql('SELECT starts_at,ends_at,countdown_end FROM banners ORDER BY id DESC LIMIT 1')[0]
            check(status==200 and row==('2027-01-01 08:00:00','2027-01-02 08:00:00','2027-01-02 08:00:00'),'User local and explicit offsets store UTC '+action,row=row)
        seed();status,_,body=request(data=dict(payload,action='delete',starts_at='bad',ends_at='0',countdown_end='bad'),who=admin)
        check(status==200 and snapshot()==[],'Delete ignores irrelevant invalid date payload')
        seed();sql('UPDATE admin_users SET is_active=0 WHERE id=1');before=snapshot();status,_,_=request(data=dict(payload,action='update'),who=admin)
        check(status==403 and snapshot()==before,'Revoked admin cannot modify banner')
    finally:
        server.terminate();server.wait(timeout=10);server_log.close()
    report={'scope':'Actual localhost HTTP/auth/CSRF with disposable SQLite/config, no production/provider/browser actions','passed':sum(c['passed'] for c in checks),'total':len(checks),'checks':checks}
    print(json.dumps(report,indent=2))
    if report['passed']!=report['total']:raise SystemExit(1)
