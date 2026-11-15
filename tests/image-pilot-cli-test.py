"""Synthetic fixed-config CLI acceptance, not production resource enforcement."""
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import struct
import subprocess
import tempfile
import zlib

FIXTURE = Path(__file__).resolve().parent / 'fixtures/image-pilot'
PINS = {
    'ImagePilot.php': '018ac35aa01b70d659d8c4d9ac726ae11dd04756028655e925ca64077ca33887',
    'ImportedImage.php': 'cbdd75a554b3c28a287206be5761a70e7bd03c737a08b561797031115d46399b',
    'ResponsiveImage.php': '47bd94cda593f5e9c11423e6c0ca2c86b7c0fcc0e1b502773eab4ec04cb3e729',
    'pilot.php': 'eca37c06cab25254ee046620df6349d0e63a5c4a4b7d20b08d7a1e0f05c04eac',
}

def png():
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))
    rows = b''.join(b'\0' + bytes(v for x in range(640) for v in ((x*19+y*7)%256, (x*3+y*11)%256, (x+y*5)%256)) for y in range(480))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 640, 480, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b'')

def main():
    php = shutil.which('php')
    assert php, 'Real PHP required, never skip'
    for name, digest in PINS.items():
        assert hashlib.sha256((FIXTURE/name).read_bytes()).hexdigest() == digest, name
    checks = 0
    with tempfile.TemporaryDirectory(prefix='fas-pilot-cli-') as tmp:
        root = Path(tmp).resolve()
        package, private, site = (root/n for n in ('package', 'private', 'site'))
        for p in (package/'src', private/'inputs', private/'tmp', site/'gallery/responsive'):
            p.mkdir(parents=True, mode=0o700)
        private.chmod(0o700)
        for name in PINS:
            shutil.copyfile(FIXTURE/name, package/name if name == 'pilot.php' else package/'src'/name)
        source = 'https://i.ebayimg.com/images/g/SYNTHETIC/s-l1600.png'
        original = private/'inputs/original.png'
        original.write_bytes(png())
        original_hash = hashlib.sha256(original.read_bytes()).hexdigest()
        database = root/'catalog.sqlite'
        with sqlite3.connect(database) as db:
            db.execute('CREATE TABLE products(id INTEGER PRIMARY KEY,is_active INTEGER,show_on_website INTEGER,image_url TEXT,images TEXT)')
            db.execute('INSERT INTO products VALUES(1,1,1,?,?)', (source, '[]'))
        database_before = database.read_bytes()
        config = dict(enabled=True, site=str(site), private=str(private), input=str(private/'inputs'), database=str(database), job='1'*32)
        config_file = package/'config.json'
        job = private/('job-'+'1'*32+'.json')
        receipt = site/'gallery/responsive'/('remote-'+hashlib.sha256(('1\n'+source).encode()).hexdigest()+'.json')
        def configure(value=config, mode=0o600):
            config_file.write_text(json.dumps(value))
            config_file.chmod(mode)
        def invoke(*args, error=None):
            nonlocal checks
            result = subprocess.run([php, '-d', 'sys_temp_dir='+str(private/'tmp'), str(package/'pilot.php'), *args], cwd=root, env={'PATH': os.defpath, 'LANG': 'C', 'TMPDIR': str(private/'tmp')}, capture_output=True, text=True, timeout=25)
            checks += 1
            if error is not None:
                assert result.returncode != 0 and error in result.stdout+result.stderr, (args, result.stdout, result.stderr)
                assert not job.exists() and not receipt.exists(), 'Refusal wrote job/receipt'
                return None
            assert result.returncode == 0, (args, result.stdout, result.stderr)
            return json.loads(result.stdout)
        invoke('enqueue', error='Private fixed config required')
        configure(dict(config, enabled=False)); invoke('enqueue', error='Pilot disabled')
        configure(mode=0o644); invoke('enqueue', error='Private fixed config required')
        config_file.unlink(); config_file.symlink_to(database); invoke('enqueue', error='Private fixed config required'); config_file.unlink()
        configure(); config_file.write_text('x'*16385); invoke('enqueue', error='Config bound')
        config_file.write_text('{'); invoke('enqueue', error='Syntax error')
        configure(dict(config, database=str(root/'missing.sqlite'))); invoke('enqueue', error='Existing canonical database required')
        assert not (root/'missing.sqlite').exists()
        configure(dict(config, database=str(root)+'/./catalog.sqlite')); invoke('enqueue', error='Existing canonical database required')
        configure(); invoke('unknown', error='Unknown mode'); invoke('run', 'extra', error='Unexpected CLI arguments')
        invoke('enqueue', error='Approved record missing')
        now = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0)
        record = dict(product_id='1', source_url=source, local_file='original.png', source_sha256=original_hash, verified_at=now.isoformat(), valid_until=(now+datetime.timedelta(hours=1)).isoformat(), provenance=dict(basis='existing_catalog', reference='synthetic CLI only', optimization_authorized=True, excluded=False))
        (private/'approved-record.json').write_text(json.dumps(record))
        (private/'approved-record.json').chmod(0o600)
        assert invoke('enqueue')['state'] == 'queued'
        assert invoke()['state'] == 'queued'
        done = invoke('run'); assert done['state'] == 'done' and done['variants'] > 0
        journal = job.read_bytes(); receipt_bytes = receipt.read_bytes()
        assert invoke('inspect')['state'] == 'done'
        again = invoke('run'); assert again['state'] == 'done' and again['retry_authorized'] is False
        assert job.read_bytes() == journal and receipt.read_bytes() == receipt_bytes
        assert hashlib.sha256(original.read_bytes()).hexdigest() == original_hash
        assert database.read_bytes() == database_before, 'Catalog changed'
        r = json.loads(receipt_bytes); assert r['source_sha256'] == original_hash and r['product_id'] == '1'
        for width, variant in r['variants'].items():
            path = site/'gallery/responsive'/f'remote-{original_hash}-{width}.webp'
            assert path.is_file() and hashlib.sha256(path.read_bytes()).hexdigest() == variant['sha256']
        assert all(not p.is_symlink() for p in root.rglob('*'))
    print(json.dumps(dict(status='PASS', cli_invocations=checks, scope='synthetic fixed-config CLI only; no production resource proof')))

if __name__ == '__main__':
    main()
