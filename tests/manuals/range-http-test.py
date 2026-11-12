"""Byte-exact range checks against a fresh synthetic manuals fixture on loopback only."""
from pathlib import Path
import http.client
import http.cookiejar
import html
import json
import re
import sqlite3
import sys
import urllib.error
import urllib.parse
import urllib.request

OUT = Path(sys.argv[1]).resolve()
BASE = sys.argv[2]
parsed = urllib.parse.urlsplit(BASE)
assert parsed.scheme == 'http' and parsed.hostname == '127.0.0.1' and parsed.port
assert (OUT / 'receipt.json').is_file(), 'Use prepare-fixture.py, never production'

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)

admin, anon = client(), client()
checks = []
def request(path, headers=None, method='GET', who=anon, data=None):
    data = None if data is None else urllib.parse.urlencode(data).encode()
    try:
        response = who.open(urllib.request.Request(BASE + path, data=data, headers=headers or {}, method=method), timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, dict(response.headers), response.read()

def check(value, label):
    assert value, label
    checks.append(label)
    print('PASS', label)

def safe_headers(headers):
    return (headers.get('Accept-Ranges') == 'bytes'
            and headers.get('Content-Type') == 'application/pdf'
            and headers.get('Content-Disposition') == 'attachment; filename="manual.pdf"'
            and headers.get('Cache-Control') == 'private, no-store'
            and headers.get('X-Content-Type-Options') == 'nosniff'
            and headers.get('Content-Security-Policy') == "sandbox; default-src 'none'"
            and headers.get('X-Robots-Tag') == 'noindex, nofollow'
            and headers.get('Referrer-Policy') == 'no-referrer'
            and 'Content-Encoding' not in headers)

public = '/manuals/file.php?id=1'
private = '/admin/manuals-file.php?id=1'
source = (OUT / 'synthetic-workshop.pdf').read_bytes()
size = len(source)
check(request('/fixture-login.php', who=admin)[0] == 200, 'Actual AdminAuth synthetic login')
for path, who in [(public, anon), (private, admin)]:
    result = request(path, who=who, headers={'Accept-Encoding': 'gzip'})
    check(result[0] == 200 and result[2] == source and result[1].get('Content-Length') == str(size)
          and 'Content-Range' not in result[1] and safe_headers(result[1]), 'Full response is exact and uncompressed ' + path)
    for value, start, end in [('bytes=0-0', 0, 0), ('bytes=0-31', 0, 31), ('bytes=31-66', 31, 66),
                              (f'bytes={size-1}-', size-1, size-1), ('bytes=101-', 101, size-1),
                              ('bytes=-32', size-32, size-1), (f'bytes=-{size+1}', 0, size-1),
                              ('bytes=1-99999999999999999999999999', 1, size-1),
                              ('bytes=-99999999999999999999999999', 0, size-1), ('BYTES=0001-0009', 1, 9)]:
        status, headers, body = request(path, {'Range': value, 'Accept-Encoding': 'gzip'}, who=who)
        check(status == 206 and body == source[start:end+1] and headers.get('Content-Length') == str(end-start+1)
              and headers.get('Content-Range') == f'bytes {start}-{end}/{size}' and safe_headers(headers),
              'Exact bounded range and safety headers ' + path + ' ' + value)
    for value in ['', 'bytes=-0', 'bytes=-', 'bytes=4-3', f'bytes={size}-', 'bytes=999999999999999999999999-',
                  'bytes=0-1,4-5', 'bytes=0-1,', 'items=0-1', 'bytes=+1-2', 'bytes=1e2-200', 'bytes=0-1X',
                  'bytes=0-' + '9'*256]:
        status, headers, body = request(path, {'Range': value}, who=who)
        check(status == 416 and body == b'' and headers.get('Content-Length') == '0'
              and headers.get('Content-Range') == f'bytes */{size}' and safe_headers(headers),
              'Unsatisfiable or unsupported range returns empty 416 ' + path + ' ' + value[:45])
    for value in [None, 'bytes=0-9', 'bytes=999999999999999999-', 'invalid']:
        status, headers, body = request(path, {} if value is None else {'Range': value}, method='HEAD', who=who)
        check(status == 200 and body == b'' and headers.get('Content-Length') == str(size)
              and 'Content-Range' not in headers and safe_headers(headers), 'HEAD ignores Range and returns full length ' + path + ' ' + str(value))
    for validator in ['"unknown"', 'W/"weak"', 'Wed, 21 Oct 2015 07:28:00 GMT', '']:
        status, headers, body = request(path, {'Range': 'bytes=0-9', 'If-Range': validator}, who=who)
        check(status == 200 and body == source and headers.get('Content-Length') == str(size)
              and 'Content-Range' not in headers and safe_headers(headers), 'If-Range conservatively returns full representation ' + path + ' ' + validator)
    result = request(path, {'Range': 'bytes=0-9'}, method='POST', who=who, data={})
    check(result[0] == 405 and result[1].get('Allow') == 'GET, HEAD' and result[2] == b'', 'Range cannot bypass method allowlist ' + path)

connection = http.client.HTTPConnection(parsed.hostname, parsed.port, timeout=15)
connection.putrequest('GET', public)
connection.putheader('Range', 'bytes=0-1')
connection.putheader('Range', 'bytes=4-5')
connection.endheaders()
response = connection.getresponse()
check(response.status == 416 and response.getheader('Content-Length') == '0' and response.read() == b'', 'Repeated Range fields cannot smuggle multipart content')
connection.close()

# Verify independent sequential requests reconstruct the exact original without overfetch.
pieces = []
for start in range(0, size, 701):
    end = min(start+700, size-1)
    result = request(public, {'Range': f'bytes={start}-{end}'})
    assert result[0] == 206 and len(result[2]) == end-start+1
    pieces.append(result[2])
check(b''.join(pieces) == source, 'Sequential ranges reconstruct the complete synthetic PDF exactly')

# Test-only wrapper observes the actual endpoint at shutdown, before PHP's normal
# session teardown. It is generated only inside the disposable fixture web root.
probe = OUT / 'site/fixture-stream-probe.php'
marker = OUT / 'session-stream-state.json'
probe.write_text("<?php register_shutdown_function(static function():void {file_put_contents("
                 + repr(str(marker)) + ",json_encode(['status'=>session_status()]));});require __DIR__.'/admin/manuals-file.php';")
try:
    result = request('/fixture-stream-probe.php?id=1', {'Range': 'bytes=0-7'}, who=admin)
    check(result[0] == 206 and result[2] == source[:8] and json.loads(marker.read_text())['status'] == 1,
          'Actual private streaming endpoint explicitly closes the administrator session')
finally:
    probe.unlink()

def blocked(path, who, status, label):
    for method, value in [('GET', 'bytes=0-7'), ('GET', 'bytes=999999999999999999-'), ('HEAD', 'bytes=0-7')]:
        result = request(path, {'Range': value}, method=method, who=who)
        check(result[0] == status and 'Content-Range' not in result[1] and 'Accept-Ranges' not in result[1]
              and not result[2].startswith(b'%PDF-'), label + ' ' + method + ' ' + value)

blocked(private, anon, 302, 'Anonymous range cannot read private bytes or disclose file length')
config = OUT / 'site/src/config/config.php'
original_config = config.read_text()
try:
    config.write_text(original_config.replace("'public_enabled'=>true", "'public_enabled'=>false"))
    blocked(public, anon, 404, 'Release hold blocks all public range requests')
    check(request(private, {'Range': 'bytes=0-7'}, who=admin)[0] == 206, 'Private authorized range remains available during public release hold')
finally:
    config.write_text(original_config)

db_path = OUT / 'site/fixture.sqlite'
with sqlite3.connect(db_path) as db:
    original_metadata = json.loads(db.execute('SELECT draft_json FROM manuals WHERE id=1').fetchone()[0])

def save(action, **changes):
    page = request('/admin/manuals-edit.php?id=1', who=admin)[2].decode()
    tokens = {name: html.unescape(re.search('name="'+name+'" value="([^"]*)"', page)[1])
              for name in ['csrf_token', 'revision', 'request_key']}
    data = {**original_metadata, **tokens, 'action': action, 'password': 'fixture-only', 'reviewed': '1', **changes}
    result = request('/admin/manuals-edit.php?id=1', method='POST', who=admin, data=data)
    assert result[0] == 303, (action, changes, result)

try:
    for rights in ['restricted', 'unverified']:
        save('draft', rights=rights)
        blocked(public, anon, 404, rights + ' rights block later ranges after an earlier successful range')
        check(request(private, {'Range': 'bytes=0-7'}, who=admin)[0] == 206, 'Administrator retains private preview of ' + rights + ' original')
        save('publish')
    save('withdraw', confirm_withdraw='1')
    blocked(public, anon, 404, 'Explicit withdrawal blocks subsequent range requests')
finally:
    save('publish')

with sqlite3.connect(db_path) as db:
    db.execute('UPDATE admin_users SET is_active=0 WHERE id=1')
try:
    blocked(private, admin, 403, 'Deactivated account cannot resume a private PDF by range')
finally:
    with sqlite3.connect(db_path) as db:
        db.execute('UPDATE admin_users SET is_active=1 WHERE id=1')
with sqlite3.connect(db_path) as db:
    db.execute('INSERT INTO admin_account_security(admin_id,session_version) VALUES(1,2) '
               'ON CONFLICT(admin_id) DO UPDATE SET session_version=session_version+1')
blocked(private, admin, 403, 'Revoked session token cannot resume a private PDF by range')
check(request('/fixture-login.php', who=admin)[0] == 200, 'Fresh authenticated session can be established after revocation')
check(request(private, {'Range': 'bytes=0-7'}, who=admin)[0] == 206, 'Fresh active session regains authorized private range access')

with sqlite3.connect(db_path) as db:
    file = json.loads(db.execute('SELECT file_json FROM manuals WHERE id=1').fetchone()[0])
stored = OUT / 'private' / file['key']
original = stored.read_bytes()
try:
    # Corrupt only the last byte, outside the requested prefix, without changing length.
    stored.write_bytes(original[:-1] + bytes([original[-1] ^ 1]))
    for path, who in [(public, anon), (private, admin)]:
        for method, value in [('GET', 'bytes=0-7'), ('GET', 'bytes=999999999999999999-'), ('HEAD', 'bytes=0-7')]:
            result = request(path, {'Range': value}, method=method, who=who)
            check(result[0] == 503 and 'Content-Range' not in result[1] and not result[2].startswith(b'%PDF-'),
                  'Whole-file integrity precedes range metadata and bytes ' + path + ' ' + method + ' ' + value)
finally:
    stored.write_bytes(original)
check(request(public, {'Range': 'bytes=0-7'})[2] == original[:8], 'Untampered original remains readable after integrity test')

(OUT / 'range-http-results.json').write_text(json.dumps({'passed': len(checks), 'checks': checks,
    'scope': 'Synthetic loopback HTTP only; actual AdminAuth, repository and endpoints; no browser or production acceptance'}, indent=2))
print(len(checks), 'range HTTP checks passed')
