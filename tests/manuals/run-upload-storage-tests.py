"""Full synthetic upload-storage regression runner; no production data or browser."""
from pathlib import Path
import os
import secrets
import subprocess
import sys
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('FAS_TEST_PHP', 'php')
OUT = Path(tempfile.mkdtemp(prefix='fas-manual-upload-http-')).resolve()
ENV = {**os.environ, 'FAS_TEST_PHP':PHP, 'FAS_SECURITY_DB_PATH':str(OUT / 'security.sqlite')}

def run(command, name):
    result = subprocess.run(command, cwd=ROOT, env=ENV, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    (OUT / name).write_text(result.stdout)
    print(result.stdout, end='', flush=True)
    assert result.returncode == 0, 'Failed: '+' '.join(command)

print('Synthetic evidence directory:', OUT, flush=True)
run([PHP, 'tests/manuals/upload-storage-test.php'], 'upload-storage-unit.txt')
run([PHP, 'tests/manuals/upload-storage-test.php', '--legacy'], 'upload-storage-legacy.txt')
run([PHP, 'tests/manuals/backend-test.php'], 'backend.txt')
run([PHP, 'tests/manuals/range-test.php'], 'range-unit.txt')
run([sys.executable, 'tests/manuals/upload-concurrency-test.py'], 'concurrency.txt')
run([sys.executable, 'tests/manuals/upload-concurrency-test.py', '--legacy'], 'concurrency-legacy.txt')
run([sys.executable, 'tests/manuals/prepare-fixture.py', str(OUT)], 'fixture.txt')
(OUT / 'upload-tmp').mkdir()
port = 20000+secrets.randbelow(30000)
base = f'http://127.0.0.1:{port}'
errors = OUT / 'php-errors.log'
command = [PHP, '-d', 'open_basedir='+str(OUT), '-d', 'allow_url_fopen=0', '-d', 'display_errors=1',
           '-d', 'log_errors=1', '-d', 'error_log='+str(errors), '-d', 'html_errors=0', '-d', 'error_reporting=-1',
           '-d', 'session.save_path='+str(OUT / 'sessions'), '-d', 'upload_tmp_dir='+str(OUT / 'upload-tmp'),
           '-d', 'output_buffering=4096', '-d', 'zlib.output_compression=1',
           '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open,fsockopen,pfsockopen,stream_socket_client',
           '-S', f'127.0.0.1:{port}', '-t', str(OUT / 'site')]
with (OUT / 'server.log').open('w') as log:
    server = subprocess.Popen(command, cwd=ROOT, env=ENV, stdout=log, stderr=subprocess.STDOUT)
    try:
        for _ in range(100):
            assert server.poll() is None, 'Synthetic server failed to start'
            try:
                with urllib.request.urlopen(base+'/manuals/', timeout=1) as response:
                    assert response.status == 200
                break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('Synthetic server startup timed out')
        run([sys.executable, 'tests/manuals/http-test.py', str(OUT), base], 'http.txt')
        assert not errors.exists() or errors.read_text() == '', 'Baseline HTTP produced unexpected errors'
        run([sys.executable, 'tests/manuals/range-http-test.py', str(OUT), base], 'range-http.txt')
        run([sys.executable, 'tests/manuals/upload-storage-http-test.py', str(OUT), base], 'upload-storage-http.txt')
        log_text = errors.read_text() if errors.exists() else ''
        assert 'Warning:' not in log_text and 'headers already sent' not in log_text and 'cleanup deferred' not in log_text, log_text
    finally:
        server.terminate()
        try:
            server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
print('All synthetic upload-storage tests passed. Evidence:', OUT)
