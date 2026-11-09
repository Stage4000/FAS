"""Pure fixture/identity/report helpers only. Never starts PHP, Node, browser or network."""
import copy, hashlib, json, sqlite3, tempfile
from pathlib import Path
import heading_runner as h

suite=Path(__file__).parent
manifest=json.loads((suite/'heading-sources.json').read_text())
baseline=h.decode_manifest(manifest)
checks=0
def check(condition):
    global checks
    checks+=1
    assert condition
def rejected(fn):
    global checks
    checks+=1
    try: fn()
    except (ValueError, KeyError): return
    raise AssertionError('Mutation was incorrectly accepted')

check(len(baseline)==9)
base_map=json.loads((suite/'heading-base-tree.json').read_text())
check(h.base_tree_hash(base_map['entries'])==base_map['base_tree'])
bad_entries=copy.deepcopy(base_map['entries']);bad_entries[0]['sha']='0'*40
check(h.base_tree_hash(bad_entries)!=base_map['base_tree'])
for field,value in [('base','wrong')]:
    bad=copy.deepcopy(manifest);bad[field]=value;rejected(lambda:h.decode_manifest(bad))
for index in range(9):
    bad=copy.deepcopy(manifest);bad['files'][index]['baseline_sha256']='0'*64
    rejected(lambda:h.decode_manifest(bad))
bad=copy.deepcopy(manifest);bad['files'].append(bad['files'][0]);rejected(lambda:h.decode_manifest(bad))
bad=copy.deepcopy(manifest);bad['files'][0]['path']='../config.php';rejected(lambda:h.decode_manifest(bad))
# GBP source is exact, reversible, and cannot mask unrelated footer edits.
footer = (suite.parents[1]/'includes/footer.php').read_bytes()
pre_gbp = h.restore_pre_gbp_footer(footer)
check(h.sha256(pre_gbp)==h.GBP_FOOTER_BEFORE_SHA256)
check(pre_gbp + b'' != footer and len(footer)-len(pre_gbp)==230)
for bad_footer in [pre_gbp, footer+b'changed', footer+h.GBP_FOOTER_LINE,
                   footer.replace(b'Find us on Google',b'Changed link'),
                   footer.replace(b'noopener noreferrer',b'noopener',1)]:
    rejected(lambda:h.restore_pre_gbp_footer(bad_footer))

# One valid synthetic mapping, then exactly one changed path per negative test.
synthetic={path:('synthetic candidate '+path).encode() for path in baseline}
synthetic_manifest=copy.deepcopy(manifest)
for entry in synthetic_manifest['files']:entry['candidate_sha256']=h.sha256(synthetic[entry['path']])
h.verify_candidate(synthetic_manifest,synthetic);check(True)
for path in baseline:
    changed=dict(synthetic);changed[path]=baseline[path]
    rejected(lambda:h.verify_candidate(synthetic_manifest,changed))
cart_candidate=dict(baseline)
cart_candidate['cart.php']=baseline['cart.php'].replace(b'<h4 class="h5 fw-bold text-center mb-3">Popular Parts Shoppers Are Viewing</h4>',b'<h2 class="h5 fw-bold text-center mb-3">Popular Parts Shoppers Are Viewing</h2>')
for path,changes in manifest['residual_changes'].items():
    for old,new,code in changes:
        cart_candidate[path]=cart_candidate[path].replace(old.encode(),new.encode())
restored=h.cart_restoration(cart_candidate,baseline)
check(h.residual_restoration(restored,verify=False)==baseline)
check(restored['includes/ebay-seller-rating.php']==cart_candidate['includes/ebay-seller-rating.php'])
for path,changes in manifest['residual_changes'].items():
    for old,new,code in changes:
        check(h.residual_restoration(cart_candidate,verify=False)[path].count(old.encode())==1)
        bad=dict(cart_candidate);bad[path]=bad[path].replace(new.encode(),b'missing')
        rejected(lambda:h.residual_restoration(bad,verify=False))
bad=dict(cart_candidate);bad['cart.php']+=b'changed';rejected(lambda:h.cart_restoration(bad,baseline))

