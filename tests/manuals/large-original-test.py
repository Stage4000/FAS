"""CLI import, private byte ranges and Chromium acceptance using a synthetic 396 MB PDF."""
import argparse
import hashlib
import http.client
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time

HERE = Path(__file__).resolve().parent
PHP = os.environ.get('FAS_TEST_PHP', 'php')


def build_pdf(path):
    offsets = [0]
    with path.open('wb') as f:
        f.write(b'%PDF-1.7\n%\xe2\xe3\xcf\xd3\n')

        def obj(data):
            offsets.append(f.tell())
            f.write(b'%d 0 obj\n' % (len(offsets) - 1) + data + b'\nendobj\n')

        def text(label):
            body = b'BT /F1 24 Tf 50 700 Td (' + label + b') Tj ET'
            obj(b'<< /Length %d >>\nstream\n' % len(body) + body + b'\nendstream')

        obj(b'<< /Type /Catalog /Pages 2 0 R >>')
        obj(b'<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>')
        for content in (6, 8):
            obj(b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                b'/Resources << /Font << /F1 5 0 R >> >> /Contents %d 0 R >>' % content)
        obj(b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>')
        text(b'Synthetic large original: page one')
        # An unreferenced stream separates the two pages by nearly 400 MB.
        offsets.append(f.tell())
        padding = 396_576_000
        f.write(b'7 0 obj\n<< /Length %d >>\nstream\n' % padding)
        f.seek(padding, 1)
        f.write(b'\nendstream\nendobj\n')
        text(b'Synthetic large original: page two')
        xref = f.tell()
        f.write(b'xref\n0 9\n0000000000 65535 f \n')
        for offset in offsets[1:]:
            f.write(b'%010d 00000 n \n' % offset)
        f.write(b'trailer\n<< /Size 9 /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n' % xref)
    return path.stat().st_size


def request(port, url, headers=None, method='GET'):
    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=25)
    conn.request(method, url, headers=headers or {})
    return conn, conn.getresponse()


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--http-only', action='store_true')
args = parser.parse_args()
with tempfile.TemporaryDirectory(prefix='fas-large-original-') as tmp:
    out = Path(tmp)
    env = {**os.environ, 'FAS_TEST_PHP': PHP, 'FAS_SECURITY_DB_PATH': str(out/'security.sqlite')}
    subprocess.run([sys.executable, str(HERE/'prepare-fixture.py'), tmp], check=True, env=env)
    site = out/'site'
    (site/'src/config/config.php').write_text(
        "<?php return ['database'=>['path'=>__DIR__.'/../../fixture.sqlite'],"
        "'manuals'=>['storage_path'=>" + repr(str(out/'private')) + ",'public_enabled'=>false]];")
    original = out/'large.pdf'
    size = build_pdf(original)
    with (out/'over-limit.pdf').open('wb') as f:
        f.write(b'%PDF-1.7\n')
        f.truncate(400 * 1024**2 + 1)
    with original.open('rb') as f:
        digest = hashlib.file_digest(f, 'sha256').hexdigest()
    seed = out/'seed-large.php'
    seed.write_text(r"""<?php
require __DIR__.'/site/src/manuals/bootstrap.php';
$storage=manualsStorage();
try {
    $storage->importLocal(__DIR__.'/over-limit.pdf');
    throw new RuntimeException('Over-limit original was not rejected');
} catch(InvalidArgumentException $expected) {}
$db=manualsDatabase();
$repo=new FAS\Manuals\ManualLibrary($db,fn()=>true);
$input=FAS\Manuals\ManualLibrary::blank();
$input['title']='Synthetic large original';
$input['manufacturer']='Fixture Works';
try {
    $file=$storage->importLocal(__DIR__.'/large.pdf',true);
    $m=$repo->create($input,$file,1,bin2hex(random_bytes(24)));
} finally { $storage->settleNewFiles($db); }
echo $m['id'].' '.$file['key'].' '.$file['bytes'].' '.$file['sha256'];
""")
    row, key, stored_size, stored_hash = subprocess.check_output(
        [PHP, '-d', 'memory_limit=32M', str(seed)], env=env, text=True).split()
    assert int(stored_size) == size and stored_hash == digest
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with (out/'server.log').open('w+') as log:
        server = subprocess.Popen([
            PHP, '-d', 'memory_limit=32M', '-d', 'allow_url_fopen=0',
            '-d', 'display_errors=0', '-d', 'session.save_path=' + str(out/'sessions'),
            '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec',
            '-S', f'127.0.0.1:{port}', '-t', str(site)], stdout=log, stderr=subprocess.STDOUT, env=env)
        try:
            for _ in range(100):
                try:
                    conn, r = request(port, '/manuals/')
                    assert r.status == 404 and r.getheader('Cache-Control') == 'no-store'
                    conn.close()
                    break
                except ConnectionRefusedError:
                    time.sleep(.05)
            else:
                raise AssertionError('Synthetic PHP server did not start')
            public = f'/manuals/file.php?id={row}'
            private = f'/admin/manuals-file.php?id={row}'
            for url, status in [(public, 404), (private, 302)]:
                conn, r = request(port, url, {'Range': 'bytes=0-7'})
                assert r.status == status and not r.getheader('Content-Range')
                conn.close()
            conn, r = request(port, '/fixture-login.php', method='POST')
            assert r.status == 200
            cookie = r.getheader('Set-Cookie').split(';', 1)[0]
            conn.close()
            conn, r = request(port, private, {'Cookie': cookie})
            assert r.status == 200 and int(r.getheader('Content-Length')) == size
            streamed = hashlib.sha256()
            for chunk in iter(lambda: r.read(256 * 1024), b''):
                streamed.update(chunk)
            assert streamed.hexdigest() == digest
            conn.close()
            for start, end in [(0, 127), (size - 256, size - 1)]:
                conn, r = request(port, private, {'Cookie': cookie, 'Range': f'bytes={start}-{end}'})
                assert r.status == 206 and r.getheader('Content-Range') == f'bytes {start}-{end}/{size}'
                assert r.getheader('Cache-Control') == 'private, no-store'
                with original.open('rb') as f:
                    f.seek(start)
                    assert r.read() == f.read(end - start + 1)
                conn.close()
            if not args.http_only:
                subprocess.run(['node', str(HERE/'large-original-browser-test.cjs'),
                                f'http://127.0.0.1:{port}', row, str(size)], env=env, check=True, timeout=110)
            with (out/'private'/key).open('r+b') as f:
                f.seek(size - 16)
                f.write(b'x')
            conn, r = request(port, private, {'Cookie': cookie, 'Range': 'bytes=0-7'})
            assert r.status == 503 and not r.getheader('Content-Range')
            conn.close()
            print(f'PASS {size}-byte exact private download, first/last byte ranges, access gates, and integrity rejection')
        except BaseException:
            log.seek(0)
            print(log.read(), file=sys.stderr)
            raise
        finally:
            server.terminate()
            server.wait(timeout=10)
