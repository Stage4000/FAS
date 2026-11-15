"""Pure exact-source and fixture-boundary checks; no browser/server/network."""
from pathlib import Path
import ast,base64,gzip,hashlib,json
MANIFEST_SHA256 = '31891929007995542dce1e63090cc3ccad02db24c1a69d55e9e0331af67ccbc5'
RECOVER_ROUTE = "if ($p==='/recover-cart.php') { if ($_SERVER['REQUEST_METHOD']!=='GET' || ($_SERVER['QUERY_STRING'] ?? '')!=='') { http_response_code(404); echo 'Fixture route not found'; return true; } require $_SERVER['DOCUMENT_ROOT'].'/recover-cart.php'; return true; }\n"

def manifest():
 raw=(Path(__file__).parent/'email-restore-sources.json').read_bytes()
 if hashlib.sha256(raw).hexdigest()!=MANIFEST_SHA256:raise ValueError('Email source manifest identity mismatch')
 return json.loads(raw)

def baseline(path):
 entry=manifest()['files'][path]
 data=gzip.decompress(base64.b64decode(entry['baseline_gzip_b64'],validate=True))
 if hashlib.sha256(data).hexdigest()!=entry['before_sha256']:raise ValueError('Email source preimage mismatch')
 return data

def verify_runtime(root):
 for path,entry in manifest()['files'].items():
  if hashlib.sha256((root/path).read_bytes()).hexdigest()!=entry['after_sha256']:raise ValueError('Exact reviewed email runtime required: '+path)
  baseline(path)

def verify_fixture_contract(data):
 source=data.decode() if isinstance(data,bytes) else data
 m=manifest();before=gzip.decompress(base64.b64decode(m['fixture_baseline_gzip_b64'],validate=True))
 if hashlib.sha256(before).hexdigest()!=m['fixture_baseline_sha256']:raise ValueError('Fixture baseline identity mismatch')
 old=ast.parse(before.decode());new=ast.parse(source)
 def projection(tree):
  copies=[];folders=[];pages=[];routers=[]
  for node in ast.walk(tree):
   if isinstance(node,ast.Call) and isinstance(node.func,ast.Attribute):
    if isinstance(node.func.value,ast.Name) and node.func.value.id=='shutil' and node.func.attr in {'copy2','copytree'}:
     copies.append(ast.dump(node,include_attributes=False))
    if isinstance(node.func.value,ast.Name) and node.func.value.id=='router' and node.func.attr=='write_text':
     if len(node.args)!=1 or not isinstance(node.args[0],ast.Constant):raise ValueError('Exact static router required')
     routers.append(node.args[0].value)
   if isinstance(node,ast.For) and isinstance(node.target,ast.Name):
    if node.target.id=='folder':folders.append(ast.dump(node.iter,include_attributes=False))
    if node.target.id=='name' and any(isinstance(c,ast.Call) and isinstance(c.func,ast.Attribute) and c.func.attr=='copy2' and len(c.args)>0 and isinstance(c.args[0],ast.BinOp) and isinstance(c.args[0].left,ast.Name) and c.args[0].left.id=='ROOT' and isinstance(c.args[0].right,ast.Name) and c.args[0].right.id=='name' for c in ast.walk(node)):
     try:pages.append(ast.literal_eval(node.iter))
     except (ValueError,TypeError):raise ValueError('Static root-page copy list required')
  return copies,folders,pages,routers
 a,b=projection(old),projection(new)
 if a[0]!=b[0] or a[1]!=b[1]:raise ValueError('Fixture copy/API/filter boundaries changed')
 if a[2]!=[['index.php','products.php','product.php','404.php','cart.php']] or b[2]!=[a[2][0]+['recover-cart.php']]:raise ValueError('Only exact recover root-page copy addition allowed')
 if len(a[3])!=1 or len(b[3])!=1 or b[3][0].count(RECOVER_ROUTE)!=1 or b[3][0].replace(RECOVER_ROUTE,'',1)!=a[3][0]:raise ValueError('Only exact GET/noquery recover route addition allowed')
 return {'copied_extra':['recover-cart.php'],'api_files':['api/products.php'],'restore_api_copied':False,'recover_document':'GET /recover-cart.php, no query','previous_copy_filters_router_preserved':True}
