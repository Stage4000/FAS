"""Pure exact writer/tree/copy/router negatives; no browser/server/network/application execution."""
from pathlib import Path
import copy,hashlib,json
import email_restore_identity as e
checks=0

def check(value):
 global checks
 checks+=1
 assert value

def reject(fn):
 global checks
 checks+=1
 try:fn()
 except (ValueError,KeyError):return
 raise AssertionError('Mutation incorrectly accepted')

root=Path(__file__).resolve().parents[2];entry=e.manifest()['files']['public/js/email-action.js'];raw=(root/'public/js/email-action.js').read_bytes();before=e.baseline('public/js/email-action.js')
check(hashlib.sha256(raw).hexdigest()==entry['after_sha256']);check(hashlib.sha256(before).hexdigest()==entry['before_sha256']);check(raw!=before);e.verify_runtime(root);check(True)
original=e.MANIFEST_SHA256;e.MANIFEST_SHA256='0'*64;reject(lambda:e.manifest());e.MANIFEST_SHA256=original
runner=(Path(__file__).parent/'run.py').read_text();receipt=e.verify_fixture_contract(runner);check(receipt['restore_api_copied'] is False);check(receipt['api_files']==['api/products.php'])
# Copy paths, recursive filters, and recover route remain independently bounded.
mutations=[
 runner.replace("'cart.php', 'recover-cart.php']","'cart.php', 'api/growth.php']",1),
 runner.replace("['src', 'includes', 'public']","['src', 'includes', 'public', 'api']",1),
 runner.replace("'config*.php', 'uploads'","'uploads'",1),
 runner.replace("ROOT / 'api/products.php'","ROOT / 'api/growth.php'",1),
 runner.replace("        (site / 'api').mkdir()","        (site / 'api').mkdir()\n        shutil.copy2(ROOT / 'api/growth.php', site / 'api/growth.php')",1),
 runner.replace("if ($p==='/recover-cart.php')", "if (str_starts_with($p,'/recover-cart'))",1),
 runner.replace("$_SERVER['REQUEST_METHOD']!=='GET'", "false",1),
 runner.replace("($_SERVER['QUERY_STRING'] ?? '')!==''", "false",1),
 runner.replace("/recover-cart.php'; return true; }", "/api/growth.php'; return true; }",1),
]
for changed in mutations:
 check(changed!=runner);reject(lambda:e.verify_fixture_contract(changed))
import heading_runner as h
base={'entries':[{'path':'src/runtime.php','mode':'100644','type':'blob','sha':'base'}]};tree={'src/runtime.php':{'mode':'100644','type':'blob','sha':'base'}}
tree.update({p:{'mode':'100644','type':'blob','sha':'reviewed'} for p in h.INTEGRATION_PATHS});tree.update(copy.deepcopy(h.REVIEWED_OVERLAY));h.verify_tree(base,tree,{});check(True)
for path in h.EMAIL_BLOBS:
 for field,value in [('sha','0'*40),('mode','100755'),('type','tree')]:
  changed=copy.deepcopy(tree);changed[path][field]=value;reject(lambda:h.verify_tree(base,changed,{}))
 changed=copy.deepcopy(tree);del changed[path];reject(lambda:h.verify_tree(base,changed,{}))
for path in ['src/runtime.php','api/growth.php','tests/unreviewed.cjs','tests/keyboard-browser/heading-policy.cjs']:
 changed=copy.deepcopy(tree);changed[path]={'mode':'100644','type':'blob','sha':'unexpected'};reject(lambda:h.verify_tree(base,changed,{}))
print(json.dumps({'passed':True,'checks':checks,'scope':'pure source/fixture-copy/router/tree mutations; no browser/server/network/application execution'}))
