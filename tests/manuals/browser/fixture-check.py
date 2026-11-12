"""HTTP fixture/router contract only, not browser evidence. Requires loopback URL."""
from pathlib import Path
import http.cookiejar
import json
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

fixture=Path(sys.argv[1]).resolve();base=sys.argv[2];php=sys.argv[3]
assert re.fullmatch(r'http://127\.0\.0\.1:[0-9]+',base)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect)
checks=0

def req(path,method='GET'):
    try:r=client.open(urllib.request.Request(base+path,method=method,data=b'' if method=='POST' else None),timeout=5)
    except urllib.error.HTTPError as e:r=e
    with r:return r.status,dict(r.headers),r.read()

def check(condition,label):
    global checks
    assert condition,label;checks+=1;print('PASS '+label)

def control(op):check(subprocess.check_output([php,str(fixture/'control.php'),op],text=True)=='ok','CLI fixture control '+op)

for route in ['/database.sqlite','/fixture.sqlite','/src/config/config.php','/admin/auth.php','/private/','/control.php','/router.php','/admin/products.php','/api/ebay-sync.php']:
    check(req(route)[0]==404,'Unlisted route denied '+route)
check(req('/fixture-login.php')[0]==405,'Synthetic login requires POST')
check(req('/manuals/')[0]==200,'Catalog renders with actual repository')
for ident in [1,2,3,4,5]:
    status,headers,body=req(f'/manuals/view.php?id={ident}')
    check(status==200 and b'data-manual-reader' in body,'Actual reader markup '+str(ident))
    status,headers,body=req(f'/manuals/file.php?id={ident}')
    check(status==200 and body.startswith(b'%PDF-'),'Synthetic PDF bytes '+str(ident))
    check('attachment' in headers.get('Content-Disposition','') and 'no-store' in headers.get('Cache-Control',''),'Safe download headers '+str(ident))
check(req('/public/js/manual-reader.mjs')[1].get('Content-Type')=='application/javascript','Reader module MIME')
check(req('/public/vendor/pdfjs-6.5.438/pdf.worker.mjs')[1].get('Content-Type')=='application/javascript','Actual PDF worker MIME')
check(req('/admin/manuals-preview.php?id=1')[0]==302,'Anonymous private preview denied')
check(req('/fixture-login.php','POST')[0]==200,'Synthetic login uses actual AdminAuth')
check(req('/admin/manuals-preview.php?id=1')[0]==200,'Authenticated private preview')
check(req('/admin/manuals-file.php?id=1')[0]==200,'Authenticated private bytes')
try:
    control('revoke-session');check(req('/admin/manuals-file.php?id=1')[0]==403,'Revoked actor cannot fetch private bytes')
finally:control('restore-session')
check(req('/fixture-login.php','POST')[0]==200,'Login after actor restored')
check(req('/fixture-logout.php','POST')[0]==200,'Synthetic session logout')
check(req('/admin/manuals-file.php?id=1')[0]==302,'Logged-out session cannot fetch private bytes')
try:
    control('withdraw-rights')
    check(req('/manuals/view.php?id=1')[0]==404,'Withdrawn rights hide reader')
    check(req('/manuals/file.php?id=1')[0]==404,'Withdrawn rights block bytes')
finally:control('restore-rights')
check(req('/manuals/file.php?id=1')[0]==200,'Republished synthetic bytes restored')
print(f'{checks} synthetic fixture HTTP checks passed. No browser launched.')
