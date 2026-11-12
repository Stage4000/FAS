"""Real PDO/GD fixed-CLI lifecycle with deterministic in-process cURL API shim.
No provider, production data, PC or workflow changes. This test fails, never skips,
when the required existing PHP extensions are unavailable. It does NOT validate
libcurl networking/TLS or kernel resource wrappers.
"""
import hashlib, json, os, pathlib, shutil, subprocess, sys, tempfile
ROOT = pathlib.Path(__file__).resolve().parents[1]
FIXTURES = pathlib.Path(__file__).resolve().parent / 'fixtures/image-auto-pilot'
ENTRY_DIR = FIXTURES/'entrypoints' if FIXTURES.is_dir() else ROOT/'candidate'
SOURCE_DIR = FIXTURES if FIXTURES.is_dir() else ROOT/'candidate/src'
PHP = str(pathlib.Path(sys.argv[1]).resolve()) if len(sys.argv) > 1 else shutil.which('php')
if not PHP:
    raise SystemExit('BLOCKED: existing PHP CLI is required; nothing was installed')
DISABLED = 'exec,system,shell_exec,passthru,popen,proc_open,mail,fsockopen,pfsockopen,stream_socket_client,stream_socket_server,curl_exec,curl_multi_exec,socket_create,socket_connect,socket_bind,dns_get_record,gethostbyname,gethostbynamel,getmxrr'
PROBE = r'''echo json_encode(['php'=>PHP_VERSION,'modules'=>array_map('extension_loaded',['pdo_sqlite','gd','fileinfo','exif','posix']),'fsync'=>function_exists('fsync'),'webp'=>function_exists('imagewebp'),'open_flags'=>defined('PDO::SQLITE_ATTR_OPEN_FLAGS')&&defined('PDO::SQLITE_OPEN_READONLY')&&defined('PDO::SQLITE_OPEN_READWRITE')]);'''
SETUP = r'''<?php
declare(strict_types=1);
$root=__DIR__;foreach(['/private','/private/inputs','/private/tmp','/site','/site/gallery','/site/gallery/responsive']as$s)if(!mkdir($root.$s,0700))throw new RuntimeException('Synthetic fixture mkdir');
$dbfile=$root.'/synthetic-catalog.sqlite';if(file_exists($dbfile))throw new RuntimeException('Fresh synthetic catalog required');
$db=new PDO('sqlite:'.$dbfile);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY,is_active INTEGER,show_on_website INTEGER,image_url TEXT,images TEXT)');
$url='https://i.ebayimg.com/images/g/SYNTHETIC-CLI/s-l1600.png';$db->prepare('INSERT INTO products VALUES(1,1,1,?,?)')->execute([$url,'[]']);$db=null;
$image=imagecreatetruecolor(640,480);for($y=0;$y<480;$y++)for($x=0;$x<640;$x++)imagesetpixel($image,$x,$y,(($x*19+$y*7)%256)<<16|(($x*3+$y*11)%256)<<8|(($x+$y*5)%256));imagepng($image,$root.'/remote-fixture.png');imagedestroy($image);
function save(string$p,array$v):void{if(file_put_contents($p,json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n")===false||!chmod($p,0600))throw new RuntimeException('Fixture file');}
$id=str_repeat('a',32);$snapshot=['id'=>'1','is_active'=>1,'show_on_website'=>1,'image_url'=>$url,'images'=>'[]'];
save($root.'/config.json',['enabled'=>true,'network_enabled'=>true,'site'=>$root.'/site','private'=>$root.'/private','input'=>$root.'/private/inputs','database'=>$dbfile,'job'=>$id]);
save($root.'/private/intake-request.json',['job'=>$id,'product_id'=>'1','source_url'=>$url,'reference'=>'Synthetic CLI fixture only','intent'=>'initial','catalog_snapshot'=>$snapshot,'receipt_preimage_sha256'=>null,'optimization_authorized'=>true]);
echo json_encode(['status'=>'PASS','source_sha256'=>hash_file('sha256',$root.'/remote-fixture.png'),'catalog_sha256'=>hash_file('sha256',$dbfile)])."\n";
'''
PDO_CHECK = r'''<?php
declare(strict_types=1);
$root=__DIR__;$file=$root.'/synthetic-catalog.sqlite';$checks=0;
function check(bool$v,string$m):void{global$checks;$checks++;if(!$v)throw new RuntimeException($m);}
function openDb(string$p,int$flags):PDO{return new PDO('sqlite:'.$p,null,null,[PDO::SQLITE_ATTR_OPEN_FLAGS=>$flags,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
$ro=openDb($file,PDO::SQLITE_OPEN_READONLY);check((int)$ro->query('SELECT count(*) FROM products')->fetchColumn()===1,'readonly SELECT');$denied=false;try{$ro->exec('UPDATE products SET is_active=0');}catch(PDOException$e){$denied=true;}check($denied,'readonly must deny UPDATE');$ro->exec('PRAGMA busy_timeout=250');check((int)$ro->query('PRAGMA busy_timeout')->fetchColumn()===250,'readonly busy_timeout');$ro=null;
$missing=$root.'/synthetic-missing.sqlite';check(!file_exists($missing),'missing precondition');$denied=false;try{$unexpected=openDb($missing,PDO::SQLITE_OPEN_READWRITE);}catch(PDOException$e){$denied=true;}check($denied&&!file_exists($missing),'readwrite without CREATE refuses missing file');
$rw=openDb($file,PDO::SQLITE_OPEN_READWRITE);$rw->exec('PRAGMA busy_timeout=250');$rw->exec('BEGIN IMMEDIATE');check((int)$rw->query('SELECT is_active FROM products WHERE id=1')->fetchColumn()===1,'BEGIN IMMEDIATE works');$rw->exec('ROLLBACK');$sqliteVersion=$rw->query('SELECT sqlite_version()')->fetchColumn();$rw=null;
echo json_encode(['status'=>'PASS','checks'=>$checks,'sqlite'=>$sqliteVersion])."\n";
'''
# These functions are explicitly namespaced test doubles. They never delegate to
# libcurl or open any URL; all response bytes come from one generated local PNG.
# The production adapter and all other modules remain the exact candidate files.
CURL_SHIM = r'''<?php
declare(strict_types=1);
namespace FAS\Utils;
foreach(['CURLOPT_FOLLOWLOCATION','CURLOPT_MAXREDIRS','CURLOPT_PROTOCOLS','CURLPROTO_HTTPS','CURLOPT_REDIR_PROTOCOLS','CURLOPT_CONNECTTIMEOUT_MS','CURLOPT_TIMEOUT_MS','CURLOPT_NOSIGNAL','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_PROXY','CURLOPT_HTTPGET','CURLOPT_HTTPHEADER','CURLOPT_HTTP_CONTENT_DECODING','CURLOPT_MAXFILESIZE','CURLOPT_HEADERFUNCTION','CURLOPT_WRITEFUNCTION','CURLINFO_EFFECTIVE_URL']as$i=>$name)if(!\defined($name))\define($name,10000+$i);
final class SyntheticCurlHandle {public array$options=[];public function __construct(public string$url){}}
function extension_loaded(string$name):bool{return $name==='curl'?true:\extension_loaded($name);}
function curl_init(?string$url=null):SyntheticCurlHandle{if($url!=='https://i.ebayimg.com/images/g/SYNTHETIC-CLI/s-l1600.png')throw new \RuntimeException('Unexpected synthetic URL');return new SyntheticCurlHandle($url);}
function curl_setopt_array(SyntheticCurlHandle$h,array$options):bool{$h->options=$options;return true;}
function curl_exec(SyntheticCurlHandle$h):bool{
 $expected=[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_HTTP_CONTENT_DECODING=>false,CURLOPT_CONNECTTIMEOUT_MS=>3000,CURLOPT_TIMEOUT_MS=>10000,CURLOPT_MAXFILESIZE=>2097152];
 foreach($expected as$key=>$value)if(($h->options[$key]??null)!==$value)throw new \RuntimeException('Transport option changed');
 $header=$h->options[CURLOPT_HEADERFUNCTION];$chunk=$h->options[CURLOPT_WRITEFUNCTION];$body=\file_get_contents(__DIR__.'/remote-fixture.png');if(!\is_string($body)||\strlen($body)>2097152)throw new \RuntimeException('Synthetic PNG missing');
 \file_put_contents(__DIR__.'/synthetic-transfer.jsonl',\json_encode(['url'=>$h->url,'bytes'=>\strlen($body)])."\n",FILE_APPEND);
 foreach(["HTTP/1.1 200 OK\r\n","Content-Type: image/png\r\n",'Content-Length: '.\strlen($body)."\r\n","\r\n"]as$line)if($header($h,$line)!==\strlen($line))throw new \RuntimeException('Header callback rejected');
 foreach(\str_split($body,16384)as$part)if($chunk($h,$part)!==\strlen($part))throw new \RuntimeException('Body callback rejected');return true;
}
function curl_getinfo(SyntheticCurlHandle$h,?int$option=null):string{return$h->url;}
function curl_close(SyntheticCurlHandle$h):void{}
'''
VERIFY = r'''<?php
declare(strict_types=1);
require_once __DIR__.'/src/ResponsiveImage.php';
$root=__DIR__;$url='https://i.ebayimg.com/images/g/SYNTHETIC-CLI/s-l1600.png';$path=$root.'/site/gallery/responsive/remote-'.hash('sha256',"1\n".$url).'.json';$r=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
if($r['source_sha256']!==hash_file('sha256',$root.'/remote-fixture.png')||!$r['variants'])throw new RuntimeException('Actual receipt mismatch');
foreach($r['variants']as$width=>$m){$f=$root.'/site/gallery/responsive/remote-'.$r['source_sha256'].'-'.$width.'.webp';$size=getimagesize($f);if(!$size||$size[2]!==IMAGETYPE_WEBP||$size[0]!==$width||filesize($f)!==$m['bytes']||hash_file('sha256',$f)!==$m['sha256'])throw new RuntimeException('Actual variant mismatch');}
if(!str_contains(\FAS\Utils\ResponsiveImage::attributes($url,'100vw',0,$root.'/site','1'),'srcset='))throw new RuntimeException('Actual renderer fallback/variants');
echo json_encode(['status'=>'PASS','variants'=>count($r['variants'])])."\n";
'''
checks = 0

