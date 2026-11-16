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
            '-S', f'127.0.0.1:{port}', str(ROOT / 'tests/showcase-http-router.php')],
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
            check(status == 200, 'Synthetic fixture started')
            check(call('/fixture/bootstrap', auth=False)[0] == 404, 'Fixture inaccessible without ephemeral key')
            fields = dict(csrf_token=tokens['csrf'], form_id=tokens['form'], display_name='Fixture <script>alert(1)</script>', email='person@example.invalid', caption='Synthetic project\r\nSecond paragraph', consent='1')

            def submit(overrides=None, image=png, headers=None, **kwargs):
                body, media = multipart(dict(fields, **(overrides or {})), image, **kwargs)
                return call('/submit', body, media, headers)

            check(call('/form')[0] == 200 and 'name="photo"' in call('/form')[1], 'Intake form served only by fixture')
            check(call('/submit')[0] == 400, 'GET cannot accept')
            for change in [{'csrf_token': 'bad'}, {'form_id': 'bad'}, {'consent': '0'}, {'consent[]': '1'}, {'actor_id': '7'}, {'email': 'invalid'}, {'display_name': ''}]:
                check(submit(change)[0] == 400, f'Invalid multipart fields rejected: {list(change)}')
            check(submit(image=None)[0] == 400, 'Missing native upload rejected')
            check(submit(image=b'<?php echo "not pixels"; ?>', filename='photo.jpg')[0] == 400, 'Disguised executable rejected')
            check(submit(headers={'X-Fixture-Gate': 'deny'})[0] == 400, 'Admission denial fails closed')
            check(submit(headers={'X-Fixture-File': 'path'})[0] == 400, 'Local file not accepted as HTTP upload')
            check(submit(headers={'X-Fixture-File': 'size'})[0] == 400, 'Native upload length verified')
            check(submit(extra=True)[0] == 400, 'Only one file allowed')
            check(call('/fixture/counts')[1] == {'submissions': 0, 'audit': 0}, 'No invalid request stores any data')
            status, first, headers = submit(filename='../../shell.php', mime='application/x-php')
            check(status == 200 and first['status'] == 'pending_review' and first['version'] == 1, 'Real upload committed pending')
            check(set(first) == {'id','status','version','accepted_at'}, 'Receipt contains no PII/photo/consent')
            check(headers.get('Cache-Control') == 'private, no-store' and headers.get('X-Content-Type-Options') == 'nosniff', 'Private fixture response headers')
            check(submit()[1] == first, 'Lost-response replay returns exact committed receipt')
            check(submit({'caption': 'Synthetic project\nSecond paragraph'})[1] == first, 'Equivalent LF retry returns same committed receipt')
            check(submit({'caption': 'Synthetic project\rSecond paragraph'})[1] == first, 'Equivalent CR retry returns same committed receipt')
            check(submit({'caption': 'changed'})[0] == 409, 'Conflicting same-form retry rejected')
            check(call('/fixture/counts')[1]['submissions'] == 1, 'Duplicate/conflict did not insert')
            publication = {'X-Fixture-Publication': 'allow'}
            check(call('/gallery', extra=publication)[1] == [], 'Pending photo absent from gallery')
            check(call('/gallery-photo?id=' + first['id'], extra=publication)[0] == 404, 'Pending photo not publicly served')
            status, queue_html, _ = call('/queue-view')
            check(status == 200 and first['id'] in queue_html and fields['email'] not in queue_html and '<script>' not in queue_html, 'Private escaped queue renders')
            status, review_html, _ = call('/review-view?id=' + first['id'])
            check(status == 200 and fields['email'] in review_html and 'name="photo_sha256"' in review_html and 'value="approved"' in review_html, 'Private review renders exact controls')
            check(call('/private-photo?id=' + first['id'])[0] == 200, 'Current admin sees normalized private photo')
            other = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            call('/fixture/bootstrap', client=other)
            body, media = multipart(fields, png)
            check(call('/submit', body, media, client=other)[0] == 400, 'Tokens cannot cross sessions')
            status, row, _ = call('/review?id=' + first['id'])
            check(status == 200 and row['contact_email'] == fields['email'], 'Private review retains contact')
            check(row['caption'] == 'Synthetic project\nSecond paragraph', 'Multipart multiline caption stored canonically')
            check(row['photo_sha256'] == row['photo_sha256_actual'] and row['photo_sha256'] != hashlib.sha256(png).hexdigest(), 'Stored photo is verified normalized derivative')
            check(row['consent_text'] == 'Synthetic permission fixture. Not approved legal wording.', 'Server-owned consent archived')
            review = dict(csrf_token=tokens['csrf'], id=first['id'], version='1', photo_sha256=row['photo_sha256'], consent_text_sha256=row['consent_text_sha256'], decision='approved', reason='Synthetic review')

            def moderate(change=None):
                body = urllib.parse.urlencode(dict(review, **(change or {}))).encode()
                return call('/moderate', body, 'application/x-www-form-urlencoded')

            check(moderate({'csrf_token': 'bad'})[0] == 400, 'Moderation CSRF required')
            check(moderate({'actor_id': '7'})[0] == 400, 'Cannot supply own actor')
            call('/fixture/revoke')
            check(moderate()[0] == 400 and call('/review?id=' + first['id'])[0] == 400, 'Revoked admin cannot review or mutate')
            check(call('/queue-view')[0] == 400 and call('/review-view?id=' + first['id'])[0] == 400 and call('/private-photo?id=' + first['id'])[0] == 400, 'Revoked admin loses all review surfaces')
            call('/fixture/restore')
            check(moderate({'photo_sha256': '0' * 64})[0] == 409, 'Approval binds reviewed pixels')
            check(moderate({'consent_text_sha256': '0' * 64})[0] == 409, 'Approval binds reviewed permission')
            check(moderate()[1]['status'] == 'approved', 'Authenticated current snapshot approved')
            check(call('/gallery')[1] == [] and call('/gallery-photo?id=' + first['id'])[0] == 404, 'Approval alone does not enable publication')
            gallery = call('/gallery', extra=publication)[1]
            check(len(gallery) == 1 and set(gallery[0]) == {'id','display_name','caption'}, 'Enabled gallery exposes only explicit public projection')
            gallery_html = call('/gallery-view', extra=publication)[1]
            check('&lt;script&gt;' in gallery_html and '<script>' not in gallery_html and fields['email'] not in gallery_html, 'Gallery safely renders untrusted caption/name without email')
            status, image, photo_headers = call('/gallery-photo?id=' + first['id'], extra=publication)
            check(status == 200 and hashlib.sha256(image).hexdigest() == row['photo_sha256'], 'Enabled public photo matches approved derivative')
            check(photo_headers['Cache-Control'] == 'private, no-store' and photo_headers['X-Content-Type-Options'] == 'nosniff', 'Public photo response is withdrawal-safe and fixed type')
            check(moderate()[0] == 409, 'Repeated/stale decision refused')
            check(submit()[1]['status'] == 'approved', 'Acceptance retry preserves later state')
            check(moderate({'version':'2','decision':'withdrawn'})[1]['status'] == 'withdrawn', 'Withdrawal committed')
            check(call('/gallery', extra=publication)[1] == [] and call('/gallery-photo?id=' + first['id'], extra=publication)[0] == 404, 'Withdrawal removes both gallery and subsequent image request')
            withdrawn_html = call('/review-view?id=' + first['id'])[1]
            check('name="decision"' not in withdrawn_html and 'Decision history' in withdrawn_html and 'Synthetic review' in withdrawn_html, 'Withdrawn review has audit and no resubmission controls')
            check(moderate({'version':'3'})[0] == 409, 'Withdrawn record cannot be republished')
            check(call('/fixture/counts')[1] == {'submissions':1,'audit':2}, 'Exactly intended commits/audit')
            # A new form tests the exact input ceiling and PHP's upload-limit path.
            fields['form_id'] = call('/fixture/new-form')[1]['form']
            exact = png + b'\0' * (6 * 1024 * 1024 - len(png))
            check(submit(image=exact)[0] == 200, 'Exact 6 MiB input accepted and normalized')
            check(submit(image=exact + b'x')[0] == 400, 'PHP upload-size error rejected')
            check(submit(image=b'x' * (7 * 1024 * 1024 + 1))[0] == 400, 'POST overflow fails closed even when PHP empties arrays')
            check(call('/fixture/counts')[1] == {'submissions':2,'audit':2}, 'Final storage contains only intended records')
            print(f'Showcase real HTTP: {checks} assertions passed.')
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
