import os
from pathlib import Path
import tempfile,subprocess,shutil
r=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='showcase-storage-control-') as d:
 w=Path(d);shutil.copytree(r/'src',w/'src');(w/'tests').mkdir();shutil.copy(r/'tests/showcase-private-storage-test.php',w/'tests/showcase-private-storage-test.php')
 p=w/'src/showcase/ShowcasePrivateStorage.php';s=p.read_text();old='if (strpos(self::normalized($real),$prefix)===0)';assert s.count(old)==1;p.write_text(s.replace(old,'if (false)'))
 result=subprocess.run(['php','tests/showcase-private-storage-test.php'],cwd=w,capture_output=True,text=True,creationflags=(subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0));assert result.returncode!=0 and 'expected rejection' in (result.stdout+result.stderr),(result.stdout,result.stderr);print('Public containment removal detected')
