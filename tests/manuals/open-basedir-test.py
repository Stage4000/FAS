"""Synthetic constructor and HTTP regressions with a strict fixture-only open_basedir."""
from pathlib import Path
import json
import os
import secrets
import shutil
import subprocess
import sys
sys.dont_write_bytecode=True
from config_reload import FixtureConfigReload
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('FAS_TEST_PHP', 'php')
OUT = Path(tempfile.mkdtemp(prefix='fas-manual-open-basedir-')).resolve()
OUTSIDE = Path(tempfile.mkdtemp(prefix='fas-manual-outside-')).resolve()
ENV = {**os.environ, 'FAS_TEST_PHP': PHP, 'FAS_SECURITY_DB_PATH': str(OUT / 'security.sqlite')}
checks = []

def check(condition, label):
    assert condition, label
    checks.append(label)
    print('PASS', label, flush=True)

print('Synthetic evidence directory:', OUT, flush=True)
seed = subprocess.run([sys.executable, 'tests/manuals/prepare-fixture.py', str(OUT)], cwd=ROOT, env=ENV,
                      text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
(OUT / 'fixture-results.txt').write_text(seed.stdout)
assert seed.returncode == 0, seed.stdout
source = Path(os.environ.get('FAS_TEST_STORAGE_SOURCE', ROOT / 'src/manuals/ManualLibrary.php'))
shutil.copy2(source, OUT / 'site/src/manuals/ManualLibrary.php')
(OUT / 'site/nested-private').mkdir()
(OUT / 'upload-tmp').mkdir()
(OUT / 'container').mkdir()
(OUT / 'container/private').mkdir()
(OUT / 'direct-link').symlink_to(OUT / 'private', target_is_directory=True)
(OUT / 'nested-link').symlink_to(OUT / 'container', target_is_directory=True)
(OUT / 'outside-link').symlink_to(OUTSIDE, target_is_directory=True)
probe = OUT / 'constructor-probe.php'
probe.write_text('''<?php
require __DIR__.'/site/src/manuals/ManualLibrary.php';
$result=['accepted'=>false,'warnings'=>[]];
$restore=($argv[3]??'')==='restore';
if($restore)set_error_handler(static function(int $severity,string $message)use(&$result):bool{$result['warnings'][]=$message;return true;});
$root=$argv[1]==='NULLBYTE'?__DIR__."/private\\0bad":$argv[1];
try{new FAS\\Manuals\\ManualStorage($root,$argv[2]);$result['accepted']=true;}
catch(Throwable $e){$result['error']=get_class($e);$result['message']=$e->getMessage();}
if($restore){trigger_error('caller-handler-restored',E_USER_WARNING);restore_error_handler();}
$result['open_basedir']=ini_get('open_basedir');$result['error_reporting']=error_reporting();
echo json_encode($result,JSON_THROW_ON_ERROR);
''')
cases = [
    ('Canonical private root', str(OUT / 'private'), str(OUT / 'site'), True),
    ('Harmless trailing separator', str(OUT / 'private')+'/', str(OUT / 'site'), True),
    ('Sibling root with a shared name prefix', str(OUT / 'container/private'), str(OUT / 'site'), True),
    ('Relative root', 'private', str(OUT / 'site'), False),
    ('Empty root', '', str(OUT / 'site'), False),
    ('Dot segment alias', str(OUT / 'private')+'/.', str(OUT / 'site'), False),
    ('Parent segment alias', str(OUT / 'private')+'/../private', str(OUT / 'site'), False),
    ('Duplicate separator alias', str(OUT)+'//private', str(OUT / 'site'), False),
    ('Direct symlink', str(OUT / 'direct-link'), str(OUT / 'site'), False),
    ('Nested symlink component', str(OUT / 'nested-link/private'), str(OUT / 'site'), False),
    ('Symlink resolving outside allowed root', str(OUT / 'outside-link'), str(OUT / 'site'), False),
    ('Outside storage root', str(OUTSIDE), str(OUT / 'site'), False),
    ('Inaccessible ancestor root', str(OUT.parent), str(OUT / 'site'), False),
    ('Outside document root', str(OUT / 'private'), str(OUTSIDE), False),
    ('Missing storage root', str(OUT / 'missing'), str(OUT / 'site'), False),
    ('Missing document root', str(OUT / 'private'), str(OUT / 'missing'), False),
    ('Storage equals web root', str(OUT / 'site'), str(OUT / 'site'), False),
    ('Storage inside web root', str(OUT / 'site/nested-private'), str(OUT / 'site'), False),
    ('Storage ancestor of web root', str(OUT), str(OUT / 'site'), False),
    ('Stream-wrapper spelling', 'file://'+str(OUT / 'private'), str(OUT / 'site'), False),
    ('Embedded NUL rejected before filesystem resolution', 'NULLBYTE', str(OUT / 'site'), False),
]
cli_log = OUT / 'constructor-errors.log'
for label, root, web, allowed in cases:
    for mode in ['plain', 'restore']:
        result = subprocess.run([PHP, '-d', 'open_basedir='+str(OUT), '-d', 'display_errors=1', '-d', 'html_errors=0',
                                 '-d', 'log_errors=1', '-d', 'error_log='+str(cli_log), '-d', 'error_reporting=-1',
                                 str(probe), root, web, mode], cwd=OUT, env=ENV, text=True, capture_output=True)
        (OUT / ('constructor-'+str(len(checks))+'.txt')).write_text(result.stdout+result.stderr)
        assert result.returncode == 0 and result.stderr == '', (label, mode, result.stdout, result.stderr)
        try:
            value = json.loads(result.stdout)
        except ValueError:
            raise AssertionError((label, mode, 'Warning or other output corrupted constructor response', result.stdout))
        check(value['accepted'] is allowed and value['warnings'] == (['caller-handler-restored'] if mode == 'restore' else [])
              and value['open_basedir'] == str(OUT) and value['error_reporting'] == -1,
              label + ': clean result and unchanged restrictions ('+mode+')')
check(not cli_log.exists() or cli_log.read_text() == '', 'Constructor probes produce no PHP error-log warnings with display_errors on')

port = 20000 + secrets.randbelow(30000)
base = f'http://127.0.0.1:{port}'
errors = OUT / 'php-errors.log'
disabled = 'mail,curl_exec,curl_multi_exec,exec,shell_exec,system,passthru,popen,proc_open,fsockopen,pfsockopen,stream_socket_client'
command = [PHP, '-d', 'open_basedir='+str(OUT), '-d', 'allow_url_fopen=0', '-d', 'disable_functions='+disabled,
           '-d', 'display_errors=1', '-d', 'html_errors=0', '-d', 'log_errors=1', '-d', 'error_log='+str(errors),
           '-d', 'error_reporting=-1', '-d', 'session.save_path='+str(OUT / 'sessions'),
           '-d', 'upload_tmp_dir='+str(OUT / 'upload-tmp'),
           '-d', 'output_buffering=4096', '-d', 'zlib.output_compression=1', '-S', f'127.0.0.1:{port}', '-t', str(OUT / 'site')]
with (OUT / 'server.log').open('w') as log:
    server = subprocess.Popen(command, cwd=ROOT, env=ENV, stdout=log, stderr=subprocess.STDOUT)
    try:
        for _ in range(100):
            assert server.poll() is None, (OUT / 'server.log').read_text()
            try:
                with urllib.request.urlopen(base+'/manuals/', timeout=1) as response:
                    assert response.status == 200
                break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError('Strict fixture server did not become ready.')
        for name in ['http-test.py', 'range-http-test.py']:
            result = subprocess.run([sys.executable, 'tests/manuals/'+name, str(OUT), base], cwd=ROOT, env=ENV,
                                    text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            (OUT / (name+'.results.txt')).write_text(result.stdout)
            # Relay only into the outer stage pipe. The hosted runner captures
            # and sanitizes it; raw child output is never an uploaded artifact.
            print(result.stdout, end='', flush=True)
            check(result.returncode == 0, name+' passes with display_errors on and unchanged strict open_basedir')
            if name == 'http-test.py':
                check(not errors.exists() or errors.read_text() == '', 'All 51 baseline HTTP checks leave a completely clean PHP error log')
        # Six intentional 503s in the range suite must log only the controlled
        # application integrity error, never a PHP filesystem/header warning.
        lines = errors.read_text().splitlines() if errors.exists() else []
        check(len(lines) == 6 and all(line.endswith(('Manual PDF unavailable: The stored PDF failed its integrity check.',
              'Private PDF unavailable: The stored PDF failed its integrity check.')) for line in lines),
              'Range suite logs only its six intentional integrity failures, with no PHP warnings')
        config = OUT / 'site/src/config/config.php'
        original = config.read_text()
        reload=FixtureConfigReload(OUT,base)
        try:
            for case,storage in zip(['storage_outside','storage_outside_link','storage_direct_link','storage_alias'],[str(OUTSIDE), str(OUT / 'outside-link'), str(OUT / 'direct-link'), str(OUT / 'private')+'/../private']):
                reload.apply(original.replace(repr(str(OUT / 'private')), repr(storage)),case)
                reload.before_request()
                try:
                    response = urllib.request.urlopen(urllib.request.Request(base+'/manuals/file.php?id=1', headers={'Range':'bytes=0-7'}), timeout=10)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    body=response.read()
                    reload.observe((response.status,dict(response.headers),body),'boolean_true',storage,'file')
                    check(response.status == 503 and body == b'The PDF is temporarily unavailable.'
                          and response.headers.get('Content-Range') is None,
                          'Invalid storage configuration fails cleanly without PDF/warning bytes: '+storage)
        finally:
            reload.apply(original,'storage_restore');reload.before_request()
            with urllib.request.urlopen(urllib.request.Request(base+'/manuals/file.php?id=1',headers={'Range':'bytes=0-7'}),timeout=10) as response:
                body=response.read();reload.observe((response.status,dict(response.headers),body),'boolean_true',OUT/'private','file')
                assert response.status==206 and body==(OUT/'synthetic-workshop.pdf').read_bytes()[:8],'Restored storage must serve exact public range'
        content = errors.read_text() if errors.exists() else ''
        check('Warning:' not in content and 'headers already sent' not in content and 'open_basedir restriction' not in content,
              'Invalid configuration responses contain only controlled application errors in the log')
    finally:
        server.terminate()
        try:
            server.wait(timeout=10)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()

(OUT / 'open-basedir-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks,'fixture':str(OUT),
    'restrictions':{'open_basedir':str(OUT),'display_errors':1,'allow_url_fopen':0,'disable_functions':disabled},
    'source':str(source),'scope':'Synthetic CLI and loopback HTTP only; no browser or production changes'}, indent=2))
print(len(checks), 'strict open_basedir checks passed, including existing 51 + 103 HTTP checks. Evidence:', OUT)
