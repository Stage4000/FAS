"""Local-only derived lifecycle: never edits approved entrypoints. Real extensions required.
Reuses accepted fixture assertions; dynamically builds separately pinned bootstrap CLIs.
No real curl/TLS/kernel acceptance. Run inside the existing network-isolated CI only.
"""
import pathlib
HERE=pathlib.Path(__file__).resolve().parent
BASE=HERE/'image-auto-entrypoint-pdo-test.py' if (HERE/'image-auto-entrypoint-pdo-test.py').is_file() else HERE.parents[1]/'tests/entrypoint-pdo-test.py'
BOOTSTRAP=HERE/'fixtures/image-auto-bootstrap' if (HERE/'fixtures/image-auto-bootstrap').is_dir() else HERE
s=BASE.read_text()
# Local candidate seven-module revision; hosted run uses the corresponding overlay fixtures.
s=s.replace("ENTRY_DIR = FIXTURES/'entrypoints' if FIXTURES.is_dir() else ROOT/'candidate'", "ENTRY_DIR = FIXTURES/'entrypoints' if FIXTURES.is_dir() else ROOT/'next-publication/tests/fixtures/image-auto-pilot/entrypoints'")
s=s.replace("$root.'/synthetic-catalog.sqlite'", "$root.'/database/synthetic-catalog.sqlite'")
s=s.replace("$root.'/synthetic-missing.sqlite'", "$root.'/database/synthetic-missing.sqlite'")
s=s.replace("['/private','/private/inputs'", "['/database','/private','/private/inputs'")
s=s.replace("root/'synthetic-catalog.sqlite'", "root/'database/synthetic-catalog.sqlite'")
s=s.replace("__DIR__.'/remote-fixture.png'", "__DIR__.'/private/remote-fixture.png'")
s=s.replace("$root.'/remote-fixture.png'", "$root.'/private/remote-fixture.png'")
s=s.replace("root/'remote-fixture.png'", "root/'private/remote-fixture.png'")
s=s.replace("__DIR__.'/synthetic-transfer.jsonl'", "__DIR__.'/private/synthetic-transfer.jsonl'")
s=s.replace("root/'synthetic-transfer.jsonl'", "root/'private/synthetic-transfer.jsonl'")
# Assert narrowing before adapter bytes and all PDO opens, not merely eventual success.
s=s.replace("function curl_exec(SyntheticCurlHandle$h):bool{", "function curl_exec(SyntheticCurlHandle$h):bool{ if(\\ini_get('open_basedir')==='')throw new \\RuntimeException('Unconfined transport');")
s=s.replace("$ro=openDb($file", "if(ini_get('open_basedir')==='')throw new RuntimeException('Unconfined PDO');$ro=openDb($file")
s=s.replace("config=json.loads((root/'config.json').read_text());config['network_enabled']=False;", "original_config=(root/'config.json').read_bytes();config=json.loads(original_config);config['network_enabled']=False;")
s=s.replace("config['network_enabled']=True;(root/'config.json').write_text(json.dumps(config))", "(root/'config.json').write_bytes(original_config)")
insertion=r'''
        helper=BOOTSTRAP_DIR/'FixedImageBootstrap.php'
        shutil.copyfile(helper,root/'FixedImageBootstrap.php')
        pins={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in (root/'src').glob('*.php') if p.name in ['ImageIntakeBinding.php','ImagePilot.php','ImageSourceIntake.php','ImageSourceSelection.php','ImportedImage.php','PosixImageIntakeStore.php','ResponsiveImage.php']}
        for extra in (root/'src').iterdir():
            if extra.name not in pins:
                if extra.is_dir(): shutil.rmtree(extra)
                else: extra.unlink()
        config_sha=hashlib.sha256((root/'config.json').read_bytes()).hexdigest()
        def preflight(acquire):
            # Pins are emitted into the trusted fixed launcher, NOT accepted from config.
            return "require_once __DIR__.'/FixedImageBootstrap.php';\n$b=\\FAS\\Utils\\FixedImageBootstrap::start(__DIR__.'/src',__DIR__.'/config.json','"+config_sha+"',json_decode('"+json.dumps(pins)+"',true),"+str(os.geteuid())+","+('true' if acquire else 'false')+");\n$c=$b['config'];$store=$b['store'];\nif(ini_get('open_basedir')==='')throw new RuntimeException('No restriction');\n"
        for entry,acquire,cut in [('intake.php',True,"$f=$c['private'].'/intake-request.json';"),('automatic-pilot.php',False,"if(!defined('PDO::SQLITE_ATTR_OPEN_FLAGS')")]:
            original=(ENTRY_DIR/entry).read_text()
            body=original[original.index(cut):]
            modes="$argv[1]!=='acquire'" if acquire else "!in_array($argv[1],['enqueue','run','inspect'],true)"
            guard="if(count($argv)!==2||"+modes+")throw new RuntimeException('Explicit mode required');\n"
            (root/entry).write_text("<?php\ndeclare(strict_types=1);\n"+guard+preflight(acquire)+body)
        pdo=(root/'pdo-check.php').read_text().replace("$root=__DIR__;",preflight(False)+"$root=__DIR__;")
        (root/'pdo-check.php').write_text(pdo)
'''
s=s.replace("        return root,info",insertion+"\n        return root,info")
s=s.replace("'real_modules':'PDO SQLite/GD/fileinfo/EXIF/POSIX and exact candidate entrypoints'", "'real_modules':'PDO SQLite/GD/fileinfo/EXIF/POSIX after narrowing; derived bootstrap entrypoints'")
namespace={'__file__':str(BASE),'__name__':'__main__','BOOTSTRAP_DIR':BOOTSTRAP}
exec(compile(s,str(HERE/'derived-lifecycle-test.py'),'exec'),namespace)