def check(value, label):
    global checks
    if not value: raise AssertionError(label)
    checks += 1

def command(root, script, args=(), shim=False, expect_ok=True):
    # Match the existing POSIX fixture tests: ancestor ownership checks must stat
    # outside the leaf fixture. Do not impose a leaf-only PHP open_basedir here.
    # The unchanged network-isolated workflow and disabled network/process
    # functions remain in force. Production wrapper compatibility is UNVERIFIED.
    cmd=[PHP,'-d','allow_url_fopen=0','-d','allow_url_include=0','-d','memory_limit=128M','-d',f'disable_functions={DISABLED}','-d',f'sys_temp_dir={root}/private/tmp']
    if shim: cmd += ['-d',f'auto_prepend_file={root}/synthetic-curl.php']
    result=subprocess.run(cmd+[str(root/script),*args],cwd=root,capture_output=True,text=True,timeout=30,env={'PATH':'/usr/bin:/bin','LC_ALL':'C','TMPDIR':str(root/'private/tmp')})
    if expect_ok and result.returncode!=0: raise AssertionError(f'{script} {args} failed: {result.stdout}\n{result.stderr}')
    if not expect_ok: return result
    return json.loads(result.stdout.strip())

probe=subprocess.run([PHP,'-r',PROBE],capture_output=True,text=True,timeout=10,env={'PATH':'/usr/bin:/bin','LC_ALL':'C'})
try: capability=json.loads(probe.stdout)
except Exception: raise SystemExit('BLOCKED: PHP capability probe failed: '+probe.stderr)
if not all(capability['modules']) or not capability['fsync'] or not capability['webp'] or not capability['open_flags']:
    print(json.dumps({'status':'BLOCKED','reason':'real PDO/GD/fileinfo/EXIF/POSIX/open flags required','capability':capability,'tests_executed':False}))
    raise SystemExit(2)

