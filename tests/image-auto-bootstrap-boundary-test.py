"""Synthetic actual-store bootstrap tests. No PDO, decoder or network operations."""
import hashlib,json,os,pathlib,shutil,subprocess,sys,tempfile
HERE=pathlib.Path(__file__).resolve().parent
WORK=HERE.parents[1]
SOURCE=HERE/'fixtures/image-auto-pilot' if (HERE/'fixtures/image-auto-pilot').is_dir() else WORK/'candidate/src'
HELPER=HERE/'fixtures/image-auto-bootstrap/FixedImageBootstrap.php' if (HERE/'fixtures/image-auto-bootstrap').is_dir() else HERE/'FixedImageBootstrap.php'
PHP=str(pathlib.Path(sys.argv[1]).resolve()) if len(sys.argv)>1 else (shutil.which('php') or str(WORK.parent/'php83-intake-runtime-20261109/php-8.3.36/sapi/cli/php'))
checks=0
with tempfile.TemporaryDirectory(prefix='fixed-bootstrap-',dir=HERE) as tmp:
 base=pathlib.Path(tmp).resolve();base.chmod(0o700)
 for case in ['pass','nested-site-db','broader-db','site-root-db','package-db','private-site-overlap','inherited','config-drift','module-drift','unknown-config','overlap','missing-db','wrong-pin-set','symlink-config']:
  root=base/case;root.mkdir(mode=0o700)
  for d in ['src','private','private/inputs','private/tmp','site','site/gallery','site/gallery/responsive','database']:(root/d).mkdir(mode=0o700)
  for name in ['ImageIntakeBinding.php','ImagePilot.php','ImageSourceIntake.php','ImageSourceSelection.php','ImportedImage.php','PosixImageIntakeStore.php','ResponsiveImage.php']:shutil.copyfile(SOURCE/name,root/'src'/name)
  shutil.copyfile(HELPER,root/'bootstrap.php')
  (root/'database/catalog.sqlite').write_text('SYNTHETIC NOT SQLITE')
  (root/'outside-marker').write_text('OUTSIDE')
  c={'enabled':True,'network_enabled':True,'private':str(root/'private'),'site':str(root/'site'),'input':str(root/'private/inputs'),'database':str(root/'database/catalog.sqlite'),'job':'a'*32}
  if case=='unknown-config':c['extra']=True
  if case=='overlap':c['database']=str(root/'private/catalog.sqlite');(root/'private/catalog.sqlite').write_text('SYNTHETIC')
  if case=='nested-site-db':
   (root/'site/database').mkdir(mode=0o700);c['database']=str(root/'site/database/catalog.sqlite');(root/'site/database/catalog.sqlite').write_text('SYNTHETIC')
  if case=='broader-db':c['database']=str(root/'catalog.sqlite');(root/'catalog.sqlite').write_text('SYNTHETIC')
  if case=='site-root-db':c['database']=str(root/'site/catalog.sqlite');(root/'site/catalog.sqlite').write_text('SYNTHETIC')
  if case=='package-db':c['database']=str(root/'src/catalog.sqlite');(root/'src/catalog.sqlite').write_text('SYNTHETIC')
  if case=='private-site-overlap':
   (root/'site/private').mkdir(mode=0o700);(root/'site/private/inputs').mkdir(mode=0o700);c['private']=str(root/'site/private');c['input']=str(root/'site/private/inputs')
  config=root/'config.json';config.write_text(json.dumps(c));config.chmod(0o600)
  sha=hashlib.sha256(config.read_bytes()).hexdigest()
  pins={p.name:hashlib.sha256(p.read_bytes()).hexdigest()for p in (root/'src').glob('*.php') if p.name in ['ImageIntakeBinding.php','ImagePilot.php','ImageSourceIntake.php','ImageSourceSelection.php','ImportedImage.php','PosixImageIntakeStore.php','ResponsiveImage.php']}
  if case=='wrong-pin-set':pins.pop('ImagePilot.php')
  if case=='config-drift':config.write_text(config.read_text()+' ')
  if case=='module-drift':(root/'src/ImagePilot.php').write_text('<?php throw new RuntimeException("MUST NOT EXECUTE");')
  if case=='missing-db':(root/'database/catalog.sqlite').unlink()
  if case=='symlink-config':config.rename(root/'real-config.json');config.symlink_to(root/'real-config.json')
  launcher="<?php\ndeclare(strict_types=1);\nrequire __DIR__.'/bootstrap.php';\n$b=\\FAS\\Utils\\FixedImageBootstrap::start(__DIR__.'/src',__DIR__.'/config.json','"+sha+"',json_decode('"+json.dumps(pins)+"',true),"+str(os.geteuid())+",true);\n"+r'''
if(ini_get('open_basedir')!==$b['restriction']||@file_get_contents(__DIR__.'/outside-marker')!==false||@fileowner(__DIR__)!==false)throw new RuntimeException('Restriction not enforced');
$s=$b['store'];$s->claim(str_repeat('a',32),['synthetic'=>true]);$s->original(str_repeat('a',32),'png','not decoded');$s->event('staged',['synthetic'=>true]);$s->ready(['intake'=>['job'=>str_repeat('a',32)],'synthetic'=>true]);$s->event('ready',['synthetic'=>true]);$s->release();
if(!is_file($b['config']['private'].'/approved-record.json'))throw new RuntimeException('Real store failed');
echo json_encode(['status'=>'PASS','actual_store_after_narrowing'=>true,'outside_denied'=>true,'roots'=>explode(PATH_SEPARATOR,$b['restriction'])]);
'''
  (root/'run.php').write_text(launcher)
  cmd=[PHP,'-d',f'sys_temp_dir={root}/private/tmp','-d','allow_url_fopen=0','-d','allow_url_include=0','-d','disable_functions=exec,system,shell_exec,passthru,popen,proc_open,curl_exec,fsockopen,stream_socket_client']
  if case=='inherited':cmd+=['-d',f'open_basedir={root}']
  r=subprocess.run(cmd+[str(root/'run.php')],capture_output=True,text=True,timeout=15)
  if case in ['pass','nested-site-db']:
   assert r.returncode==0,(r.stdout,r.stderr);result=json.loads(r.stdout);assert result['actual_store_after_narrowing']
   expected=[str(root/'src'),str(root/'private'),str(root/'site')]
   if case=='pass':expected.append(str(root/'database'))
   assert result['roots']==expected,(case,result)

  else:
   assert r.returncode!=0,(case,r.stdout,r.stderr)
   assert not (root/'private/intake-attempt.jsonl').exists(),case
   assert 'MUST NOT EXECUTE' not in r.stderr+r.stdout,case
  checks+=1
print(json.dumps({'status':'PASS','cases':checks,'actual_store_after_narrowing':True,'pdo_gd_tls_kernel_acceptance':False}))
