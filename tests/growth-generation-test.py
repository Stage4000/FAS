"""Archived controlled two-PDO schedules, on disposable data and fake senders."""
from pathlib import Path
import os, shutil, subprocess, tempfile
ROOT = Path(__file__).resolve().parents[1]
FILES = ['src/marketing/Growth.php', 'src/security/SecurityStore.php', 'src/security/ClientIp.php', 'tests/fixtures/applepay-schema.sql']
with tempfile.TemporaryDirectory(prefix='fas-growth-generation-') as directory:
    root = Path(directory)
    for name in FILES:
        target = root/name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT/name, target)
    env = {'PATH': os.environ['PATH'], 'HOME': directory, 'TMPDIR': directory}
    for name in ['race', 'same-second', 'payload', 'legacy', 'tokens']:
        target = root/(name+'.php')
        shutil.copyfile(ROOT/'tests/fixtures/growth-generation'/(name+'.php'), target)
        subprocess.run(['php', '-d', 'open_basedir='+directory, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,exec,system,passthru,proc_open,popen', str(target), directory], cwd=root, env=env, check=True)
print('PASS archived generation schedules, payload guards, legacy and token behavior')