# POSIX store intentionally rejects world-writable ancestors; fixture is under the
# existing isolated workflow's trusted work directory, not a shared /tmp path.
with tempfile.TemporaryDirectory(prefix='image-cli-pdo-synthetic-',dir=os.getcwd()) as tmp:
    base=pathlib.Path(tmp).resolve();base.chmod(0o700)
    def fixture(name):
        root=base/name;root.mkdir(mode=0o700)
        for entry in ('intake.php','automatic-pilot.php'):shutil.copyfile(ENTRY_DIR/entry,root/entry)
        shutil.copytree(SOURCE_DIR,root/'src',ignore=shutil.ignore_patterns('entrypoints'))
        for name,source in [('setup.php',SETUP),('pdo-check.php',PDO_CHECK),('synthetic-curl.php',CURL_SHIM),('verify.php',VERIFY)]:
            (root/name).write_text(source);(root/name).chmod(0o600)
        info=command(root,'setup.php')
        return root,info
    root,info=fixture('happy')
    flags=command(root,'pdo-check.php');check(flags['status']=='PASS' and flags['checks']>=6,'real PDO flags and reservation')
    config=json.loads((root/'config.json').read_text());config['network_enabled']=False;(root/'config.json').write_text(json.dumps(config))
    refused=command(root,'intake.php',['acquire'],shim=True,expect_ok=False)
    check(refused.returncode!=0 and not (root/'synthetic-transfer.jsonl').exists(),'disabled network cannot call fake transport')
    config['network_enabled']=True;(root/'config.json').write_text(json.dumps(config))
    intake=command(root,'intake.php',['acquire'],shim=True);check(intake['state']=='ready','actual intake entrypoint ready')
    record=json.loads((root/'private/approved-record.json').read_text());journal=(root/'private/intake-attempt.jsonl').read_bytes()
    check([json.loads(x)['state'] for x in journal.splitlines()]==['receiving','staged','ready'],'actual durable intake sequence')
    original=root/'private/inputs'/record['local_file'];check(hashlib.sha256(original.read_bytes()).hexdigest()==info['source_sha256'],'source preserved')
    check(not list((root/'site/gallery/responsive').glob('remote-*.json')),'source CLI does not publish receipt')
    check(command(root,'automatic-pilot.php',['enqueue'])['state']=='queued','actual automatic enqueue')
    job=json.loads((root/'private'/('job-'+'a'*32+'.json')).read_text());check('intake_binding' in job,'entrypoint enforces committed intake')
    out=command(root,'automatic-pilot.php',['run']);check(out['state']=='done' and out['variants']>0,'actual automatic encoding')
    check(command(root,'automatic-pilot.php',['inspect'])['state']=='done','actual inspect')
    check(command(root,'automatic-pilot.php',['run'])['retry_authorized'] is False,'completed run not repeated')
    check(command(root,'verify.php')['variants']>0,'actual receipt variants and renderer')
    refused=command(root,'intake.php',['acquire'],shim=True,expect_ok=False)
    check(refused.returncode!=0 and len((root/'synthetic-transfer.jsonl').read_text().splitlines())==1,'repeat intake cannot fetch again')
    check((root/'private/intake-attempt.jsonl').read_bytes()==journal,'intake evidence unchanged')
    check(hashlib.sha256((root/'synthetic-catalog.sqlite').read_bytes()).hexdigest()==info['catalog_sha256'],'catalog bytes unchanged')

    root2,_=fixture('missing-ready');command(root2,'intake.php',['acquire'],shim=True)
    journal2=root2/'private/intake-attempt.jsonl';journal2.write_bytes(b'\n'.join(journal2.read_bytes().splitlines()[:2])+b'\n')
    refused=command(root2,'automatic-pilot.php',['enqueue'],expect_ok=False)
    check(refused.returncode!=0 and not (root2/'private'/('job-'+'a'*32+'.json')).exists(),'actual automatic CLI refuses missing ready marker')

print(json.dumps({'status':'PASS','checks':checks,'real_pdo_flags_checks':flags['checks'],'php':capability['php'],'transport':'namespaced deterministic cURL API shim; no libcurl/provider operation','real_modules':'PDO SQLite/GD/fileinfo/EXIF/POSIX and exact candidate entrypoints','resource_wrapper_acceptance':False}))
