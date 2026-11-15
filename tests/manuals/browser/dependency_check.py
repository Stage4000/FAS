"""Dependency receipt for the exact interpreter selected inside isolation."""
from pathlib import Path
import json
import platform
import subprocess
import sys
sys.dont_write_bytecode=True
from stage_diagnostics import EXTENSIONS,runtime_receipt


def probe(php):
    receipt={'python_executable':'/usr/bin/python3' if Path(sys.executable).resolve()==Path('/usr/bin/python3').resolve() else 'other',
             'python_version':platform.python_version(),'php_executable':'/usr/bin/php8.3' if php=='/usr/bin/php8.3' else 'other',
             'extensions':{},'errors':[]}
    try:
        import reportlab
        from reportlab.pdfgen import canvas
        from reportlab.lib.pdfencrypt import StandardEncryption
        receipt.update(reportlab_available=True,reportlab_version=str(reportlab.Version),
                       reportlab_distribution_package=Path(reportlab.__file__).is_relative_to('/usr/lib/python3/dist-packages/reportlab'))
    except ImportError:receipt['errors'].append('reportlab_missing')
    code="""$names=['pdo_sqlite','sqlite3','fileinfo','mbstring','dom','json','session'];$e=[];
foreach($names as $n)$e[$n]=extension_loaded($n);$j=false;
if($e['pdo_sqlite']){try{$db=new PDO('sqlite::memory:');$j=(int)$db->query("SELECT json_valid('{}')")->fetchColumn()===1;}catch(Throwable $x){}}
echo json_encode(['php_version'=>PHP_VERSION,'extensions'=>$e,'sqlite_json'=>$j]);"""
    try:
        result=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=15)
        if result.returncode!=0 or result.stderr:raise ValueError('PHP probe failed')
        value=json.loads(result.stdout)
        receipt.update(php_version=value['php_version'],extensions=value['extensions'],sqlite_json=value['sqlite_json'])
        if not all(value['extensions'].get(name) is True for name in EXTENSIONS):receipt['errors'].append('php_extension_missing')
        if value['sqlite_json'] is not True:receipt['errors'].append('sqlite_json_missing')
    except (OSError,ValueError,KeyError,subprocess.TimeoutExpired):receipt['errors'].append('php_probe_failed')
    return runtime_receipt(receipt)


if __name__=='__main__':
    result=probe(sys.argv[1]);print('FAS_RUNTIME_RECEIPT '+json.dumps(result,sort_keys=True))
    sys.exit(1 if result['errors'] else 0)
