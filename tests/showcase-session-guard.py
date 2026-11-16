from pathlib import Path
import subprocess,tempfile,shutil,os
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='showcase-session-control-') as d:
 r=Path(d);(r/'src/showcase').mkdir(parents=True);(r/'tests').mkdir()
 src=root/'src/showcase/ShowcaseSessionForms.php';s=src.read_text();before="$this->state[$submittedId]['expires_at']<=$now";assert s.count(before)==1
 (r/'src/showcase/ShowcaseSessionForms.php').write_text(s.replace(before,'false'))
 shutil.copyfile(root/'tests/showcase-session-test.php',r/'tests/showcase-session-test.php')
 p=subprocess.run(['php','tests/showcase-session-test.php'],cwd=r,text=True,capture_output=True,creationflags=(subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0))
 assert p.returncode!=0 and 'expected rejection' in (p.stdout+p.stderr),(p.returncode,p.stderr)
 print('Session expiry removal negative control detected.')
