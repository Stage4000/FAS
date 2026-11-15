"""HTTP retry/storage accounting against an already-running synthetic fixture."""
from pathlib import Path
import hashlib
import html
import http.cookiejar
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
assert (OUT / 'receipt.json').is_file(), 'Only use the synthetic fixture'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
checks = []
def check(condition, label):
    assert condition, label
    checks.append(label)
    print('PASS', label)

def request(path, data=None, pdf=None):
    headers = {}
    if pdf is not None:
        boundary = 'FASSyntheticStorageRetry'
        chunks = [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key, value in data.items()]
        chunks += [f'--{boundary}\r\nContent-Disposition: form-data; name="pdf"; filename="synthetic.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode(), pdf, f'\r\n--{boundary}--\r\n'.encode()]
        data = b''.join(chunks)
        headers['Content-Type'] = 'multipart/form-data; boundary='+boundary
    try:
        response = client.open(urllib.request.Request(BASE+path, headers=headers, data=data), timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, dict(response.headers), response.read()

def tokens():
    response = request('/admin/manuals-edit.php')
    assert response[0] == 200
    page = response[2].decode()
    return {name:html.unescape(re.search('name="'+name+'" value="([^"]*)"', page)[1]) for name in ['csrf_token','revision','request_key']}

def snapshot():
    return {p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in (OUT / 'private').glob('*.pdf')}

def counts():
    with sqlite3.connect(OUT / 'site/fixture.sqlite') as db:
        return db.execute('SELECT count(*) FROM manuals').fetchone()[0], db.execute('SELECT count(*) FROM manual_history').fetchone()[0]

check(request('/fixture-login.php')[0] == 200, 'Actual administrator login for retry accounting')
pdf = (OUT / 'synthetic-workshop.pdf').read_bytes()
data = dict(title='Synthetic storage retry', manufacturer='Example', model='ZX', years='2026', language='English', description='', rights='unverified', rights_note='', source_url='', action='draft')
before = snapshot()
rows, history = counts()
form = {**data, **tokens()}
first = request('/admin/manuals-edit.php', form, pdf)
check(first[0] == 303, 'Initial multipart upload creates its draft')
location = first[1]['Location']
committed = snapshot()
check(len(committed) == len(before)+1 and counts() == (rows+1, history+1), 'Initial multipart upload stores exactly one new original and one audited row')
for attempt in range(3):
    retry = request('/admin/manuals-edit.php', form, pdf)
    check(retry[0] == 303 and retry[1]['Location'] == location, 'Exact multipart retry returns the existing manual '+str(attempt+1))
    check(snapshot() == committed and counts() == (rows+1, history+1), 'Exact multipart retry adds no stored PDF, bytes or history '+str(attempt+1))
conflict = request('/admin/manuals-edit.php', {**form, 'title':'Conflicting title'}, pdf)
check(conflict[0] == 409 and snapshot() == committed and counts() == (rows+1, history+1), 'Conflicting metadata retry removes its unused copy and preserves committed state')
conflict = request('/admin/manuals-edit.php', form, pdf+b'\n% Synthetic alternate payload\n')
check(conflict[0] == 409 and snapshot() == committed and counts() == (rows+1, history+1), 'Conflicting content retry removes its unused copy and preserves original bytes')

with sqlite3.connect(OUT / 'site/fixture.sqlite') as db:
    db.execute("CREATE TRIGGER synthetic_upload_audit_failure BEFORE INSERT ON manual_history BEGIN SELECT RAISE(ABORT,'Synthetic audit failure'); END")
try:
    failed = request('/admin/manuals-edit.php', {**data, **tokens()}, pdf)
    check(failed[0] == 503 and b'Warning:' not in failed[2], 'Failure after catalog insertion produces a controlled save failure')
    check(snapshot() == committed and counts() == (rows+1, history+1), 'Rolled-back HTTP creation leaves no new row, history or stored copy')
finally:
    with sqlite3.connect(OUT / 'site/fixture.sqlite') as db:
        db.execute('DROP TRIGGER synthetic_upload_audit_failure')

fresh = request('/admin/manuals-edit.php', {**data, **tokens()}, pdf)
check(fresh[0] == 303 and fresh[1]['Location'] != location and len(snapshot()) == len(committed)+1,
      'An intentional new entry still gets its own original; global dedupe is not silently introduced')
with sqlite3.connect(OUT / 'site/fixture.sqlite') as db:
    files = [json.loads(row[0]) for row in db.execute('SELECT file_json FROM manuals')]
check(all((OUT / 'private' / file['key']).stat().st_size == file['bytes']
          and hashlib.sha256((OUT / 'private' / file['key']).read_bytes()).hexdigest() == file['sha256'] for file in files),
      'Every catalog-referenced original still exists with its exact recorded length and hash')
(OUT / 'upload-storage-http-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks}, indent=2))
print(len(checks), 'upload-storage HTTP checks passed')
