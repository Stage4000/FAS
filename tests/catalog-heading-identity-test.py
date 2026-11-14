"""Exact catalog restoration compatibility; no server, browser, or network."""
from pathlib import Path
import hashlib,json,sys
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tests/keyboard-browser'))
import heading_runner as h
manifest=json.loads((ROOT/'tests/keyboard-browser/heading-sources.json').read_text())
candidate={e['path']:(ROOT/e['path']).read_bytes() for e in manifest['files']}
checks=0
def check(value):
 global checks
 checks+=1
 assert value
def reject(callback):
 global checks
 checks+=1
 try:callback()
 except (ValueError,KeyError):return
 raise AssertionError('Unexpected source was accepted')
new=candidate['products.php'];published=h.restore_pre_zero_search('products.php',new);old=h.restore_pre_catalog_review(published)
check(hashlib.sha256(published).hexdigest()=='60885c5aa58f3e343f94528e7d8c4fab06b8bc3b2b09400acc5fa19936578c33')
check(hashlib.sha256(old).hexdigest()=='70eaceb74c05cf1d73bd4af201653cdc9692cce99daee2e36832fb0820706d23')
check(next(e for e in manifest['files'] if e['path']=='products.php')['candidate_sha256']==h.CATALOG_PREIMAGE_SHA256)
h.verify_candidate(manifest,candidate);check(True)
for bad in [old,new+b'\ninjected',new[:-1],new.replace(b'<h1',b'<h2',1),new.replace(b'if (existing === null)',b'if (false)',1),b'<script>unexpected</script>'+new]:
 check(bad!=new);reject(lambda b=bad:h.verify_candidate(manifest,dict(candidate,**{'products.php':b})))
missing=dict(candidate);del missing['products.php'];reject(lambda:h.verify_candidate(manifest,missing))
for path,data in candidate.items():reject(lambda p=path,d=data:h.verify_candidate(manifest,dict(candidate,**{p:d+b'\nchanged'})))
original=h.CATALOG_PREIMAGE_SHA256
try:
 h.CATALOG_PREIMAGE_SHA256='0'*64
 reject(lambda:h.restore_pre_catalog_review(published))
finally:h.CATALOG_PREIMAGE_SHA256=original
print(json.dumps({'checks':checks,'passed':True,'scope':'Pure source identity; no hosted/browser acceptance'}))

for path in ('products.php','api/products.php'):
 data=candidate[path];before=h.restore_pre_zero_search(path,data)
 check(h.sha256(data)==h.ZERO_SEARCH_HASHES[path][0]);check(h.sha256(before)==h.ZERO_SEARCH_HASHES[path][1])
 for bad in (before,data+b'changed',data[:-1],data.replace(h.ZERO_SEARCH_NEW,h.ZERO_SEARCH_OLD,1),b''):
  reject(lambda p=path,b=bad:h.verify_candidate(manifest,dict(candidate,**{p:b})))
  reject(lambda p=path,b=bad:h.restore_pre_zero_search(p,b))
 missing=dict(candidate);del missing[path];reject(lambda:h.verify_candidate(manifest,missing))
 prior=h.ZERO_SEARCH_HASHES[path]
 try:
  h.ZERO_SEARCH_HASHES[path]=(prior[0],'0'*64)
  reject(lambda:h.restore_pre_zero_search(path,data))
 finally:h.ZERO_SEARCH_HASHES[path]=prior
print(json.dumps({'checks':checks,'passed':True,'scope':'Exact catalog and API zero-query identity, not hosted acceptance'}))
