import os
from pathlib import Path
import tempfile,shutil,subprocess
r=Path(__file__).resolve().parents[1]
for label,old,new in [('consent binding',"return hash_hmac('sha256',$this->request->consentVersion().chr(0).$this->request->consentText(),$tokens['csrf_token']);","return $tokens['csrf_token'];"),('strict enable',"($this->enabled)()!==true","!($this->enabled)()")]:
 with tempfile.TemporaryDirectory(prefix='showcase-controller-control-') as d:
  w=Path(d);shutil.copytree(r/'src',w/'src');shutil.copytree(r/'tests',w/'tests');p=w/'src/showcase/ShowcaseIntakeController.php';s=p.read_text();assert s.count(old)==1;p.write_text(s.replace(old,new))
  result=subprocess.run(['python','tests/showcase-controller-http-test.py'],cwd=w,capture_output=True,text=True,timeout=120,creationflags=(subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0));assert result.returncode!=0 and ('Changed consent' if label=='consent binding' else 'Strict true') in result.stderr,(label,result.stdout,result.stderr);print('Detected mutation:',label)