# Exact clipboard delta is normalized for historical heading identity only.
product=(suite.parents[1]/'product.php').read_bytes()
check(h.sha256(product)==h.SHARE_FIXED_SHA256)
restored_product=h.heading_identity_bytes('product.php',product)
check(h.sha256(restored_product)==h.SHARE_PREIMAGE_SHA256)
check(h.heading_identity_bytes('other.php',product)==product)
check(h.heading_identity_bytes('product.php',restored_product)==restored_product)
product_manifest={'files':[next(e for e in manifest['files'] if e['path']=='product.php')]}
h.verify_candidate(product_manifest,{'product.php':product});check(True)
for mutation in [restored_product, product+h.SHARE_NEW, product.replace(b"execCommand('copy')",b"execCommand('cut')"), product+b'changed',product.replace(b'Clipboard copy was not accepted',b'changed'),product.replace(b'<h2',b'<h4',1)]:
    rejected(lambda:h.verify_candidate(product_manifest,{'product.php':mutation}))
check(h.heading_identity_bytes('product.php',product+b'changed')==product+b'changed')

row=lambda key:{'key':key,'visual':[{'text':'Synthetic heading','visible':True,'style':{'fontSize':'20px'},'rect':{'x':0,'y':0,'width':100,'height':20}}]}
empty={'rows':[row(k) for k in sorted(h.EXPECTED_KEYS)],'semantic_failures':[],'infrastructure_failures':[]}
reports={name:copy.deepcopy(empty) for name in h.VARIANTS}
required=['HOME_CATEGORY','HOME_FEATURE','CATALOG_CARD','CATALOG_EMPTY','PRODUCT_SECTION','FOOTER','CART_PARENT','SHARED_CARD']
reports['baseline']['semantic_failures']=[{'code':c} for c in required+sorted(h.RESIDUAL_CODES)]
reports['residual-restored']['semantic_failures']=[{'code':c} for c in sorted(h.RESIDUAL_CODES)]
reports['cart-parent-restored']['semantic_failures']=[{'code':'CART_PARENT'}]
check(h.evaluate_reports(reports)==[])
for variant in ['baseline','residual-restored']:
    for code in (set(required)|h.RESIDUAL_CODES if variant=='baseline' else h.RESIDUAL_CODES):
        bad=copy.deepcopy(reports);bad[variant]['semantic_failures']=[f for f in bad[variant]['semantic_failures'] if f['code']!=code]
        check(bool(h.evaluate_reports(bad)))
bad=copy.deepcopy(reports);bad['residual-restored']['semantic_failures'].append({'code':'FOOTER'});check(bool(h.evaluate_reports(bad)))
for variant in h.VARIANTS:
    bad=copy.deepcopy(reports);bad[variant]['rows'][0]['visual'].append(copy.deepcopy(bad[variant]['rows'][0]['visual'][0]));check(bool(h.evaluate_reports(bad)))
for variant in reports:
    for mutation in ['missing','duplicate','infra','unknown_key']:
        bad=copy.deepcopy(reports)
        if mutation=='missing':bad[variant]['rows'].pop()
        if mutation=='duplicate':bad[variant]['rows'][-1]=bad[variant]['rows'][0]
        if mutation=='unknown_key':bad[variant]['rows'][0]['key']='unknown'
        if mutation=='infra':bad[variant]['infrastructure_failures']=['blocked request']
        check(bool(h.evaluate_reports(bad)))
bad=copy.deepcopy(reports);bad['candidate']['semantic_failures']=[{'code':'FOOTER'}];check(bool(h.evaluate_reports(bad)))
bad=copy.deepcopy(reports);bad['baseline']['semantic_failures']=[];check(bool(h.evaluate_reports(bad)))
bad=copy.deepcopy(reports);bad['baseline']['semantic_failures'].append({'code':'TARGET_EXPOSED'});check(bool(h.evaluate_reports(bad)))
bad=copy.deepcopy(reports);bad['cart-parent-restored']['semantic_failures']=[];check(bool(h.evaluate_reports(bad)))
bad=copy.deepcopy(reports);bad['cart-parent-restored']['semantic_failures'].append({'code':'FOOTER'});check(bool(h.evaluate_reports(bad)))
check(h.php_diagnostics('[date] 127.0.0.1 [200]: GET /products')==[])
for message in ['PHP Warning: test','PHP Fatal error: test','PHP Deprecated: test','Uncaught Error: test','SQLSTATE[HY000]: test']:
    check(bool(h.php_diagnostics(message)))
