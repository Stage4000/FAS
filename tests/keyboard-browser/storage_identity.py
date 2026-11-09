"""Exact storage overlay restoration; pure bytes only, no server/browser/network."""
from pathlib import Path
import base64, gzip, hashlib, json
import malformed_identity
MANIFEST_SHA256 = '23e23bceb7d6fb04edf25356ba590f3642b7700d17e27bd06efb7cd60d8faed5'

def manifest():
    raw=(Path(__file__).parent/'storage-sources.json').read_bytes()
    if hashlib.sha256(raw).hexdigest()!=MANIFEST_SHA256: raise ValueError('Storage manifest identity mismatch')
    return json.loads(raw)

def restore(path, data, strict=False):
    data = malformed_identity.restore(path, data)
    entry=manifest()['files'].get(path)
    if entry is None: return data
    digest=hashlib.sha256(data).hexdigest()
    if digest!=entry['after_sha256']:
        if strict: raise ValueError('Exact storage candidate required: '+path)
        return data
    before=gzip.decompress(base64.b64decode(entry['baseline_gzip_b64'],validate=True))
    if hashlib.sha256(before).hexdigest()!=entry['before_sha256']: raise ValueError('Storage preimage mismatch')
    return before

def verify_runtime(root):
    return {path:restore(path,(root/path).read_bytes(),strict=True) for path in manifest()['files']}
