"""Run isolated synthetic backend/HTTP checks; leaves evidence in a new temporary folder."""
from pathlib import Path
import os
import secrets
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('FAS_TEST_PHP', 'php')
OUT = Path(tempfile.mkdtemp(prefix='fas-manual-range-http-'))
environment = {**os.environ, 'FAS_TEST_PHP': PHP, 'FAS_SECURITY_DB_PATH': str(OUT / 'security.sqlite')}

def run(command, filename):
    result = subprocess.run(command, cwd=ROOT, env=environment, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    (OUT / filename).write_text(result.stdout)
    print(result.stdout, end='')
    if result.returncode:
        raise RuntimeError('Failed: ' + ' '.join(command))

print('Synthetic evidence directory:', OUT, flush=True)
run([PHP, 'tests/manuals/range-test.php'], 'range-unit-results.txt')
run([PHP, 'tests/manuals/backend-test.php'], 'backend-results.txt')
run([sys.executable, 'tests/manuals/prepare-fixture.py', str(OUT)], 'fixture-results.txt')
port = 20000 + secrets.randbelow(30000)
base = f'http://127.0.0.1:{port}'
# Exercise output-buffer cleanup and disable PHP compression at response time,
# even when the synthetic host starts with compression enabled.
command = [PHP, '-d', 'allow_url_fopen=0', '-d', 'display_errors=0', '-d', 'output_buffering=4096',
           '-d', 'zlib.output_compression=1', '-d', 'session.save_path=' + str(OUT / 'sessions'),
           '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec',
           '-S', f'127.0.0.1:{port}', '-t', str(OUT / 'site')]
with (OUT / 'php-server.log').open('w') as log:
    server = subprocess.Popen(command, cwd=ROOT, env=environment, stdout=log, stderr=subprocess.STDOUT)
    try:
        for attempt in range(100):
            if server.poll() is not None:
                raise RuntimeError('Fixture server did not start. See ' + str(OUT / 'php-server.log'))
            try:
                with urllib.request.urlopen(base + '/manuals/', timeout=1) as response:
                    assert response.status == 200
                break
            except (OSError, urllib.error.URLError):
                time.sleep(0.05)
        else:
            raise RuntimeError('Fixture server startup timed out.')
        run([sys.executable, 'tests/manuals/http-test.py', str(OUT), base], 'http-results.txt')
        run([sys.executable, 'tests/manuals/range-http-test.py', str(OUT), base], 'range-http-results.txt')
    finally:
        server.terminate()
        try:
            server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
print('All isolated tests passed. Evidence:', OUT)
