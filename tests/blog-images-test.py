"""Portable, isolated HTTP tests with real AdminAuth and synthetic gallery/database.
Usage: python tests/blog-images-test.py --source-root PATH_TO_FULL_SITE
The endpoint is taken from this test's sibling admin directory; no live config is used.
"""
from pathlib import Path
import argparse, base64, hashlib, http.cookiejar, json, os, shutil, socket
import sqlite3, subprocess, tempfile, time, urllib.error, urllib.request
from contextlib import contextmanager

def php_ini_string(value):
    # PHP parses -d values as INI syntax even when subprocess bypasses the shell.
    return '"' + str(value).replace('\\', '\\\\').replace('"', '\\"') + '"'

@contextmanager
def database(path):
    db = sqlite3.connect(path)
    try:
        with db:
            yield db
    finally:
        db.close()

parser = argparse.ArgumentParser()
parser.add_argument('--source-root', type=Path, default=Path(__file__).resolve().parents[1])
parser.add_argument('--force-junction', action='store_true', help='On Windows, exercise junction fallback even when symlinks are permitted')
args = parser.parse_args()
if args.force_junction and os.name != 'nt':
    parser.error('--force-junction is Windows-only; POSIX symlink checks remain mandatory')
endpoint = Path(__file__).resolve().parents[1] / 'admin/blog-images.php'
checks = []
skips = []

