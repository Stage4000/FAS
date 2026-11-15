"""Pure exact CSS/heading compatibility/tree controls. No server/browser/network."""
from pathlib import Path
import copy, hashlib, json
import heading_runner as h

checks=0
def check(value):
    global checks
    checks+=1
    assert value
def reject(fn):
    global checks
    checks+=1
    try: fn()
    except (ValueError,KeyError): return
    raise AssertionError('Mutation was incorrectly accepted')

root=Path(__file__).resolve().parents[2]
compatibility=h.manuals_policy(root, checkout=False)['overlay']
candidate=(root/'public/css/style.css').read_bytes()
check(h.sha256(candidate)==h.CATALOG_SCROLL_AFTER_SHA256)
baseline=h.restore_pre_catalog_scroll(candidate)
check(h.sha256(baseline)==h.CATALOG_SCROLL_BEFORE_SHA256)
check(candidate.count(h.CATALOG_SCROLL_NEW)==1)
check(candidate.replace(h.CATALOG_SCROLL_NEW,h.CATALOG_SCROLL_OLD,1)==baseline)
check(h.heading_identity_bytes('public/css/style.css',candidate)==baseline)
check(h.heading_identity_bytes('another.css',candidate)==candidate)
manifest=json.loads((Path(__file__).parent/'heading-sources.json').read_text())
css_entry=next(e for e in manifest['files'] if e['path']=='public/css/style.css')
check(css_entry['candidate_sha256']==h.CATALOG_SCROLL_BEFORE_SHA256)
one={'files':[css_entry]}
h.verify_candidate(one,{'public/css/style.css':candidate});check(True)
for mutation in [baseline,candidate+b'changed',candidate+h.CATALOG_SCROLL_NEW,
    candidate.replace(h.CATALOG_SCROLL_NEW,b'#main-content, #productContent { scroll-margin-top: 6rem; }'),
    candidate.replace(h.CATALOG_SCROLL_NEW,b'#main-content, #productsContent { scroll-margin-top: 0; }')]:
    reject(lambda:h.restore_pre_catalog_scroll(mutation))
    reject(lambda:h.verify_candidate(one,{'public/css/style.css':mutation}))
check(h.decode_manifest(manifest)['public/css/style.css']!=candidate)

# Exact overlay entries override existing pins; no extra generic integration exemption.
base={'entries':[{'path':'src/runtime.php','mode':'100644','type':'blob','sha':'base'}]}
tree={'src/runtime.php':{'mode':'100644','type':'blob','sha':'base'}}
tree.update({p:{'mode':'100644','type':'blob','sha':'reviewed'} for p in h.INTEGRATION_PATHS})
tree.update(copy.deepcopy(h.REVIEWED_OVERLAY))
h.verify_tree(base,tree,{});check(True)
for path,entry in h.CATALOG_SCROLL_BLOBS.items():
    check(entry==h.REVIEWED_OVERLAY[path])
    check(entry==h.QUANTITY_BLOBS[path])
    data=(root/path).read_bytes()
    blob=hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()
    # Old overlay identities remain tested above; exact current guard-only
    # postimages are pinned by the reviewed manuals compatibility policy.
    check(blob==compatibility.get(path,entry)['sha'])
    for field,value in [('sha','0'*40),('mode','100755'),('type','tree')]:
        changed=copy.deepcopy(tree);changed[path][field]=value
        reject(lambda:h.verify_tree(base,changed,{}))
    changed=copy.deepcopy(tree);del changed[path]
    reject(lambda:h.verify_tree(base,changed,{}))
for path in ['src/runtime.php','api/growth.php','tests/unreviewed.cjs','public/css/unreviewed.css']:
    changed=copy.deepcopy(tree);changed[path]={'mode':'100644','type':'blob','sha':'unexpected'}
    reject(lambda:h.verify_tree(base,changed,{}))
print(json.dumps({'passed':True,'checks':checks,'scope':'pure CSS/heading/tree mutations; not browser acceptance'}))
