"""HTTP checks on the synthetic fixture only. Pass fixture directory and loopback base URL."""
from pathlib import Path
import urllib.request, urllib.error, urllib.parse, http.cookiejar, re, html, sqlite3, json,sys,hashlib
OUT=Path(sys.argv[1]).resolve();BASE=sys.argv[2]
assert BASE.startswith('http://127.0.0.1:')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None

def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect)
admin=client();anon=client();checks=[]
def req(path,data=None,who=admin,upload=None,method=None):
    headers={}
    if upload is not None:
        boundary='FASManualSyntheticBoundary';chunks=[]
        for k,v in data.items():chunks += [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()]
        chunks += [f'--{boundary}\r\nContent-Disposition: form-data; name="pdf"; filename="synthetic.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode(),upload,b'\r\n',f'--{boundary}--\r\n'.encode()]
        data=b''.join(chunks);headers['Content-Type']='multipart/form-data; boundary='+boundary
    elif data is not None:data=urllib.parse.urlencode(data).encode()
    try:r=who.open(urllib.request.Request(BASE+path,data=data,headers=headers,method=method),timeout=15)
    except urllib.error.HTTPError as e:r=e
    with r:return r.status,dict(r.headers),r.read()
def text(result):return result[2].decode(errors='replace')
def check(value,label):
    assert value,label
    checks.append(label);print('PASS',label)
def field(body,name):return html.unescape(re.search('name="'+name+'" value="([^"]*)"',body)[1])
def tokens(path):
    body=text(req(path));return {name:field(body,name) for name in ['csrf_token','revision','request_key']}
config=OUT/'site/src/config/config.php';original=config.read_text()
def release_probe(path, expected):
    """Observe the actual config require on this request without retaining data."""
    route={'/manuals/':'catalog','/manuals/view.php?id=1':'viewer','/manuals/file.php?id=1':'file'}[path]
    marker=OUT/'config-observation.json';marker.unlink(missing_ok=True)
    response=req(path,who=anon)
    try:
        observation=json.loads(marker.read_text())
        if not isinstance(observation,dict):observation={}
    except (OSError,ValueError):observation={}
    digest=hashlib.sha256(expected.encode()).hexdigest()
    note={key:observation.get(key) for key in ['php_version','sapi','loaded_flag','opcache_loaded','opcache_enable','opcache_enable_cli','opcache_validate_timestamps','opcache_revalidate_path','opcache_revalidate_freq','opcache_file_update_protection']}
    note.update(case='string_release',route=route,observation_present=bool(observation),
                python_disk_matches_expected=config.read_text()==expected,
                php_disk_matches_expected=observation.get('disk_sha256')==digest,
                http_status=response[0],response_bytes=len(response[2]),
                body_kind=('pdf' if response[2].startswith(b'%PDF-') else 'catalog_not_found' if response[2]==b'Page not found.' else 'manual_not_found' if response[2]==b'Manual not found.' else 'other'),
                php_diagnostic_body=any(value in response[2] for value in [b'Warning:',b'Fatal error:',b'Parse error:',b'Notice:',b'Deprecated:']))
    # Only the outer stage sanitizer's fixed schema may enter CI reports.
    print('FAS_RELEASE_CONFIG_RECEIPT '+json.dumps(note,sort_keys=True),flush=True)
    return response,note
check(req('/manuals/',who=anon)[0]==200,'Public catalog available with explicit release flag')
check('Example Motors' in text(req('/manuals/?q=ZX&manufacturer=Example+Motors',who=anon)),'Manufacturer/model metadata displayed')
check('No matching manuals' in text(req('/manuals/?q=unfindable',who=anon)),'No-match state')
for path in ['/manuals/?q%5B%5D=bad','/manuals/?manufacturer%5B%5D=bad']:
    check(req(path,who=anon)[0]==200,'Malformed filter safely ignored '+path)
check(req('/manuals/?q='+('a'*161),who=anon)[0]==400,'Oversized search rejected')
for p in ['/manuals/?q=%FF','/manuals/?manufacturer=%FF','/manuals/?q='+('+'.join(['term']*13))]:
    check(req(p,who=anon)[0]==400,'Invalid UTF8 or excessive search terms rejected '+p)
for p in ['/manuals/','/manuals/view.php?id=1','/manuals/file.php?id=1']:
    check('no-store' in req(p,who=anon)[1].get('Cache-Control',''),'Public response is no-store '+p)
for ident in ['-1','99999999999999999999999999999999999']:
    check(req('/manuals/view.php?id='+ident,who=anon)[0]==404,'Out-of-range manual id rejected '+ident)
for path in ['/admin/manuals.php','/admin/manuals-edit.php','/admin/manuals-preview.php?id=1','/admin/manuals-file.php?id=1']:
    check(req(path,who=anon)[0]==302,'Anonymous private access redirects '+path)
check(req('/fixture-login.php')[0]==200,'Actual AdminAuth synthetic login')
check(req('/admin/manuals.php')[0]==200,'Authenticated list')
preview=req('/admin/manuals-preview.php?id=1');check(preview[0]==200 and preview[1]['Cache-Control']=='private, no-store','Private preview no-store')
check('Private preview' in text(preview),'Private preview visibly labelled')
pdf=req('/manuals/file.php?id=1',who=anon)
check(pdf[0]==200 and pdf[2].startswith(b'%PDF-'),'Published PDF fetched')
check(pdf[1]['Content-Disposition'].startswith('attachment') and pdf[1]['X-Content-Type-Options']=='nosniff' and 'sandbox' in pdf[1]['Content-Security-Policy'],'PDF attachment nosniff and sandbox headers')
check(pdf[1]['Cache-Control']=='private, no-store' and pdf[1]['X-Robots-Tag']=='noindex, nofollow','PDF cache and indexing headers')
check(req('/manuals/file.php?id=1',who=anon,method='HEAD')[2]==b'','HEAD returns no PDF bytes')
check(req('/manuals/file.php?id=1',data={},who=anon)[0]==405,'PDF endpoint denies mutation methods')
check(req('/manuals/view.php?id=not-an-id',who=anon)[0]==404,'Invalid record id rejected')
check(req('/manuals/file.php?id[]=1',who=anon)[0]==404,'Array record id rejected')
data=dict(title='Uploaded synthetic manual <script>',manufacturer='Fixture Maker',model='ZZ Test',years='2026',language='English',description='Synthetic upload test',rights='unverified',rights_note='',source_url='')
t=tokens('/admin/manuals-edit.php')
check(req('/admin/manuals-edit.php',{**data,**t,'action':'draft','csrf_token':'bad'},upload=pdf[2])[0]==403,'Upload requires CSRF')
check(req('/admin/manuals-edit.php',{**data,**t,'action':'draft'},upload=b'<html>not pdf</html>')[0]==422,'Renamed HTML upload rejected')
r=req('/admin/manuals-edit.php',{**data,**t,'action':'draft'},upload=pdf[2]);check(r[0]==303,'Multipart PDF upload succeeds')
new_id=int(re.search(r'id=(\d+)',r[1]['Location'])[1]);path=f'/admin/manuals-edit.php?id={new_id}'
check(req('/manuals/file.php?id='+str(new_id),who=anon)[0]==404,'Unreviewed original cannot be fetched publicly')
check(req('/manuals/view.php?id='+str(new_id),who=anon)[0]==404,'Unreviewed reader route not public')
check('&lt;script&gt;' in text(req(path)) and '<script>' not in text(req(path)),'Metadata HTML escaped in editor')
t=tokens(path)
check(req(path,{**data,**t,'action':'publish','reviewed':'1','password':'fixture-only'})[0]==422,'Rights gate blocks attempted publish')
cleared={**data,'rights':'cleared','rights_note':'Original synthetic fixture, no third-party material.','reviewed':'1','action':'publish'}
check(req(path,{**cleared,**t})[0]==403,'Publishing requires password reauthentication')
check(req(path,{**cleared,**t,'password':'fixture-only'})[0]==303,'Reviewed cleared PDF published')
check(req('/manuals/file.php?id='+str(new_id),who=anon)[0]==200,'Published file route succeeds')
check('Original synthetic fixture, no third-party' not in text(req('/manuals/',who=anon)),'Rights evidence absent from public catalog')
check(req(path,{**cleared,**t,'action':'draft'})[0]==409,'Stale editor revision cannot overwrite publication')
new=tokens(path)
check(req(path,{**data,**new,'title':'Private replacement title','rights':'cleared','rights_note':cleared['rights_note'],'action':'draft'})[0]==303,'Private metadata draft saved')
check('Private replacement title' not in text(req('/manuals/view.php?id='+str(new_id),who=anon)),'Draft title does not leak through public reader')
new=tokens(path)
check(req(path,{**data,**new,'rights':'restricted','action':'draft'})[0]==303,'Rights restricted by saved draft')
check(req('/manuals/file.php?id='+str(new_id),who=anon)[0]==404,'Rights restriction withdraws PDF bytes immediately')
check(req('/manuals/view.php?id='+str(new_id),who=anon)[0]==404,'Rights restriction withdraws viewer')
try:
    assert original.count("'public_enabled'=>true")==1,'Synthetic release flag mutation must be exact'
    expected=original.replace("'public_enabled'=>true","'public_enabled'=>'true'")
    config.write_text(expected)
    for p in ['/manuals/','/manuals/view.php?id=1','/manuals/file.php?id=1']:
        response,observed=release_probe(p,expected)
        check(response[0]==404,'String release flag fails closed '+p)
        assert observed['observation_present'] and observed['loaded_flag']=='string_true' and observed['python_disk_matches_expected'] and observed['php_disk_matches_expected'], 'Actual endpoint did not observe the exact synthetic string release config'
    check(req('/admin/manuals-preview.php?id=1')[0]==200,'Admin private preview works during release hold')
finally:config.write_text(original)
with sqlite3.connect(OUT/'site/fixture.sqlite') as db:db.execute('UPDATE admin_users SET is_active=0 WHERE id=1')
try:
    check(req('/admin/manuals-file.php?id=1')[0]==403,'Deactivated admin session loses PDF access')
finally:
    with sqlite3.connect(OUT/'site/fixture.sqlite') as db:db.execute('UPDATE admin_users SET is_active=1 WHERE id=1')
report={'passed':len(checks),'checks':checks,'scope':'Actual manuals backend/admin/auth; synthetic database/files; public layout and admin navigation adapters; no browser acceptance'}
(OUT/'http-results.json').write_text(json.dumps(report,indent=2));print(len(checks),'checks passed')
