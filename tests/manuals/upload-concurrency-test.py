"""Real competing PHP processes and SQLite writers; synthetic private files only."""
from pathlib import Path
import hashlib
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('FAS_TEST_PHP', 'php')
OUT = Path(tempfile.mkdtemp(prefix='fas-manual-upload-concurrency-')).resolve()
checks = []
LEGACY = '--legacy' in sys.argv

def check(condition, label):
    assert condition, label
    checks.append(label)
    print('PASS', label, flush=True)

def fixture(name):
    folder = OUT / name
    folder.mkdir()
    (folder / 'private').mkdir()
    (folder / 'site').mkdir()
    (folder / 'input.pdf').write_bytes(b'%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n')
    (folder / 'different.pdf').write_bytes((folder / 'input.pdf').read_bytes().replace(b'Catalog', b'Changed'))
    shutil.copy2(ROOT / 'src/manuals/ManualLibrary.php', folder / 'ManualLibrary.php')
    (folder / 'worker.php').write_text('''<?php
require __DIR__.'/ManualLibrary.php';
use FAS\\Manuals\\ManualLibrary;use FAS\\Manuals\\ManualStorage;
class ConcurrencyProbePDO extends PDO {
    public $legacy=false;public $sqlOwned=false;
    public function exec($statement):int{$value=parent::exec($statement);$sql=strtoupper(trim($statement));if($sql==='BEGIN IMMEDIATE')$this->sqlOwned=true;if($sql==='COMMIT'||$sql==='ROLLBACK')$this->sqlOwned=false;if($value===false)throw new RuntimeException('Unexpected SQL failure.');return $value;}
    public function inTransaction():bool{return $this->legacy&&$this->sqlOwned?false:parent::inTransaction();}
    public function commit():bool{if($this->legacy&&$this->sqlOwned)throw new PDOException('No tracked SQL transaction in emulated legacy PDO.');return parent::commit();}
    public function rollBack():bool{if($this->legacy&&$this->sqlOwned)throw new PDOException('No tracked SQL transaction in emulated legacy PDO.');return parent::rollBack();}
}
$db=new ConcurrencyProbePDO('sqlite:'.__DIR__.'/catalog.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->legacy=($argv[6]??'')==='legacy';
$db->exec('PRAGMA busy_timeout='.(($argv[5]??'')==='short'?'200':'5000'));
if(($argv[1]??'')==='init'){ManualLibrary::install($db);exit;}
$id=$argv[1];$variant=$argv[2];$mode=$argv[3];$key=$argv[4];
$store=new ManualStorage(__DIR__.'/private',__DIR__.'/site');
$input=ManualLibrary::blank();$input['title']=$variant==='metadata'?'Different title':'Synthetic concurrency';$input['manufacturer']='Example';
$file=$store->importLocal(__DIR__.($variant==='pdf'?'/different.pdf':'/input.pdf'),true);
file_put_contents(__DIR__.'/ready-'.$id,json_encode(['file'=>$file,'input'=>$input]));
$deadline=microtime(true)+10;while(!is_file(__DIR__.'/go')){if(microtime(true)>$deadline)throw new RuntimeException('Synthetic start barrier timed out.');usleep(1000);}
$result=[];$calls=0;
$repo=new ManualLibrary($db,static function()use(&$calls){$calls++;if($calls===2)usleep(200000);return true;});
try{if($mode==='create'){$saved=$repo->create($input,$file,1,$key);$result=['status'=>'created','id'=>$saved['id']];}else{$result=['status'=>'settle-only'];}}
catch(Throwable $e){$result=['status'=>get_class($e),'message'=>$e->getMessage()];}
finally{try{$store->settleNewFiles($db);$result['settled']=true;}catch(Throwable $e){$result['settled']=false;$result['settle_error']=$e->getMessage();}}
echo json_encode($result,JSON_THROW_ON_ERROR);
''')
    result = subprocess.run([PHP, str(folder / 'worker.php'), 'init'], text=True, capture_output=True)
    assert result.returncode == 0 and result.stderr == '', result
    return folder

