"""Pure exact malformed overlay identity; no browser/server/network."""
from pathlib import Path
import base64,gzip,hashlib,json
MANIFEST_SHA256 = 'f2d85b16feadfbb91c6fb8382e567c9355314a524cb5bedf0fd33962137bb299'

def manifest():
    raw=(Path(__file__).parent/'malformed-sources.json').read_bytes()
    if hashlib.sha256(raw).hexdigest()!=MANIFEST_SHA256: raise ValueError('Malformed manifest identity mismatch')
    return json.loads(raw)

def restore(path,data,strict=False):
    entry=manifest()['files'].get(path)
    if entry is None:return data
    if hashlib.sha256(data).hexdigest()!=entry['after_sha256']:
        if strict:raise ValueError('Exact malformed candidate required: '+path)
        return data
    before=gzip.decompress(base64.b64decode(entry['baseline_gzip_b64'],validate=True))
    if hashlib.sha256(before).hexdigest()!=entry['before_sha256']:raise ValueError('Malformed preimage identity mismatch')
    return before

def verify_runtime(root):
    return {path:restore(path,(root/path).read_bytes(),strict=True) for path in manifest()['files']}
