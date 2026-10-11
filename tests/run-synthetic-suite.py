"""Run repository regressions; prepare synthetic assets, never runtime inventory/config."""
from pathlib import Path
import json, shutil, subprocess, sys

ROOT=Path(__file__).resolve().parents[1]
if not shutil.which('php'):
    sys.exit('PHP CLI is required for the synthetic suite.')
(ROOT/'audit').mkdir(exist_ok=True)
(ROOT/'gallery/favicons').mkdir(parents=True,exist_ok=True)
# Existing image regressions expect a source photo at this legacy path. CI's
# sparse checkout excludes real photos; provide an independently generated image.
image=ROOT/'gallery/FLIPANDSTRIP.COM_d00a_018a.jpg'
if not image.exists():
    subprocess.run(['php','-r',
        '$im=imagecreatetruecolor(1280,960); imagefill($im,0,0,imagecolorallocate($im,60,90,120)); imagejpeg($im,$argv[1],90); imagedestroy($im);',
        str(image)],check=True)
results=[]
for path in sorted((ROOT/'tests').glob('*-test.*')):
    runtime={'.php':'php','.py':'python3','.cjs':'node'}.get(path.suffix)
    if not runtime: continue
    try:
        result=subprocess.run([runtime,str(path)],cwd=ROOT,text=True,capture_output=True,timeout=180)
        results.append({'test':path.name,'exit':result.returncode})
        print(('PASS ' if result.returncode==0 else 'FAIL ')+path.name,flush=True)
        if result.returncode:
            print((result.stdout+result.stderr)[-6000:],flush=True)
    except subprocess.TimeoutExpired:
        results.append({'test':path.name,'exit':124})
        print('FAIL '+path.name+' (timeout)',flush=True)
print(json.dumps({'suite':results,'passed':sum(r['exit']==0 for r in results),'total':len(results)},indent=2),flush=True)
sys.exit(int(any(r['exit'] for r in results)))
