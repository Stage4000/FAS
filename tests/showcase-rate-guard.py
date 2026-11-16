from pathlib import Path
import tempfile,shutil,subprocess
r=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='showcase-rate-control-') as d:
 w=Path(d);(w/'src/showcase').mkdir(parents=True);(w/'tests').mkdir()
 s=(r/'src/showcase/ShowcaseRateAdmission.php').read_text();old='if((int)$used >= $this->limit)';assert s.count(old)==1
 (w/'src/showcase/ShowcaseRateAdmission.php').write_text(s.replace(old,'if(false)'));shutil.copy(r/'tests/showcase-rate-test.php',w/'tests/showcase-rate-test.php')
 p=subprocess.run(['php','tests/showcase-rate-test.php'],cwd=w,capture_output=True,text=True,timeout=30)
 assert p.returncode!=0 and 'expected rejection' in p.stdout+p.stderr,(p.stdout,p.stderr)
 print('Rate limit bypass mutation detected')
