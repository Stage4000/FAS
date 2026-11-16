from pathlib import Path
import tempfile,shutil,subprocess
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='showcase-concurrency-control-') as d:
 w=Path(d);(w/'src/showcase').mkdir(parents=True);(w/'tests').mkdir()
 source=(root/'src/showcase/ShowcaseConcurrency.php').read_text();needle='if(flock($h,LOCK_EX|LOCK_NB,$busy))';assert source.count(needle)==1
 (w/'src/showcase/ShowcaseConcurrency.php').write_text(source.replace(needle,'if(true)'))
 shutil.copy(root/'tests/showcase-concurrency-test.php',w/'tests/showcase-concurrency-test.php')
 p=subprocess.run(['php','tests/showcase-concurrency-test.php'],cwd=w,capture_output=True,text=True,timeout=20)
 assert p.returncode!=0 and 'expected rejection' in p.stdout+p.stderr,(p.stdout,p.stderr)
 print('Concurrency lock-bypass mutation detected')
