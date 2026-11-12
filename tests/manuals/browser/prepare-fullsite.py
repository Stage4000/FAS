"""Overlay exact main presentation shells onto a disposable synthetic reader fixture."""
from pathlib import Path
import hashlib,json,os,shutil,sys
sys.dont_write_bytecode=True
from prepare import build
from source_guard import ROOT,require

def fullsite(out,php):
 receipt=build(out,php);out=Path(out);site=out/'site'
 pins=json.loads(Path(__file__).with_name('fullsite-pins.json').read_text())
 for rel,pin in pins.items():
  source=ROOT/rel;data=source.read_bytes()
  require(hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()==pin,'Shared shell pin mismatch: '+rel)
  dest=site/rel;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(source,dest)
  receipt['source_copies_sha256'][rel]=hashlib.sha256(data).hexdigest()
 for rel in ['site/includes/header.php','site/includes/footer.php','site/admin/includes/nav.php','site/admin/includes/footer.php']:
  del receipt['generated_sha256'][rel]
 # Do not expose arbitrary site PHP routes: only these two manual admin pages.
 router=out/'router.php';text=router.read_text();needle="'/admin/login.php'=>'/admin/login.php'"
 require(needle in text,'Expected route anchor absent')
 text=text.replace(needle,"'/admin/manuals.php'=>'/admin/manuals.php','/admin/manuals-edit.php'=>'/admin/manuals-edit.php',"+needle)
 # Exact local CSS/presentation-JS assets only. Business JS is blocked by browser;
 # its bytes are copied solely for unmodified footer filemtime calls.
 allowed=['/gallery/hero-image.png','/gallery/favicons/favicon.png','/public/css/style.css','/admin/css/admin-style.css','/admin/css/admin-notifications.css','/public/js/theme-toggle.js','/public/js/runtime-guard.js','/public/js/timezone.js','/public/js/animations.js','/public/js/manual-editor.js']
 text=text.replace("$static=['/public/css/manuals.css'",'$static='+repr(allowed)[:-1]+",'/public/css/manuals.css'")
 text=text.replace("!str_starts_with($file,$site.'/public/')","!(str_starts_with($file,$site.'/public/')||str_starts_with($file,$site.'/admin/css/')||str_starts_with($file,$site.'/gallery/'))")
 text=text.replace("$mime=[", "$mime=['png'=>'image/png',")
 router.write_text(text);receipt['generated_sha256']['router.php']=hashlib.sha256(router.read_bytes()).hexdigest()
 receipt['layout']='Exact main public header/footer/admin nav/footer/style/theme scripts; business scripts excluded by browser policy, banners absent and integrations disabled in synthetic config.'
 (out/'browser-fixture.json').write_text(json.dumps(receipt,indent=2));return receipt
if __name__=='__main__':print(json.dumps(fullsite(sys.argv[1],os.environ['FAS_TEST_PHP']),indent=2))
