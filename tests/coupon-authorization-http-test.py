"""Coupon authorization regression using synthetic SQLite and a loopback PHP server only.

No production configuration, inventory, payments, emails, or external HTTP calls.
Run with PHP on PATH (PDO SQLite required); FAS_AUDIT_SOURCE may select a source copy.
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
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)


with tempfile.TemporaryDirectory(prefix='fas-coupon-authorization-') as tmp:
    base = Path(tmp)
    site = base / 'site'
    site.mkdir()
    for folder in ['admin', 'src', 'includes']:
        shutil.copytree(ROOT / folder, site / folder, ignore=shutil.ignore_patterns(
            'config.php', 'config.local.php', 'config.production.php', 'shipping.php',
            'config.json', 'settings.json', 'uploads', 'backups', 'exports', '*.db', '*.sqlite', '*.log'))
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
    env = os.environ.copy()
    env['FAS_SECURITY_DB_PATH'] = str(base / 'private/security.sqlite')
    env.pop('FAS_TRUSTED_PROXY_CIDRS', None)
    server_log = open(base / 'server.log', 'w')
    server = subprocess.Popen([PHP, '-d', 'disable_functions=mail,curl_exec,fsockopen', '-d',
        'session.save_path=' + str(base / 'sessions'), '-S', '127.0.0.1:' + str(port), '-t', str(site)],
        env=env, stdout=server_log, stderr=server_log)
    def request(path='/admin/coupons.php', data=None, who=None):
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
    operations = {
        'create': {'action':'create','code':'NEWFIXTURE','description':'Synthetic coupon','discount_type':'percentage','discount_value':'10'},
        'update': {'action':'update','id':'1','description':'Changed synthetic coupon','discount_type':'percentage','discount_value':'20'},
        'delete': {'action':'delete','id':'1'},
    }
    try:
        for _ in range(60):
            if server.poll() is not None:
                raise RuntimeError('Local PHP server exited')
            try:
                request('/admin/login.php')
                break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('Local PHP server did not start')
        reset()
        status, headers, body = request()
        check(status == 302 and headers.get('Location') == 'login.php', 'Anonymous coupon read requires sign-in', status=status)
        check('PRIVATEFIXTURE' not in body, 'Anonymous response excludes coupon data')
        for action, data in operations.items():
            reset()
            before = snapshot()
            status, _, _ = request(data=data)
            check(status == 302 and snapshot() == before, 'Anonymous ' + action + ' is denied before any coupon mutation', status=status, mutated=snapshot()!=before)
        reset()
        admin = client()
        signed_in, csrf = login(admin)
        check(signed_in, 'Real authentication accepts the synthetic active administrator')
        status, headers, body = request(who=admin)
        check(status == 200 and 'PRIVATEFIXTURE' in body, 'Active administrator can read coupons')
        check('private' in headers.get('Cache-Control','') and 'no-store' in headers.get('Cache-Control',''), 'Authenticated coupon response is not cacheable')
        forms = re.findall(r'<form\b[^>]*method="POST"[^>]*>(.*?)</form>', body, re.S | re.I)
        check(len(forms) == 2 and all(token(form) == csrf for form in forms), 'Every rendered coupon mutation form includes the session CSRF token')
        for action, data in operations.items():
            for supplied in [None, 'invalid-synthetic-token']:
                reset()
                before = snapshot()
                payload = dict(data)
                if supplied is not None:
                    payload['csrf_token'] = supplied
                status, _, _ = request(data=payload, who=admin)
                check(status == 403 and snapshot() == before, 'Authenticated ' + action + ' rejects ' + ('missing' if supplied is None else 'invalid') + ' CSRF before mutation', status=status, mutated=snapshot()!=before)
        for action, data in operations.items():
            reset()
            status, _, _ = request(data={**data,'csrf_token':csrf}, who=admin)
            rows = sql('SELECT code,description,discount_value FROM coupons ORDER BY id')
            expected = (len(rows) == 2 and rows[1][0] == 'NEWFIXTURE') if action == 'create' else ((rows == [('PRIVATEFIXTURE','Changed synthetic coupon',20.0)]) if action == 'update' else rows == [])
            check(status == 200 and expected, 'Active administrator with CSRF can ' + action, status=status)
        # SQLite can retain text in numeric-affinity fields; legacy records must not render markup.
        reset()
        markers = [f'<b data-audit="{name}">synthetic</b>' for name in ['discount','used','maximum']]
        sql('UPDATE coupons SET discount_value=?,times_used=?,max_uses=? WHERE id=1', markers)
        status, _, body = request(who=admin)
        for name, marker in zip(['discount value','usage count','maximum uses'], markers):
            check(status == 200 and marker not in body and html.escape(marker, quote=True) in body,
                'Stored ' + name + ' markup is escaped in coupon output')
        for role, active, label in [('viewer',1,'Non-admin'),('admin',0,'Inactive')]:
            sql("UPDATE admin_users SET role='admin',is_active=1 WHERE id=1")
            revoked = client()
            ok, revoked_csrf = login(revoked)
            if not ok:
                raise RuntimeError('Fixture account failed to authenticate before revocation')
            sql('UPDATE admin_users SET role=?,is_active=? WHERE id=1', [role, active])
            reset()
            before = snapshot()
            check(request(who=revoked)[0] == 403, label + ' account cannot read coupon administration')
            for action, data in operations.items():
                status, _, _ = request(data={**data,'csrf_token':revoked_csrf}, who=revoked)
                check(status == 403 and snapshot() == before, label + ' account cannot ' + action + ' with its previously valid CSRF', status=status, mutated=snapshot()!=before)
        sql("UPDATE admin_users SET role='admin',is_active=1 WHERE id=1")
        stale = client()
        ok, stale_csrf = login(stale)
        if not ok:
            raise RuntimeError('Fixture session-version authentication failed')
        sql('INSERT INTO admin_account_security(admin_id,session_version) VALUES(1,2) ON CONFLICT(admin_id) DO UPDATE SET session_version=session_version+1')
        reset()
        before = snapshot()
        status, _, _ = request(data={**operations['delete'],'csrf_token':stale_csrf}, who=stale)
        check(status == 403 and snapshot() == before, 'Revoked session version cannot delete coupons', status=status, mutated=snapshot()!=before)
    finally:
        server.terminate()
        server.wait(timeout=10)
        server_log.close()
        if not checks or not all(c['passed'] for c in checks if c['name'].startswith('Real authentication')):
            print((base / 'server.log').read_text(), file=__import__('sys').stderr)
    report = {'scope':'Synthetic loopback HTTP and disposable SQLite only','checks':len(checks),
        'passed':sum(c['passed'] for c in checks),'failed':[c for c in checks if not c['passed']], 'results':checks}
    print(json.dumps(report, indent=2))
    if report['failed']:
        raise SystemExit(1)
