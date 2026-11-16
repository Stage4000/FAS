"""Real multipart/PHP-upload/session boundary tests. Loopback, synthetic data only.
Uses stdlib and the installed PHP CLI. Does not open a browser or load app config.
"""
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
assert PHP, 'An installed PHP CLI with GD, EXIF and PDO_SQLite is required'
checks = 0


def check(condition, label):
    global checks
    assert condition, label
    checks += 1


def multipart(fields, image, filename='photo.png', mime='image/png', extra=False):
    boundary = 'showcase-fixture-' + secrets.token_hex(16)
    chunks = []
    for name, value in fields.items():
        chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    if image is not None:
        chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="photo"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + image + b'\r\n')
    if extra:
        chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="extra"; filename="extra.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + image + b'\r\n')
    chunks.append(f'--{boundary}--\r\n'.encode())
    return b''.join(chunks), 'multipart/form-data; boundary=' + boundary


with tempfile.TemporaryDirectory(prefix='.showcase-http-', dir=ROOT / 'tests') as tmp:
    temp = Path(tmp).resolve()
    assert temp.parent == (ROOT / 'tests').resolve() and temp.name.startswith('.showcase-http-')
    # Only this newly created synthetic workspace is removed by TemporaryDirectory.
    db = temp / 'fixture.sqlite'
    repo = (ROOT / 'src/showcase/ShowcaseRepository.php').as_posix()
    code = f"require {json.dumps(repo)}; $db=new PDO('sqlite:'.getenv('FAS_SHOWCASE_FIXTURE_DB'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $db->exec('PRAGMA foreign_keys=ON'); \\FAS\\Showcase\\ShowcaseRepository::initializeSchema($db);"
    key = secrets.token_hex(32)
    env = dict(os.environ, FAS_SHOWCASE_FIXTURE_KEY=key, FAS_SHOWCASE_FIXTURE_DB=str(db))
    flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
    subprocess.run([PHP, '-r', code], env=env, check=True, capture_output=True, creationflags=flags)
    (temp/'public').mkdir();(temp/'slot').write_text('FAS-SHOWCASE-SLOT-v1\n')
    rate=(ROOT/'src/showcase/ShowcaseRateAdmission.php').as_posix()
    provision='require '+json.dumps(rate)+'; $d=new PDO("sqlite:".$argv[1]); $d->exec(FAS\\Showcase\\ShowcaseRateAdmission::SCHEMA); $d->exec(FAS\\Showcase\\ShowcaseRateAdmission::STATE_SCHEMA); $d->prepare("INSERT INTO showcase_rate_state VALUES(1,?,0,0,0)")->execute([FAS\\Showcase\\ShowcaseRateAdmission::binding(str_repeat("x",32),4,10,20,100)]);'
    subprocess.run([PHP,'-r',provision,str(temp/'quota.sqlite')],check=True,capture_output=True)
    png = subprocess.run([PHP, '-r', '$i=imagecreatetruecolor(12,8); imagefilledrectangle($i,0,0,11,7,0x346789); imagepng($i);'], check=True, capture_output=True, creationflags=flags).stdout
    with socket.socket() as reserve:
        reserve.bind(('127.0.0.1', 0))
        port = reserve.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def call(path, data=None, media=None, extra=None, client=opener, method=None, auth=True):
        headers = {'X-Showcase-Fixture': key} if auth else {}
        if media:
            headers['Content-Type'] = media
        headers.update(extra or {})
        request = urllib.request.Request(base + path, data=data, headers=headers, method=method)
        try:
            response = client.open(request, timeout=12)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            raw = response.read()
            media_type = response.headers.get_content_type()
            result = json.loads(raw) if raw and media_type == 'application/json' else (raw if media_type == 'image/jpeg' else raw.decode())
            return response.status, result, response.headers

    with (temp / 'server.log').open('wb') as log:
        server = subprocess.Popen([PHP, '-d', 'file_uploads=1', '-d', 'upload_max_filesize=6M', '-d', 'post_max_size=7M',
            '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', f'upload_tmp_dir={temp}', '-d', f'session.save_path={temp}',
            '-S', f'127.0.0.1:{port}', str(ROOT / 'tests/showcase-application-router.php')],
            cwd=temp, env=env, stdout=log, stderr=log, creationflags=flags)
        try:
            deadline = time.monotonic() + 10
            while True:
                try:
                    status, tokens, _ = call('/fixture/bootstrap')
                    break
                except urllib.error.URLError:
                    if server.poll() is not None or time.monotonic() > deadline:
                        raise RuntimeError('Fixture server failed to start')
                    time.sleep(.05)
            import re, sqlite3, fcntl
            check(status==200,'GET composed')
            t={k:re.search('name="'+k+'" value="([a-f0-9]+)"',tokens).group(1) for k in ['csrf_token','form_id']}
            fields=dict(t,display_name='Synthetic',email='private@example.invalid',caption='test',consent='1');data,media=multipart(fields,png)
            status,body,headers=call('/submit',data,media);check(status==200 and 'Your submission is saved' in body,'Committed')
            check(call('/submit',data,media)[1]==body,'Retry same receipt')
            changed,mt=multipart(dict(fields,caption='different'),png);check(call('/submit',changed,mt)[0]==409,'Conflict')
            check(call('/submit',data,media)[0]==429,'Rate denied before decode')
            with sqlite3.connect(temp/'quota.sqlite') as q:check(q.execute('select used from showcase_rate_state').fetchone()[0]==4,'Exactly four charges no double charge')
            with sqlite3.connect(db) as q:check(q.execute('select count(*) from showcase_submissions').fetchone()[0]==1,'One saved record')
            stranger=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            denied=call('/',extra={'X-Enabled':'truthy'},client=stranger);check(denied[0]==404 and denied[2]['X-Fixture-Has-Pool']=='no','Disabled before issuance')
            with (temp/'slot').open('r+b') as held:
                fcntl.flock(held,fcntl.LOCK_EX|fcntl.LOCK_NB)
                busy=call('/submit',data,media,client=stranger)
                check(busy[0]==429 and busy[2]['X-Fixture-Has-Pool']=='no','Busy slot blocks full POST')
                with sqlite3.connect(temp/'quota.sqlite') as q:check(q.execute('select used from showcase_rate_state').fetchone()[0]==4,'Busy slot does not charge quota')
            cross=call('/submit',data,media,client=stranger);check(cross[0]==400 and cross[2]['X-Fixture-Has-Pool']=='no','POST no allocation')
            check(headers['Cache-Control']=='private, no-store','No-store')
            print(f'Application native HTTP: {checks} assertions passed.')
        finally:
            server.terminate();server.wait(timeout=5)