for field,value in [('text','changed'),('visible',False),('style',{'fontSize':'40px'}),('rect',{'x':0,'y':0,'width':102,'height':20})]:
    bad=copy.deepcopy(reports);bad['candidate']['rows'][0]['visual'][0][field]=value;check(bool(h.evaluate_reports(bad)))

base={'entries':[{'path':'src/runtime.php','mode':'100644','type':'blob','sha':'base'}]}
tree={'src/runtime.php':{'mode':'100644','type':'blob','sha':'base'}}
tree.update({p:{'mode':'100644','type':'blob','sha':'reviewed'} for p in h.INTEGRATION_PATHS})
tree.update(copy.deepcopy(h.REVIEWED_OVERLAY))
h.verify_tree(base,tree,{});check(True)
for path in h.REVIEWED_OVERLAY:
    for field,value in [("sha","0"*40),("mode","100755"),("type","tree")]:
        bad=copy.deepcopy(tree);bad[path][field]=value;rejected(lambda:h.verify_tree(base,bad,{}))
    bad=copy.deepcopy(tree);del bad[path];rejected(lambda:h.verify_tree(base,bad,{}))
for path,entry in [('src/runtime.php',{'mode':'100644','type':'blob','sha':'drift'}),('tests/unreviewed.php',{'mode':'100644','type':'blob','sha':'new'})]:
    bad=copy.deepcopy(tree);bad[path]=entry;rejected(lambda:h.verify_tree(base,bad,{}))
bad=copy.deepcopy(tree);del bad['src/runtime.php'];rejected(lambda:h.verify_tree(base,bad,{}))
bad=copy.deepcopy(tree);del bad[next(iter(h.INTEGRATION_PATHS))];rejected(lambda:h.verify_tree(base,bad,{}))
bad=copy.deepcopy(tree);bad[next(iter(h.INTEGRATION_PATHS))]['mode']='120000';rejected(lambda:h.verify_tree(base,bad,{}))

# Own temporary synthetic SQLite only; no application source is executed.
with tempfile.TemporaryDirectory(prefix='heading-helper-unit-') as tmp:
    site=Path(tmp);(site/'database').mkdir();database=site/'database/flipandstrip.db'
    with sqlite3.connect(database) as db:
        db.execute('CREATE TABLE products(id INTEGER PRIMARY KEY,name TEXT,sku TEXT,created_at TEXT,updated_at TEXT,ebay_item_id TEXT)')
        db.execute('CREATE TABLE orders(id INTEGER PRIMARY KEY)')
        db.execute("INSERT INTO products VALUES(1,'Synthetic test part','TEST-1','2026-09-30','2026-09-30',NULL)")
    h.seed_seller(site)
    with sqlite3.connect(database) as db:
        seller=db.execute('SELECT seller_name,feedback_score,positive_feedback_percent FROM ebay_seller_rating_cache WHERE store_name=?',('synthetic-heading-store',)).fetchone()
        check(seller==('Synthetic Heading Seller',123,99.8))
    rejected(lambda:h.seed_seller(site))
    h.seed_recommendations(site)
    with sqlite3.connect(database) as db:
        check(db.execute('SELECT count(*) FROM products').fetchone()[0]==40)
        check('created_at' in {row[1] for row in db.execute('PRAGMA table_info(orders)')})
    rejected(lambda:h.seed_recommendations(site))
    seed=site/'seed.sqlite'
    with sqlite3.connect(database) as source,sqlite3.connect(seed) as target:source.backup(target)
    with sqlite3.connect(database) as db:
        db.execute('PRAGMA journal_mode=WAL');db.execute('DELETE FROM products WHERE id>1')
    h.restore_database(seed,database)
    with sqlite3.connect(database) as db:check(db.execute('SELECT count(*) FROM products').fetchone()[0]==40)
    (site/'sessions').mkdir();(site/'sessions/sess_synthetic').write_text('synthetic')
    (site/'security.sqlite').write_text('synthetic');(site/'growth.sqlite-wal').write_text('synthetic')
    h.reset_ephemeral_state(site)
    check(not list((site/'sessions').iterdir()) and not (site/'security.sqlite').exists() and not (site/'growth.sqlite-wal').exists())
print(json.dumps({'checks':checks,'passed':True,'scope':'pure identity/report/policy and synthetic SQLite helper tests; no application/browser/server execution'}))
