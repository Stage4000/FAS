"""Exact shipping-copy and historical identity controls; no app/browser/network."""
from pathlib import Path
import copy, hashlib, json, re, subprocess, tempfile
import heading_runner as h
import shipping_identity as s
ROOT = Path(__file__).resolve().parents[2]
checks = 0

def check(value):
    global checks
    assert value
    checks += 1

def reject(fn):
    try:
        fn()
    except (ValueError, KeyError):
        check(True)
    else:
        raise AssertionError('Unexpected shipping source accepted')

manifest = json.loads((ROOT/'tests/keyboard-browser/heading-sources.json').read_text())
raw = {p:(ROOT/p).read_bytes() for p in h.EXPECTED_PATHS}
check(set(s.HASHES) == set(s.COPIES) == {'product.php', 'cart.php'})
check([len(s.COPIES[p]) for p in ['product.php','cart.php']] == [3,1])
h.verify_candidate(manifest,raw); check(True)
product_before_focus = h.restore_pre_gallery_focus(raw['product.php'])
for mutation in [product_before_focus, raw['product.php'].replace(b'gap-2 p-2"', b'gap-2"'),
                 raw['product.php'].replace(b".product-thumbnail:focus-visible", b".product-thumbnail")]:
    reject(lambda b=mutation:h.verify_candidate(manifest,dict(raw,**{'product.php':b})))
for path in s.HASHES:
    current = product_before_focus if path == 'product.php' else raw[path]
    prior = s.restore(path,current)
    check(h.sha256(current) == s.HASHES[path][0])
    check(h.sha256(prior) == s.HASHES[path][1] and prior != current)
    check(s.restore(path,prior) == prior)
    reject(lambda p=path,b=prior:h.verify_candidate(manifest,dict(raw,**{p:b})))
    mutants = [current+b'changed',current[:-1],b'']
    for old,new in s.COPIES[path]:
        old,new = old.encode(),new.encode()
        check(current.count(new) == 1)
        mutants += [current.replace(new,old,1),current+new,current.replace(new,b'changed',1)]
    for bad in mutants:
        check(s.restore(path,bad) == bad)
        reject(lambda p=path,b=bad:h.verify_candidate(manifest,dict(raw,**{p:b})))
    pins = s.HASHES[path]
    try:
        s.HASHES[path] = (pins[0],'0'*64)
        reject(lambda p=path,b=current:s.restore(p,b))
    finally:
        s.HASHES[path] = pins
check(s.restore('other.php',b'unchanged') == b'unchanged')
fixture = {p:h.storage_identity.restore(p,b) for p,b in raw.items()}
h.verify_candidate(manifest,fixture); check(True)
h.cart_restoration(fixture,h.decode_manifest(manifest)); check(True)
h.residual_restoration(fixture); check(True)
check(h.sha256(s.restore('product.php',product_before_focus)) == h.GALLERY_FIXED_SHA256)
check(h.sha256(h.restore_pre_gallery_selection(raw['product.php'])) == h.GALLERY_PREIMAGE_SHA256)
check(h.sha256(h.storage_identity.restore('cart.php',raw['cart.php'],strict=True)) == h.storage_identity.manifest()['files']['cart.php']['before_sha256'])
base = {'entries':[{'path':'src/runtime.php','mode':'100644','type':'blob','sha':'base'}]}
tree = {'src/runtime.php':{'mode':'100644','type':'blob','sha':'base'}}
tree.update({p:{'mode':'100644','type':'blob','sha':'reviewed'} for p in h.INTEGRATION_PATHS})
tree.update(copy.deepcopy(h.REVIEWED_OVERLAY))
historical_inputs = {**h.SHIPPING_INTEGRATION_BLOBS, **h.COUPON_SUMMARY_BLOBS}
reviewed = h.manuals_policy(ROOT, checkout=False)['overlay']
current_inputs = {path: reviewed.get(path, entry) for path, entry in historical_inputs.items()}
tree.update(copy.deepcopy(current_inputs))
h.verify_tree(base,tree,{},current_inputs); check(True)
for path,expected in current_inputs.items():
    data = (ROOT/path).read_bytes()
    check(hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest() == expected['sha'])
    for field,value in [('sha','0'*40),('mode','100755'),('type','tree')]:
        bad = copy.deepcopy(tree);bad[path][field] = value
        reject(lambda b=bad:h.verify_tree(base,b,{},current_inputs))
    bad = copy.deepcopy(tree);del bad[path]
    reject(lambda:h.verify_tree(base,bad,{},current_inputs))
# Both browser suites must bind their actual runtime and fixture Cart inputs.
for name in ['storage-unavailable.cjs','malformed-hydration.cjs']:
    source = (ROOT/'tests/keyboard-browser'/name).read_text()
    match = re.search(r"const CART='([0-9a-f]{64})';",source)
    check(match is not None and match.group(1) == h.sha256(raw['cart.php']))
    check(source.count("assert.equal(hash(fs.readFileSync(path.join(__dirname,'../../cart.php'))),CART)") == 1)
    check(source.count("assert.equal(fixture.source_sha256['cart.php'],CART)") == 1)
# Exercise Git's actual non-cone sparse pattern matching, not a guessed glob rule.
workflow = (ROOT/'.github/workflows/keyboard-browser.yml').read_text().splitlines()
start = next(i for i,line in enumerate(workflow) if line.strip() == 'sparse-checkout: |') + 1
end = start
while end < len(workflow) and workflow[end].startswith('            '):
    end += 1
patterns = [line.strip() for line in workflow[start:end]]
required = set(current_inputs)
with tempfile.TemporaryDirectory(prefix='shipping-sparse-') as temporary:
    repository = Path(temporary)
    subprocess.run(['git','init','-q',temporary],check=True,capture_output=True)
    def included(rules):
        (repository/'.git/info/exclude').write_text('\n'.join(rules)+'\n')
        result = subprocess.run(['git','check-ignore','--no-index','-z','--stdin'],cwd=repository,
                                input=b''.join(p.encode()+b'\0' for p in sorted(required)),
                                capture_output=True,check=True)
        return set(result.stdout.decode().split('\0')[:-1])
    check(included(patterns) == required)
    for path in ['api/shipping-estimate.php','.github/workflows/keyboard-browser.yml']:
        line = '/' + path
        check(patterns.count(line) == 1)
        check(path not in included([p for p in patterns if p != line]))
print(json.dumps({'passed':True,'checks':checks,'scope':'offline exact shipping copy, gallery/storage chains and finite tree controls'}))
