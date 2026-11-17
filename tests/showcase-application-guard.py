from pathlib import Path
import tempfile,shutil,subprocess
r=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='showcase-app-control-') as d:
 w=Path(d);shutil.copytree(r/'src',w/'src');(w/'tests').mkdir()
 for name in ['showcase-application-http-test.py','showcase-application-router.php']:shutil.copy(r/'tests'/name,w/'tests'/name)
 p=w/'src/showcase/ShowcaseIntakeApplication.php';s=p.read_text();old='$this->rate->admit($identity);';assert s.count(old)==1;p.write_text(s.replace(old,''))
 result=subprocess.run(['python','tests/showcase-application-http-test.py'],cwd=w,capture_output=True,text=True,timeout=30)
 assert result.returncode!=0 and 'Rate denied before decode' in result.stderr,(result.stdout,result.stderr)
 print('Application omitted-quota mutation detected')
