from pathlib import Path
import tempfile,shutil,subprocess
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='showcase-admin-negative-') as d:
 tree=Path(d)/'candidate';shutil.copytree(root,tree)
 source=tree/'src/showcase/ShowcaseAdminController.php'
 text=source.read_text();old="if (($this->enabled)()!==true)";assert old in text
 source.write_text(text.replace(old,"if (false)",1))
 test=subprocess.run(['php',str(tree/'tests/showcase-admin-controller-test.php')],capture_output=True,text=True)
 assert test.returncode!=0,'Disabled-controller bypass unexpectedly passed'
 print('Admin controller disabled-gate bypass mutation detected.')
