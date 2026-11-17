"""Synthetic negative control: auth recheck removal must fail exact-app test."""
from pathlib import Path
import shutil, subprocess, tempfile
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='showcase-admin-guard-') as tmp:
    target=Path(tmp)/'source'
    shutil.copytree(root,target)
    file=target/'src/showcase/ShowcaseAdminAuthorization.php'
    source=file.read_text()
    needle=' || $this->auth->isLoggedIn() !== true'
    assert source.count(needle)==1
    file.write_text(source.replace(needle,''))
    result=subprocess.run(['php',str(target/'tests/showcase-admin-current-app-test.php')],capture_output=True,text=True)
    assert result.returncode!=0 and 'check failed' in result.stdout+result.stderr
print('Admin current-auth bypass mutation rejected.')