with tempfile.TemporaryDirectory(prefix='fas-gallery-test-') as temp:
    site = Path(temp) / 'site'
    site.mkdir()
    for relative in ['admin/auth.php', 'includes/admin-accounts.php', 'includes/security.php',
                     'src/security/ClientIp.php', 'src/security/SecurityStore.php', 'src/utils/Timezone.php']:
        dest = site / relative
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(args.source_root / relative, dest)
    shutil.copytree(args.source_root / 'src/blog', site / 'src/blog')
    shutil.copy2(endpoint, site / 'admin/blog-images.php')
    (site / 'src/config').mkdir()
    (site / 'src/config/Database.php').write_text("<?php namespace FAS\\Config; class Database {public static function getInstance(){return new self;} public function getConnection(){return new \\PDO('sqlite:'.__DIR__.'/../../fixture.sqlite');}}")
    password = subprocess.check_output(['php', '-r', 'echo password_hash("fixture-only",PASSWORD_DEFAULT);'], text=True)
    with database(site / 'fixture.sqlite') as db:
        db.execute('CREATE TABLE admin_users(id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, email TEXT, role TEXT, is_active INTEGER,last_login TEXT)')
        db.execute("INSERT INTO admin_users VALUES(1,'fixture',?,'fixture@example.invalid','admin',1,NULL)", [password])
    (site / 'login.php').write_text("<?php require __DIR__.'/admin/auth.php'; $a=new AdminAuth(); if(!$a->login('fixture','fixture-only')){http_response_code(403);exit;} echo 'ok';")
    gallery = site / 'gallery'
    gallery.mkdir()
    (gallery / 'uploads').mkdir()
    # A real 1x1 PNG; wrong extensions and fake images must not be selectable.
    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6WQAAAAASUVORK5CYII=')
    for name in ['a.png', 'b.PNG', 'uploads/c.png', 'space name.png', 'percent%20.png', 'dot..dot.png', '.hidden.png', 'wrong.jpg']:
        (gallery / name).write_bytes(png)
    (gallery / 'fake.png').write_text('not an image')
    (gallery / 'vector.svg').write_text('<svg/>')
    (gallery / 'directory.png').mkdir()
    outside = Path(temp) / 'outside'
    outside.mkdir()
    (outside / 'secret.png').write_bytes(png)
    try:
        if args.force_junction:
            raise OSError('Symlink setup deliberately skipped to exercise Windows junction fallback')
        (gallery / 'escape.png').symlink_to(outside / 'secret.png')
        (gallery / 'outside').symlink_to(outside, target_is_directory=True)
        (gallery / 'alias.png').symlink_to(gallery / 'a.png')
    except OSError as error:
        if os.name != 'nt':
            raise
        skips.append('File/directory symlink checks: ' + str(error))
        junction_env = dict(os.environ, FAS_TEST_JUNCTION=str(gallery / 'junction'), FAS_TEST_TARGET=str(outside))
        # Paths are environment data, never interpolated PowerShell source or trailing -Command args.
        try:
            result = subprocess.run(['powershell', '-NoProfile', '-NonInteractive', '-Command',
                                     "$ErrorActionPreference='Stop'; New-Item -ItemType Junction -Path $env:FAS_TEST_JUNCTION -Target $env:FAS_TEST_TARGET | Out-Null"],
                                    env=junction_env, capture_output=True, text=True, timeout=20)
        except (OSError, subprocess.TimeoutExpired) as error:
            skips.append('Windows junction setup unavailable: ' + str(error))
        else:
            if result.returncode:
                skips.append('Windows junction setup failed (exit %s): %s' % (result.returncode, result.stderr.strip()))
            else:
                assert (gallery / 'junction').resolve() == outside.resolve(), 'Junction must resolve to the exact external fixture'
                checks.append('Windows junction fallback resolves to the external fixture')
    sessions = Path(temp) / 'sessions'
    sessions.mkdir()
    env = {k: v for k, v in os.environ.items() if k.upper() in ['PATH','SYSTEMROOT','WINDIR','TEMP','TMP','COMSPEC','PATHEXT']}
    env['FAS_SECURITY_DB_PATH'] = str(Path(temp) / 'security.sqlite')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    log = open(Path(temp) / 'server.log', 'w')
    session_setting = 'session.save_path=' + php_ini_string(sessions)
    actual_session_path = subprocess.check_output(
        ['php', '-d', session_setting, '-r', 'echo ini_get("session.save_path");'], text=True, env=env)
    assert actual_session_path == str(sessions), 'PHP must preserve the exact session path'
    checks.append('PHP preserves the exact quoted session path')
    server = subprocess.Popen(['php', '-d', session_setting, '-d', 'session.name=FASGALLERYTEST' + os.urandom(8).hex(),
                               '-d', 'allow_url_fopen=0',
                               '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open',
                               '-S', f'127.0.0.1:{port}', '-t', str(site)], stdout=log, stderr=log, env=env)
    admin = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    anon = urllib.request.build_opener()
    def request(path='/admin/blog-images.php', who=admin, method='GET'):
        try:
            response = who.open(urllib.request.Request(f'http://127.0.0.1:{port}' + path, method=method), timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, dict(response.headers), response.read().decode()
    def check(value, label):
        assert value, label
        checks.append(label)
    try:
        for _ in range(60):
            try:
                status, _, body = request(who=anon)
                break
            except OSError:
                time.sleep(.1)
        check(status == 403 and 'images' not in json.loads(body), 'Anonymous denied without gallery disclosure')
        check(request('/login.php')[0] == 200, 'Real admin authentication succeeds')
        status, headers, body = request()
        data = json.loads(body)
        check(status == 200, 'Active admin can browse without installed blog storage')
        check(headers.get('Cache-Control') == 'private, no-store' and headers.get('X-Robots-Tag') == 'noindex, nofollow', 'Private response headers')
        check([i['url'] for i in data['images']] == ['/gallery/a.png','/gallery/b.PNG','/gallery/uploads/c.png'], 'Only safe contained real images with model-compatible paths')
        check(all(i['width'] == i['height'] == 1 for i in data['images']), 'Image dimensions returned')
        check(str(site) not in body and str(outside) not in body, 'No absolute filesystem disclosure')
        data = json.loads(request('/admin/blog-images.php?limit=1&offset=1')[2])
        check(data['images'][0]['url'] == '/gallery/b.PNG' and data['hasMore'], 'Stable pagination')
        check(not json.loads(request('/admin/blog-images.php?offset=3')[2])['hasMore'], 'Pagination end')
        check(len(json.loads(request('/admin/blog-images.php?q=UPLOADS')[2])['images']) == 1, 'Case-insensitive literal search')
        for query in ['q=..%2Foutside', 'q=%25', 'q=%3Cscript%3E', 'q=%E2%98%83']:
            check(json.loads(request('/admin/blog-images.php?' + query)[2])['images'] == [], 'Safe literal search: ' + query)
        for query in ['q[]=a','q=%FF','q=%00','q=' + 'a'*101,'limit=0','limit=81','limit[]=2','offset=-1','offset=10001','offset[]=1']:
            check(request('/admin/blog-images.php?' + query)[0] == 400, 'Invalid input rejected: ' + query[:25])
        check(request(method='POST')[0] == 405, 'Writes are not supported')
        before = {p.relative_to(gallery).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest() for p in gallery.rglob('*') if p.is_file()}
        request()
        after = {p.relative_to(gallery).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest() for p in gallery.rglob('*') if p.is_file()}
        check(before == after, 'Gallery unchanged by listing')
        with database(site / 'fixture.sqlite') as db:
            check(db.execute("SELECT count(*) FROM sqlite_master WHERE name LIKE 'blog_%'").fetchone()[0] == 0, 'No blog schema created')
            db.execute('UPDATE admin_users SET is_active=0 WHERE id=1')
        check(request()[0] == 403, 'Disabled admin session denied')
        with database(site / 'fixture.sqlite') as db:
            db.execute("UPDATE admin_users SET is_active=1,role='editor' WHERE id=1")
        check(request()[0] == 403, 'Non-admin role denied')
        with database(site / 'fixture.sqlite') as db:
            db.execute("UPDATE admin_users SET role='admin' WHERE id=1")
            db.execute('INSERT INTO admin_account_security(admin_id,session_version) VALUES(1,2) ON CONFLICT(admin_id) DO UPDATE SET session_version=session_version+1')
        check(request()[0] == 403, 'Revoked session denied')
        check(request('/login.php')[0] == 200, 'Fresh login succeeds')
        deep = gallery
        for _ in range(10):
            deep = deep / 'deep'
            deep.mkdir()
        (deep / 'too-deep.png').write_bytes(png)
        check(json.loads(request()[2])['truncated'], 'Depth bound disclosed')
        for index in range(10010):
            (gallery / ('limit-%05d.txt' % index)).write_bytes(b'')
        bounded = json.loads(request('/admin/blog-images.php?limit=1')[2])
        check(bounded['truncated'] and len(bounded['images']) <= 1, 'Scan and response bounds enforced')
        gallery.rename(site / 'gallery-away')
        check(request()[0] == 503, 'Missing gallery returns generic unavailable')
        try:
            gallery.symlink_to(site / 'gallery-away', target_is_directory=True)
        except OSError as error:
            if os.name != 'nt':
                raise
            skips.append('Gallery-root symlink unavailable: ' + str(error))
        else:
            check(request()[0] == 503, 'Aliased gallery root rejected')
            gallery.unlink()
        print(json.dumps({'passed': len(checks), 'checks': checks, 'skips': skips}, indent=2))
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()
