"""Build an isolated manual library fixture. Never loads real application configuration."""
from pathlib import Path
import shutil,sqlite3,subprocess,os,json,sys
from reportlab.pdfgen import canvas
from reportlab.lib.colors import HexColor
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(sys.argv[1]).resolve();SITE=OUT/'site';SITE.mkdir(parents=True,exist_ok=True)
PHP=os.environ.get('FAS_TEST_PHP','php')
for folder in ['private','sessions','evidence']:(OUT/folder).mkdir(exist_ok=True)
for rel in ['src/manuals','manuals','public/vendor/pdfjs-6.5.438']:
    shutil.copytree(ROOT/rel,SITE/rel,dirs_exist_ok=True)
for rel in ['src/utils/CSRF.php','src/utils/Timezone.php','src/config/Database.php','src/security/ClientIp.php','src/security/SecurityStore.php','admin/auth.php','includes/admin-accounts.php','includes/security.php','includes/manual-reader.php','public/css/manuals.css','public/js/manual-editor.js','public/js/manual-reader.mjs','public/js/manual-reader-loader.mjs']+[str(p.relative_to(ROOT)) for p in (ROOT/'admin').glob('manuals*.php')]:
    p=SITE/rel;p.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/rel,p)
(SITE/'admin/includes').mkdir(exist_ok=True)
(SITE/'admin/includes/nav.php').write_text('<nav><a href="/admin/manuals.php">Flip and Strip Admin</a></nav>')
(SITE/'admin/includes/footer.php').write_text('')
(SITE/'includes/header.php').write_text('<?php ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FAS synthetic manuals</title><link rel="stylesheet" href="/public/css/manuals.css"></head><body style="margin:0"><nav style="background:#242629;color:white;padding:20px;font:700 18px Arial">FLIP <span style="color:#ff6485">&</span> STRIP</nav><main>')
(SITE/'includes/footer.php').write_text('</main></body></html>')
config="<?php return ['database'=>['path'=>__DIR__.'/../../fixture.sqlite'],'manuals'=>['storage_path'=>"+repr(str(OUT/'private'))+",'public_enabled'=>true]];"
(SITE/'src/config/config.php').write_text(config)
pw=subprocess.check_output([PHP,'-r','echo password_hash("fixture-only",PASSWORD_DEFAULT);'],text=True)
with sqlite3.connect(SITE/'fixture.sqlite') as db:
    db.execute('CREATE TABLE IF NOT EXISTS admin_users(id INTEGER PRIMARY KEY,username TEXT,password_hash TEXT,email TEXT,role TEXT,is_active INTEGER,last_login TEXT)')
    db.execute("INSERT OR IGNORE INTO admin_users VALUES(1,'fixture',?,'fixture@example.invalid','admin',1,NULL)",[pw])
(SITE/'fixture-login.php').write_text("<?php require __DIR__.'/admin/auth.php'; $a=new AdminAuth();if(!$a->login('fixture','fixture-only')){http_response_code(403);exit($a->lastError);}echo 'Synthetic fixture login';")
(SITE/'fixture-logout.php').write_text("<?php session_start();session_destroy();echo 'Synthetic logout';")
pdf=OUT/'synthetic-workshop.pdf';c=canvas.Canvas(str(pdf),pagesize=(612,792));c.setTitle('Synthetic FAS workshop book')
for n in range(1,8):
    c.setFillColor(HexColor('#faf7ef'));c.rect(0,0,612,792,fill=1,stroke=0)
    c.setFillColor(HexColor('#db0335'));c.rect(0,750,612,42,fill=1,stroke=0)
    c.setFillColor(HexColor('#242629'));c.setFont('Helvetica-Bold',12);c.drawString(40,710,'FLIP & STRIP  /  SYNTHETIC WORKSHOP REFERENCE')
    c.setFont('Helvetica-Bold',32);c.drawString(40,650,'Example Motors' if n==1 else f'Chapter {n-1}')
    c.setFont('Helvetica',19);c.drawString(40,610,'Model ZX / test edition' if n==1 else 'Exploded view and reference notes')
    c.setFont('Helvetica',13)
    lines=['Original synthetic test document. Not a real service manual.','No real specifications, procedures, or manufacturer content.','Search fixture: torque, gasket, assembly, Model ZX.',f'Example part number: ZX-{n}26.','Do not use this synthetic content to service equipment.']
    for i,line in enumerate(lines):c.drawString(40,540-i*26,line)
    c.setStrokeColor(HexColor('#db0335'));c.setLineWidth(3)
    for i in range(3):c.circle(160+i*130,270,40,fill=0,stroke=1)
    c.setLineWidth(1);c.line(40,90,572,90);c.setFont('Helvetica',10);c.drawString(40,66,'FAS · original test fixture');c.drawRightString(572,66,f'{n} / 7');c.showPage()
c.save()
seed=OUT/'seed.php'
seed.write_text("<?php require "+repr(str(SITE/'src/manuals/bootstrap.php'))+"; $db=manualsDatabase();FAS\\Manuals\\ManualLibrary::install($db);$repo=new FAS\\Manuals\\ManualLibrary($db,fn()=>true);$input=FAS\\Manuals\\ManualLibrary::blank();$input['title']='Model ZX Workshop Reference';$input['manufacturer']='Example Motors';$input['model']='Model ZX';$input['years']='2026 · synthetic edition';$input['description']='A synthetic seven-page book for reader acceptance. This is not a real manufacturer manual.';$input['rights']='cleared';$input['rights_note']='Original synthetic test fixture created for this feature.';$input['reviewed']=true;$file=manualsStorage()->importLocal("+repr(str(pdf))+");$m=$repo->create($input,$file,1,str_repeat('b',48));$repo->save($m['id'],$m['revision'],$input,'publish',1);echo $m['id'];")
subprocess.run([PHP,str(seed)],check=True)
(OUT/'receipt.json').write_text(json.dumps({'source':str(ROOT),'site':str(SITE),'pdf':str(pdf),'database':'synthetic only','layout':'public header/footer and admin navigation are fixture adapters; reader/backend/auth are actual candidate sources'},indent=2))
print('\nFixture ready:',SITE)
