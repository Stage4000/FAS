"""Pure identity mutations. Never runs browser, server, application or network."""
from pathlib import Path
import copy, hashlib, importlib.util, json
import storage_identity as s
checks=0

def check(value):
    global checks
    checks+=1
    assert value

def reject(fn):
    global checks
    checks+=1
    try: fn()
    except (ValueError, KeyError): return
    raise AssertionError('Identity mutation accepted')

root=Path(__file__).resolve().parents[2]
for path,entry in s.manifest()['files'].items():
    data=(root/path).read_bytes();before=s.restore(path,data,strict=True)
    check(hashlib.sha256(before).hexdigest()==entry['before_sha256'])
    check(before!=data)
    for mutation in [before,data+b'changed',data[:-1],data.replace(b'cart',b'Cart',1)]:
        reject(lambda:s.restore(path,mutation,strict=True))
    check(s.restore(path,before)==before)
check(s.restore('unrelated.js',b'anything')==b'anything')
# Manifest changes fail the exact file hash, rather than trusting supplied identities.
original=s.MANIFEST_SHA256
s.MANIFEST_SHA256='0'*64
reject(lambda:s.manifest())
s.MANIFEST_SHA256=original
# Use actual helper and historical guard verification with complete synthetic tree mapping.
import heading_runner as h
base={'entries':[{'path':'src/runtime.php','mode':'100644','type':'blob','sha':'base'}]}
tree={'src/runtime.php':{'mode':'100644','type':'blob','sha':'base'}}
tree.update({p:{'mode':'100644','type':'blob','sha':'reviewed'} for p in h.INTEGRATION_PATHS})
tree.update(copy.deepcopy(h.REVIEWED_OVERLAY))
h.verify_tree(base,tree,{});check(True)
for path in h.STORAGE_BLOBS:
    for field,value in [('sha','0'*40),('mode','100755'),('type','tree')]:
        bad=copy.deepcopy(tree);bad[path][field]=value;reject(lambda:h.verify_tree(base,bad,{}))
    bad=copy.deepcopy(tree);del bad[path];reject(lambda:h.verify_tree(base,bad,{}))
for path in ['src/runtime.php','tests/unreviewed.cjs']:
    bad=copy.deepcopy(tree);bad[path]={'mode':'100644','type':'blob','sha':'unexpected'};reject(lambda:h.verify_tree(base,bad,{}))
# Execute only the exact pinned stock-seed/finally block against our own SQLite.
import sqlite3, tempfile, textwrap, types
runner=(Path(__file__).parent/'run.py').read_bytes()
check(hashlib.sha1(b'blob '+str(len(runner)).encode()+b'\0'+runner).hexdigest()==h.STORAGE_BLOBS['tests/keyboard-browser/run.py']['sha'])
text=runner.decode()
start=text.index('                # This suite alone needs stock')
end=text.index("                with sqlite3.connect(site/'database/flipandstrip.db') as db:\n                    db.execute('DELETE FROM ebay_seller_rating_cache')",start)
fragment=compile(textwrap.dedent(text[start:end]),'<exact storage fixture stock block>','exec')
for outcome in ['pass','failure','raise']:
    with tempfile.TemporaryDirectory(prefix='storage-stock-unit-') as temporary:
        site=Path(temporary);(site/'database').mkdir()
        database=site/'database/flipandstrip.db'
        with sqlite3.connect(database) as db:
            db.execute('CREATE TABLE products(id INTEGER PRIMARY KEY,quantity INTEGER)')
            db.execute('INSERT INTO products VALUES(1,1)')
        calls=[]
        def fake_run(argv):
            with sqlite3.connect(database) as db: check(db.execute('SELECT quantity FROM products WHERE id=1').fetchone()==(5,))
            calls.append(argv)
            if outcome=='raise': raise RuntimeError('synthetic subprocess failure')
            return types.SimpleNamespace(returncode=int(outcome=='failure'))
        env={'sqlite3':sqlite3,'site':site,'ROOT':site,'args':types.SimpleNamespace(assets=site),'output':site,'origin':'http://127.0.0.1:12345','results':[0],
             'subprocess':types.SimpleNamespace(run=fake_run)}
        try: exec(fragment,env)
        except RuntimeError:
            check(outcome=='raise')
        else: check(env['results']==[0,int(outcome=='failure')])
        check(len(calls)==1 and calls[0][0]=='node' and calls[0][1].endswith('storage-unavailable.cjs'))
        with sqlite3.connect(database) as db: check(db.execute('SELECT quantity FROM products WHERE id=1').fetchone()==(1,))

print(json.dumps({'passed':True,'checks':checks,'scope':'pure exact restoration/manifest/tree mutations; no browser/server/network'}))
