"""Synthetic reader fixture. Builder only: no server, browser or network starts here."""
from pathlib import Path
import hashlib
import json
import os
import shutil
import subprocess
import sys
sys.dont_write_bytecode = True
from reportlab.pdfgen import canvas
from reportlab.lib.pdfencrypt import StandardEncryption
from source_guard import ROOT, verify_sources


def build(out, php):
    verify_sources(checkout=False)
    out = Path(out).resolve()
    if out.exists():
        raise RuntimeError('A new, absent fixture directory is required')
    out.parent.mkdir(parents=True, exist_ok=True)
    subprocess.run([sys.executable, str(ROOT/'tests/manuals/prepare-fixture.py'), str(out)],
                   env={**os.environ, 'FAS_TEST_PHP': php}, check=True, capture_output=True)
    site = out/'site'
    # Existing HTTP fixture seeds its synthetic SQLite file in site/. Move it outside.
    shutil.move(site/'fixture.sqlite', out/'database.sqlite')
    (site/'src/config/config.php').write_text("<?php return ['database'=>['path'=>" + repr(str(out/'database.sqlite')) +
        "],'manuals'=>['storage_path'=>" + repr(str(out/'private')) + ",'public_enabled'=>true]];")
    for folder in ['home','tmp','uploads']:
        (out/folder).mkdir()
    specs = [('long',180), ('nontext',2), ('encrypted',2)]
    for name, count in specs:
        options = {'encrypt':StandardEncryption('synthetic-locked',canPrint=1)} if name == 'encrypted' else {}
        c = canvas.Canvas(str(out/(name+'.pdf')), pagesize=(612,792), invariant=1, **options)
        c.setTitle('SYNTHETIC FAS browser fixture: '+name)
        for n in range(1,count+1):
            c.setFillColorRGB(.95,.93,.90);c.rect(0,0,612,792,fill=1,stroke=0)
            c.setStrokeColorRGB(.85,0,.2);c.setLineWidth(8);c.rect(40,80,532,630,fill=0,stroke=1)
            if name != 'nontext':
                c.setFillColorRGB(.1,.1,.1);c.setFont('Helvetica-Bold',22)
                c.drawString(60,670,'SYNTHETIC WORKSHOP REFERENCE')
                c.setFont('Helvetica',16);c.drawString(60,610,f'{name} page {n} of {count}')
                c.drawString(60,570,f'Unique part FIND-PAGE-{n:03d}-END')
                c.drawString(60,530,'Not a manufacturer manual. Do not use for repairs.')
            c.showPage()
        c.save()
    (out/'malformed.pdf').write_bytes(b'%PDF-1.7\nThis is deliberately malformed synthetic data.\n%%EOF\n')
    seed = out/'seed-extra.php'
    seed.write_text("""<?php
require __DIR__.'/site/src/manuals/bootstrap.php';
$repo=new FAS\\Manuals\\ManualLibrary(manualsDatabase(),fn()=>true);
$ids=['book'=>1];
foreach(['long','nontext','encrypted','malformed'] as $name){
 $input=FAS\\Manuals\\ManualLibrary::blank();
 $input['title']='SYNTHETIC '.$name; $input['manufacturer']='Fixture Works';
 $input['rights']='cleared'; $input['rights_note']='Original generated test material'; $input['reviewed']=true;
 $file=manualsStorage()->importLocal(__DIR__.'/'.$name.'.pdf');
 $m=$repo->create($input,$file,1,bin2hex(random_bytes(24)));
 $repo->save($m['id'],$m['revision'],$input,'publish',1); $ids[$name]=$m['id'];
}
echo json_encode($ids);
""")
    ids = json.loads(subprocess.check_output([php, str(seed)], text=True))
    (out/'ids.json').write_text(json.dumps(ids))
    # Bounded CLI controls are available only to the runner, not via HTTP.
    (out/'control.php').write_text("""<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/site/src/manuals/bootstrap.php';
$db=manualsDatabase();$op=$argv[1]??'';
if($op==='revoke-session'){$db->exec('UPDATE admin_users SET is_active=0 WHERE id=1');}
elseif($op==='restore-session'){$db->exec('UPDATE admin_users SET is_active=1 WHERE id=1');}
elseif($op==='withdraw-rights'){
 $repo=new FAS\\Manuals\\ManualLibrary($db,fn()=>true);$m=$repo->getAdmin(1,1);
 $input=$m['draft'];$input['rights']='restricted';
 $repo->save(1,$m['revision'],$input,'draft',1);
}elseif($op==='restore-rights'){
 $repo=new FAS\\Manuals\\ManualLibrary($db,fn()=>true);$m=$repo->getAdmin(1,1);
 $input=$m['draft'];$input['rights']='cleared';$input['reviewed']=true;
 $repo->save(1,$m['revision'],$input,'publish',1);
}else{fwrite(STDERR,'Unknown fixture control');exit(2);}
echo 'ok';
""")
    (site/'admin/login.php').write_text("<?php http_response_code(401);header('Cache-Control: no-store');echo 'Synthetic fixture session expired.';")
    (out/'router.php').write_text("""<?php
$site=__DIR__.'/site';
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
if(!is_string($path)||str_contains($path,'..')||str_contains($path,'%')||str_contains($path,'\\\\')){http_response_code(400);exit;}
if($path==='/favicon.ico'){http_response_code(204);exit;}
$allowed=['/manuals/'=>'/manuals/index.php','/manuals/view.php'=>'/manuals/view.php','/manuals/file.php'=>'/manuals/file.php',
 '/admin/manuals-preview.php'=>'/admin/manuals-preview.php','/admin/manuals-file.php'=>'/admin/manuals-file.php',
 '/admin/login.php'=>'/admin/login.php','/fixture-login.php'=>'/fixture-login.php','/fixture-logout.php'=>'/fixture-logout.php'];
if(isset($allowed[$path])){
 if(str_starts_with($path,'/fixture-')&&($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
 require $site.$allowed[$path];return;
}
$static=['/public/css/manuals.css','/public/js/manual-reader.mjs','/public/js/manual-reader-loader.mjs'];
if(in_array($path,$static,true)||str_starts_with($path,'/public/vendor/pdfjs-6.5.438/')){
 $file=realpath($site.$path);
 if(!$file||!str_starts_with($file,$site.'/public/')||!is_file($file)){http_response_code(404);exit;}
 $mime=['mjs'=>'application/javascript','js'=>'application/javascript','css'=>'text/css','wasm'=>'application/wasm','bcmap'=>'application/octet-stream','pfb'=>'application/octet-stream','ttf'=>'font/ttf'];
 $ext=pathinfo($file,PATHINFO_EXTENSION);if(!isset($mime[$ext])){http_response_code(404);exit;}
 header('Content-Type: '.$mime[$ext]);header('X-Content-Type-Options: nosniff');readfile($file);return;
}
http_response_code(404);echo 'Outside synthetic fixture route allowlist.';
""")
    sources = json.loads(Path(__file__).with_name('source-manifest.json').read_text())['source_blobs']
    copies={rel:hashlib.sha256((site/rel).read_bytes()).hexdigest() for rel in sources if (site/rel).is_file()}
    generated=['router.php','control.php','site/src/config/config.php','site/includes/header.php','site/includes/footer.php','site/admin/includes/nav.php','site/admin/includes/footer.php','site/admin/login.php','site/fixture-login.php','site/fixture-logout.php']
    receipt={'synthetic_only':True, 'ids':ids, 'source_copies_sha256':copies,
             'generated_sha256':{rel:hashlib.sha256((out/rel).read_bytes()).hexdigest() for rel in generated},
             'layout':'Actual reader markup, CSS, JS and backend; shared site header/footer are adapters.',
             'nontext':'Vector-only pages, not proof of real scanned-image compatibility.',
             'cached_bytes':'Rights/session loss blocks subsequent requests, not bytes already downloaded.'}
    (out/'browser-fixture.json').write_text(json.dumps(receipt,indent=2))
    return receipt


if __name__=='__main__':
    print(json.dumps(build(sys.argv[1],os.environ.get('FAS_TEST_PHP','php')),indent=2))