def worker(folder, ident, variant='same', mode='create', key='a'*48, timeout='normal'):
    return subprocess.Popen([PHP, '-d', 'open_basedir='+str(folder), '-d', 'display_errors=1',
                             str(folder / 'worker.php'), str(ident), variant, mode, key, timeout, 'legacy' if LEGACY else 'native'],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

def ready(folder, ids):
    deadline = time.monotonic()+10
    while not all((folder / ('ready-'+str(i))).exists() for i in ids):
        assert time.monotonic() < deadline, 'Worker readiness timed out'
        time.sleep(.005)

def finish(process):
    output, error = process.communicate(timeout=15)
    assert process.returncode == 0 and error == '', (output, error)
    return json.loads(output)

def assert_references(folder):
    with sqlite3.connect(folder / 'catalog.sqlite') as db:
        rows = db.execute('SELECT file_json FROM manuals').fetchall()
    for (encoded,) in rows:
        file = json.loads(encoded)
        content = (folder / 'private' / file['key']).read_bytes()
        assert len(content) == file['bytes'] and hashlib.sha256(content).hexdigest() == file['sha256']
    return len(rows), len(list((folder / 'private').glob('*.pdf')))

for name, second, distinct in [('identical', 'same', False), ('metadata-conflict', 'metadata', False),
                                ('content-conflict', 'pdf', False), ('distinct-keys', 'same', True)]:
    folder = fixture(name)
    first = worker(folder, 1)
    other = worker(folder, 2, second, key=('b' if distinct else 'a')*48)
    ready(folder, [1, 2])
    check(len(list((folder / 'private').glob('*.pdf'))) == 2, name+': both candidate copies exist before competing catalog decisions')
    (folder / 'go').write_text('start')
    results = [finish(first), finish(other)]
    (folder / 'results.json').write_text(json.dumps(results, indent=2))
    if distinct:
        check(all(r['status'] == 'created' and r['settled'] for r in results) and results[0]['id'] != results[1]['id'], name+': independent submissions both succeed')
        check(assert_references(folder) == (2, 2), name+': both referenced originals remain intact')
    elif second == 'same':
        check(all(r['status'] == 'created' and r['settled'] for r in results) and results[0]['id'] == results[1]['id'], name+': concurrent retries both resolve to one manual')
        check(assert_references(folder) == (1, 1), name+': exactly one intact referenced original remains')
    else:
        check(sorted(r['status'] for r in results) == ['DomainException', 'created'] and all(r['settled'] for r in results), name+': one succeeds and the conflicting retry is rejected')
        check(assert_references(folder) == (1, 1), name+': rejected candidate removed; winning referenced original intact')

folder = fixture('reference-race')
pending = worker(folder, 1, mode='settle')
ready(folder, [1])
data = json.loads((folder / 'ready-1').read_text())
with sqlite3.connect(folder / 'catalog.sqlite') as db:
    db.execute('BEGIN IMMEDIATE')
    db.execute("INSERT INTO manuals(revision,request_key,draft_json,file_json,created_at,updated_at) VALUES(1,?,?,?,?,?)",
               ['f'*48, json.dumps(data['input']), json.dumps(data['file']), '2026-11-12 00:00:00', '2026-11-12 00:00:00'])
    (folder / 'go').write_text('start')
    time.sleep(.25)
    check(pending.poll() is None and (folder / 'private' / data['file']['key']).exists(), 'Cleanup waits behind an uncommitted reference writer and preserves its bytes')
result = finish(pending)
check(result['settled'] and assert_references(folder) == (1, 1), 'Cleanup sees the newly committed reference and retains the original')

folder = fixture('lock-timeout')
pending = worker(folder, 1, mode='settle', timeout='short')
ready(folder, [1])
with sqlite3.connect(folder / 'catalog.sqlite') as db:
    db.execute('BEGIN IMMEDIATE')
    (folder / 'go').write_text('start')
    result = finish(pending)
    check(result['settled'] is False and 'locked' in result['settle_error'].lower(), 'Cleanup lock timeout is reported rather than guessed safe')
check(assert_references(folder) == (0, 1), 'Uncertain cleanup lock preserves its fresh file for later review')

(OUT / 'concurrency-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks,'fixture':str(OUT),
    'tracking':'emulated legacy PDO' if LEGACY else 'native PDO'}, indent=2))
print(len(checks), 'concurrent storage checks passed with', 'emulated legacy PDO tracking' if LEGACY else 'native PDO tracking', '. Evidence:', OUT)
