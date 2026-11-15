"""Pure exact identity and own disposable fixture checks; never browser/server/network."""
from pathlib import Path
import copy,hashlib,json,sqlite3,tempfile,textwrap,types
import malformed_identity as m
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

root=Path(__file__).resolve().parents[2]
raw=(root/'public/js/main.js').read_bytes();before=m.restore('public/js/main.js',raw,strict=True)
check(hashlib.sha256(before).hexdigest()=='3a38e022e228f573c06f5272fe80f56754a438fb40e2ed699f59990d2817abf2')
check(before!=raw)
for changed in [before,raw+b'changed',raw[:-1],raw.replace(b'cart',b'Cart',1),raw.replace(b'Number.isFinite',b'Number.isNaN',1)]:reject(lambda:m.restore('public/js/main.js',changed,strict=True))
check(m.restore('unrelated.js',b'unchanged')==b'unchanged')
original=m.MANIFEST_SHA256;m.MANIFEST_SHA256='0'*64;reject(lambda:m.manifest());m.MANIFEST_SHA256=original
import heading_runner as h
base={'entries':[{'path':'src/runtime.php','mode':'100644','type':'blob','sha':'base'}]}
tree={'src/runtime.php':{'mode':'100644','type':'blob','sha':'base'}};tree.update({p:{'mode':'100644','type':'blob','sha':'reviewed'} for p in h.INTEGRATION_PATHS});tree.update(copy.deepcopy(h.REVIEWED_OVERLAY))
h.verify_tree(base,tree,{});check(True)
for path in h.MALFORMED_BLOBS:
 for field,value in [('sha','0'*40),('mode','100755'),('type','tree')]:
  changed=copy.deepcopy(tree);changed[path][field]=value;reject(lambda:h.verify_tree(base,changed,{}))
 changed=copy.deepcopy(tree);del changed[path];reject(lambda:h.verify_tree(base,changed,{}))
for path in ['src/runtime.php','tests/unreviewed.cjs']:
 changed=copy.deepcopy(tree);changed[path]={'mode':'100644','type':'blob','sha':'unexpected'};reject(lambda:h.verify_tree(base,changed,{}))
# Exact new stock block: success, failed test result, and exception all restore id1.
runner=(Path(__file__).parent/'run.py').read_bytes()
check(hashlib.sha1(b'blob '+str(len(runner)).encode()+b'\0'+runner).hexdigest()==h.MALFORMED_BLOBS['tests/keyboard-browser/run.py']['sha'])
text=runner.decode();start=text.index('                # Malformed suite alone uses stock5;');end=text.index("                from heading_runner import run_heading_variants",start)
fragment=compile(textwrap.dedent(text[start:end]),'<exact malformed stock fixture>','exec')
for outcome in ['pass','failure','raise']:
 with tempfile.TemporaryDirectory(prefix='malformed-stock-identity-') as temporary:
  site=Path(temporary);(site/'database').mkdir();database=site/'database/flipandstrip.db'
  with sqlite3.connect(database) as db:db.execute('CREATE TABLE products(id INTEGER PRIMARY KEY,quantity INTEGER)');db.execute('INSERT INTO products VALUES(1,1)')
  calls=[]
  def fake_run(argv):
   with sqlite3.connect(database) as db:check(db.execute('SELECT quantity FROM products WHERE id=1').fetchone()==(5,))
   calls.append(argv)
   if outcome=='raise':raise RuntimeError('synthetic test exception')
   return types.SimpleNamespace(returncode=int(outcome=='failure'))
  env={'sqlite3':sqlite3,'site':site,'ROOT':site,'args':types.SimpleNamespace(assets=site),'output':site,'origin':'http://127.0.0.1:12345','results':[0],'subprocess':types.SimpleNamespace(run=fake_run)}
  try:exec(fragment,env)
  except RuntimeError:check(outcome=='raise')
  else:check(env['results']==[0,int(outcome=='failure')])
  check(len(calls)==1 and calls[0][0]=='node' and calls[0][1].endswith('malformed-hydration.cjs'))
  with sqlite3.connect(database) as db:check(db.execute('SELECT quantity FROM products WHERE id=1').fetchone()==(1,))
print(json.dumps({'passed':True,'checks':checks,'scope':'pure exact malformed identity/tree and disposable stock restoration; no browser/server/network'}))
