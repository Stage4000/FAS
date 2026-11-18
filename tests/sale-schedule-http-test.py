"""Sale schedule regression through real local auth/CSRF and synthetic SQLite.
No production config, customer data, payments, email or external HTTP calls.
PHP_BIN selects PHP; FAS_AUDIT_SOURCE selects a complete source fixture.
"""
from contextlib import closing
from html.parser import HTMLParser
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


class ScheduleFields(HTMLParser):
    names = {'starts_at', 'ends_at', 'original_starts_at', 'original_ends_at', 'schedule_timezone'}

    def __init__(self, markup):
        super().__init__()
        self.values = {}
        self.feed(markup)

    def handle_starttag(self, tag, attrs):
        fields = dict(attrs)
        name = fields.get('name')
        if tag == 'input' and name in self.names:
            self.values[name] = fields.get('value', '')


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
    (site / 'fixture-sale-readback.php').write_text(
        "<?php require __DIR__ . '/includes/sale-helper.php'; echo json_encode(getSaleConfig());\n",
        encoding='utf-8',
    )
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
    with closing(sqlite3.connect(dbfile)) as db, db:
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
        with closing(sqlite3.connect(dbfile)) as db, db:
            return db.execute(query, args).fetchall()
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    origin = 'http://127.0.0.1:' + str(port)
    env = {key: os.environ[key] for key in ('PATH', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP') if key in os.environ}
    env['FAS_SECURITY_DB_PATH'] = str(base / 'private/security.sqlite')
    env.pop('FAS_TRUSTED_PROXY_CIDRS', None)
    server_log = open(base / 'server.log', 'w')
    server = subprocess.Popen([PHP, '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect', '-d', 'allow_url_fopen=0',
        '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=60',
        '-d', 'opcache.file_update_protection=0', '-d',
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
        for start, end in [
            ('2027-04-20 16:30:47', '2027-04-21 00:45:18'),
            ('2026-11-01 08:30:00', '2026-11-01 09:30:00'),
        ]:
            seed(starts_at=start, ends_at=end)
            status, _, body = request(who=admin)
            fields = ScheduleFields(body).values
            check(status == 200 and set(fields) == ScheduleFields.names
                  and fields.get('schedule_timezone') == 'America/Los_Angeles',
                  'Rendered form gives every date its original value and named time zone')
            status, _, body = request(data=dict(payload, csrf_token=csrf, label='New label', **fields), who=admin)
            saved = config()['sale']
            check(status == 200 and saved['label'] == 'New label'
                  and (saved['starts_at'], saved['ends_at']) == (start, end),
                  'Changing a label preserves both original UTC instants '+start)

        # An untouched field in an older form must keep another admin's later schedule.
        seed(starts_at='2027-01-01 10:00:00', ends_at='2027-01-03 10:00:00')
        fields = ScheduleFields(request(who=admin)[2]).values
        seed(starts_at='2027-01-02 10:00:17', ends_at='2027-01-03 10:00:00')
        request(data=dict(payload, csrf_token=csrf, label='Edited', **fields), who=admin)
        check(config()['sale']['starts_at'] == '2027-01-02 10:00:17',
              'Unchanged date in older form preserves newer saved schedule')

        for key in ('starts_at', 'ends_at'):
            schedule = {'starts_at': '2027-01-01 10:00:17', 'ends_at': '2027-01-03 10:00:42'}
            seed(**schedule)
            fresh = ScheduleFields(request(who=admin)[2]).values
            older = ScheduleFields(request(who=other)[2]).values
            original_value = older[key]
            fresh[key] = ''
            status, _, body = request(data=dict(payload, csrf_token=csrf, **fresh), who=admin)
            schedule[key] = None
            check(status == 200 and original_value and 'Sale settings saved successfully!' in body
                  and config()['sale'][key] is None, 'First administrator clears ' + key)
            status, _, body = request(data=dict(payload, csrf_token=foreign, label='Older form label', **older), who=other)
            saved = config()['sale']
            check(status == 200 and 'Sale settings saved successfully!' in body and saved['label'] == 'Older form label'
                  and all(saved[date] == value for date, value in schedule.items()),
                  'Older form saves its label and preserves the cleared ' + key)

        seed(starts_at=past, ends_at=future, label='Before save')
        fields = ScheduleFields(request(who=admin)[2]).values
        status, _, body = request('/fixture-sale-readback.php')
        initial = json.loads(body)
        check(status == 200 and initial and initial['label'] == 'Before save' and initial['ends_at'] == future,
              'Storefront helper reads the initial active sale')
        fields['ends_at'] = ''
        request(data=dict(payload, csrf_token=csrf, label='After save', **fields), who=admin)
        status, _, body = request('/fixture-sale-readback.php')
        updated = json.loads(body)
        check(status == 200 and updated and updated['label'] == 'After save' and updated['ends_at'] is None,
              'Storefront helper observes the new sale immediately despite the cache revalidation interval')

        fields = ScheduleFields(request(who=admin)[2]).values
        fields.update(starts_at='2027-01-02T12:00', ends_at='', schedule_timezone='Asia/Kolkata')
        request(data=dict(payload, csrf_token=csrf, **fields), who=admin)
        saved = config()['sale']
        check(saved['starts_at'] == '2027-01-02 06:30:00' and saved['ends_at'] is None,
              'Edited local time uses the form time zone and explicitly clearing an end works')
        for updates in [
            {'starts_at': '2027-03-14T02:15'},  # Pacific spring clock gap
            {'starts_at': '2027-02-30T08:00'},
            {'schedule_timezone': 'Unknown/Zone'},
        ]:
            fields = ScheduleFields(request(who=admin)[2]).values
            fields.update(updates)
            before = config_path.read_bytes()
            status, _, body = request(data=dict(payload, csrf_token=csrf, **fields), who=admin)
            check(status == 200 and config_path.read_bytes() == before
                  and ('Invalid "Starts At"' in html.unescape(body) or 'confirm its time zone' in body),
                  'Invalid schedule leaves the saved configuration intact '+str(updates))
        sql('UPDATE admin_users SET is_active=0 WHERE id=1');before=config_path.read_bytes()
        status,_,_=request(data=dict(payload,csrf_token=csrf),who=admin)
        check(status==403 and config_path.read_bytes()==before,'Revoked admin cannot mutate config',status=status)
    finally:
        server.terminate();server.wait(timeout=10);server_log.close()
    report={'scope':'Real localhost HTTP AdminAuth and CSRF with synthetic SQLite/config only; provider networking disabled; no browser or production acceptance', 'passed':sum(x['passed'] for x in checks),'total':len(checks),'checks':checks}
    print(json.dumps(report,indent=2))
    if report['passed']!=report['total']:raise SystemExit(1)
