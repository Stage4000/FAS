"""Sale schedule regression through real local auth/CSRF and synthetic SQLite.
No production config, customer data, payments, email or external HTTP calls.
PHP_BIN selects PHP; FAS_AUDIT_SOURCE selects a complete source fixture.
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
    candidate = ROOT
    shutil.copyfile(candidate/'admin/sale.php', site/'admin/sale.php')
    shutil.copyfile(candidate/'includes/sale-helper.php', site/'includes/sale-helper.php')
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
    def request(path='/admin/sale.php', data=None, who=None):
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
    config_path=site/'src/config/config.php'
    config_base=config_path.read_text()
    sale={'enabled':True,'type':'percentage','value':20,'label':'FIXTURE','starts_at':None,'ends_at':None}
    def seed(**updates):
        data=dict(sale,**updates)
        config_path.write_text(config_base.replace("'security'=>", "'sale'=>json_decode("+json.dumps(json.dumps(data))+",true),'sentinel'=>'keep','security'=>"))
    def config():
        return json.loads(subprocess.check_output([PHP,'-r','echo json_encode(require $argv[1]);',str(config_path)],text=True))
    try:
        for _ in range(60):
            if server.poll() is not None: raise RuntimeError('Local PHP server exited')
            try: request('/admin/login.php'); break
            except OSError: time.sleep(.05)
        else: raise RuntimeError('Local PHP server did not start')
        seed();before=config_path.read_bytes()
        status,_,_=request(data={'enabled':'1','value':'20'})
        check(status==302 and config_path.read_bytes()==before,'Anonymous POST cannot alter config',status=status)
        admin=client();ok,csrf=login(admin);check(ok,'Real AdminAuth login succeeds with synthetic admin')
        status,_,body=request(who=admin);check(status==200 and token(body)==csrf,'Real sale form provides session CSRF token',status=status)
        payload={'enabled':'1','type':'percentage','value':'20','label':'FIXTURE','starts_at':'2027-01-01T00:00','ends_at':''}
        other=client();ok,foreign=login(other);check(ok and foreign!=csrf,'Second session has distinct token')
        for supplied in [None,'bad-token',foreign]:
            seed();before=config_path.read_bytes();post=dict(payload)
            if supplied is not None:post['csrf_token']=supplied
            status,_,body=request(data=post,who=admin)
            check(status==200 and 'Invalid security token.' in body and config_path.read_bytes()==before,'Invalid/missing/foreign CSRF rejects unchanged',status=status)
        for field in ['starts_at','ends_at']:
            for value in ['not-a-date','0','array']:
                seed(starts_at='2027-01-01 00:00:00');before=config_path.read_bytes();post=dict(payload,csrf_token=csrf)
                if value=='array':post.pop(field,None);post[field+'[]']='bad'
                else:post[field]=value
                status,_,body=request(data=post,who=admin)
                check(status==200 and 'Invalid &quot;' in body and config_path.read_bytes()==before,'Invalid '+field+' '+value+' preserves exact config',status=status)
        for value in ['', '   ', '2027-01-01T00:00']:
            seed();post=dict(payload,csrf_token=csrf,starts_at=value)
            status,_,body=request(data=post,who=admin);saved=config()
            expected='2027-01-01 08:00:00' if value.strip() else None
            check(status==200 and 'Sale settings saved successfully!' in body and saved['sale']['starts_at']==expected and saved['sentinel']=='keep','Valid or blank date saves intended UTC and preserves unrelated configuration',status=status)
        import datetime
        # Actual clock, one-hour margins rather than fragile exact-second HTTP assertions.
        past=(datetime.datetime.now(datetime.timezone.utc)-datetime.timedelta(hours=1)).strftime('%Y-%m-%d %H:%M:%S')
        future=(datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(hours=1)).strftime('%Y-%m-%d %H:%M:%S')
        for fields,expected in [({'starts_at':past},True),({'ends_at':past},False),({'starts_at':future},False),({'ends_at':future},True)]:
            seed(**fields);status,_,body=request(who=admin)
            check(status==200 and ('Sale is LIVE' in body)==expected,'Pacific admin UTC status '+str(fields),status=status)
        for start,end,rejected in [('2027-01-02T00:00','2027-01-01T00:00',True),('2027-01-01T00:00:01','2027-01-01T00:00:00',True),('2027-01-01T00:00','2027-01-01T00:00',False),('2027-01-01T00:00','2027-01-02T00:00',False),('','2027-01-01T00:00',False),('2027-01-01T00:00','',False),('','',False)]:
            seed();before=config_path.read_bytes()
            status,_,body=request(data=dict(payload,csrf_token=csrf,starts_at=start,ends_at=end),who=admin)
            if rejected:check(status==200 and 'End time must not be earlier than start time.' in body and config_path.read_bytes()==before,'Reversed interval rejects without write '+start+' / '+end,status=status)
            else:check(status==200 and 'Sale settings saved successfully!' in body and config()['sentinel']=='keep','Equal/valid/open interval remains accepted '+start+' / '+end,status=status)
        sql('UPDATE admin_users SET is_active=0 WHERE id=1');before=config_path.read_bytes()
        status,_,_=request(data=dict(payload,csrf_token=csrf),who=admin)
        check(status==403 and config_path.read_bytes()==before,'Revoked admin cannot mutate config',status=status)
    finally:
        server.terminate();server.wait(timeout=10);server_log.close()
    report={'scope':'Real localhost HTTP AdminAuth and CSRF with synthetic SQLite/config only; provider networking disabled; no browser or production acceptance', 'passed':sum(x['passed'] for x in checks),'total':len(checks),'checks':checks}
    print(json.dumps(report,indent=2))
    if report['passed']!=report['total']:raise SystemExit(1)
