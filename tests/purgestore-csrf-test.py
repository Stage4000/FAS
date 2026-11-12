"""Offline purge request-boundary regression; no server, live data or remote services.

Run: python3 tests/purgestore-csrf-test.py
Optional: PHP_BIN selects PHP; FAS_AUDIT_SOURCE selects the full source root.
Only allowlisted PHP source files are copied into a TemporaryDirectory. Real
configuration is never copied. The actual candidate controller is tested without
modification; its pre-fix negative control is reconstructed only in the fixture.
Both the database and session backend are explicitly memory-only. Every request
uses a fresh PHP process with a clean environment and disabled outbound transports.
"""
from pathlib import Path
import base64
import hashlib
import json
import os
import re
import shutil
import subprocess
import tempfile

ROOT = Path(os.environ.get('FAS_AUDIT_SOURCE', Path(__file__).resolve().parents[1])).resolve()
PHP = os.environ.get('PHP_BIN', 'php')
PHP = shutil.which(PHP) or str(Path(PHP).expanduser().resolve())
FILES = (
    'admin/purgestore.php', 'admin/auth.php', 'src/config/Database.php',
    'src/utils/Timezone.php', 'src/utils/CSRF.php', 'includes/admin-accounts.php',
    'includes/security.php', 'src/security/SecurityStore.php', 'src/security/ClientIp.php',
)
TOKEN = 'a' * 64
IMPORT = "require_once __DIR__ . '/../src/utils/CSRF.php';\n"
GUARD = r'''
// Validate every POST before any purge operation.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\FAS\Utils\CSRF::validateToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    header('Cache-Control: private, no-store');
    exit('Invalid security token. Refresh the page and try again.');
}
'''
FIELD = '                                        <?php echo \\FAS\\Utils\\CSRF::tokenField(); ?>\n'

RUNNER = r'''<?php
// This runner is generated inside a disposable fixture, never in the live site.
declare(strict_types=1);
$case = json_decode(base64_decode($argv[2], true), true, 512, JSON_THROW_ON_ERROR);
$variant = $argv[1];
if (!in_array($variant, ['baseline', 'candidate'], true)) throw new RuntimeException('Bad variant');
$encodedSession = isset($case['session_data']) ? base64_decode($case['session_data'], true) : '';
if ($encodedSession === false) throw new RuntimeException('Bad synthetic session');
$backend = new class($encodedSession) implements SessionHandlerInterface {
    private string $data;
    public function __construct(string $data) { $this->data = $data; }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string { return $this->data; }
    public function write(string $id, string $data): bool { $this->data = $data; return true; }
    public function destroy(string $id): bool { $this->data = ''; return true; }
    public function gc(int $max): int { return 0; }
};
session_set_save_handler($backend, true);
session_id('syntheticpurgefixture');
session_start();
$_SERVER = ['REQUEST_METHOD'=>$case['method'] ?? 'POST', 'HTTP_HOST'=>'fixture.invalid', 'HTTPS'=>'on'];
$_POST = $case['post'] ?? [];
$_GET = [];
require __DIR__.'/site/src/config/Database.php';
require __DIR__.'/site/includes/admin-accounts.php';
require __DIR__.'/site/src/utils/CSRF.php';
$db = \FAS\Config\Database::getInstance()->getConnection();
if ($db->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'] !== '') throw new RuntimeException('Expected in-memory SQLite');
$db->exec("CREATE TABLE products(id INTEGER PRIMARY KEY, name TEXT, source TEXT);
    INSERT INTO products VALUES(1,'Synthetic imported A','ebay'),(2,'Synthetic imported B','ebay'),(3,'Synthetic manual','manual');
    CREATE TABLE ebay_sync_log(id INTEGER PRIMARY KEY, detail TEXT);
    INSERT INTO ebay_sync_log VALUES(1,'Synthetic first sync'),(2,'Synthetic second sync');
    CREATE TABLE admin_users(id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, email TEXT, role TEXT, is_active INTEGER);
    INSERT INTO admin_users VALUES(1,'synthetic-admin','not-a-live-password-hash','fixture@example.invalid','admin',1);");
fas_admin_accounts_init($db);
if (!array_key_exists('session_data', $case)) {
    $_SESSION = ['admin_logged_in'=>true, 'admin_id'=>1,
        'admin_session_token'=>fas_admin_session_token(fas_admin_account($db,1)), 'csrf_token'=>str_repeat('a',64)];
}
$auth = $case['auth'] ?? 'active';
if ($auth==='anonymous') unset($_SESSION['admin_logged_in'],$_SESSION['admin_id'],$_SESSION['admin_session_token']);
if ($auth==='inactive') $db->exec('UPDATE admin_users SET is_active=0 WHERE id=1');
if ($auth==='non-admin') $db->exec("UPDATE admin_users SET role='customer' WHERE id=1");
if ($auth==='revoked') fas_admin_revoke_sessions($db,1);
if ($auth==='wrong-session') $_SESSION['admin_session_token']='invalid-session';
if ($case['no_session_csrf'] ?? false) unset($_SESSION['csrf_token']);
if ($case['delete_failure'] ?? false) $db->exec("CREATE TRIGGER synthetic_log_failure BEFORE DELETE ON ebay_sync_log BEGIN SELECT RAISE(ABORT,'Synthetic rollback test'); END;");
$snapshot = static fn() => ['products'=>$db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'logs'=>$db->query('SELECT * FROM ebay_sync_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
$before = $snapshot();
ob_start();
register_shutdown_function(static function() use ($snapshot,$before) {
    $body = ob_get_clean();
    $error = error_get_last();
    $csrf = $_SESSION['csrf_token'] ?? null;
    $encodedSession = base64_encode(session_encode());
    session_write_close();
    echo json_encode(['status'=>http_response_code() ?: 200, 'before'=>$before, 'after'=>$snapshot(),
        'body'=>$body, 'session_csrf'=>$csrf, 'session_data'=>$encodedSession, 'error'=>$error], JSON_THROW_ON_ERROR);
});
require __DIR__.'/site/admin/'.($variant==='baseline'?'purgestore.baseline.php':'purgestore.php');
'''

POST = {'confirm_purge':'1', 'confirmation':'DELETE ALL STORE ITEMS', 'csrf_token':TOKEN}
CASES = [
    ('GET renders', {'method':'GET','post':{}}, 'same', 200),
    ('HEAD never mutates', {'method':'HEAD','post':POST}, 'same', 200),
    ('PUT never mutates', {'method':'PUT','post':POST}, 'same', 200),
    ('Valid token permits intentional purge', {'post':POST}, 'purged', 200),
    ('Missing token', {'post':{k:v for k,v in POST.items() if k!='csrf_token'}}, 'csrf', 403),
    ('Empty token', {'post':dict(POST,csrf_token='')}, 'csrf', 403),
    ('Wrong token', {'post':dict(POST,csrf_token='invalid')}, 'csrf', 403),
    ('Array token', {'post':dict(POST,csrf_token=['invalid'])}, 'csrf', 403),
    ('Different session token', {'post':dict(POST,csrf_token='b'*64)}, 'csrf', 403),
    ('No session token', {'post':POST,'no_session_csrf':True}, 'csrf', 403),
    ('Wrong phrase', {'post':dict(POST,confirmation='wrong')}, 'same', 200),
    ('Array phrase', {'post':dict(POST,confirmation=['DELETE ALL STORE ITEMS'])}, 'same', 200),
    ('Missing phrase', {'post':{k:v for k,v in POST.items() if k!='confirmation'}}, 'same', 200),
    ('Missing confirm button', {'post':{k:v for k,v in POST.items() if k!='confirm_purge'}}, 'same', 200),
    ('Invalid token without button', {'post':{'csrf_token':'wrong'}}, 'allpost', 403),
    ('Anonymous denied', {'post':POST,'auth':'anonymous'}, 'same', 302),
    ('Inactive denied', {'post':POST,'auth':'inactive'}, 'same', 403),
    ('Non-admin denied', {'post':POST,'auth':'non-admin'}, 'same', 403),
    ('Revoked session denied', {'post':POST,'auth':'revoked'}, 'same', 403),
    ('Wrong session signature denied', {'post':POST,'auth':'wrong-session'}, 'same', 403),
    ('Delete failure rolls back both tables', {'post':POST,'delete_failure':True}, 'same', 200),
]


def sha(data):
    return hashlib.sha256(data).hexdigest()


def field(body):
    found = re.findall(r'name="csrf_token" value="([^"]+)"', body)
    assert len(found) <= 1, 'Expected at most one purge CSRF field'
    return found[0] if found else None


def purged(row):
    return row['after']['products'] == [{'id':3,'name':'Synthetic manual','source':'manual'}] and row['after']['logs'] == []


def record(results, variant, name, row, expected, status, extra=True):
    same = row['before'] == row['after']
    passed = row['status'] == status and (same if expected=='same' else purged(row)) and row['error'] is None and extra
    results.append({'variant':variant, 'case':name, 'passed':bool(passed), 'status':row['status'],
        'mutated':not same, 'products_remaining':len(row['after']['products']), 'logs_remaining':len(row['after']['logs'])})


def main():
    source = {rel:(ROOT/rel).read_bytes() for rel in FILES}
    hashes = {rel:sha(data) for rel,data in source.items()}
    candidate = source['admin/purgestore.php'].decode('utf-8')
    # Fail if the candidate is unpatched; never silently repair code under test.
    for added in (IMPORT, GUARD, FIELD):
        assert candidate.count(added) == 1, 'Candidate is missing or has duplicated reviewed CSRF boundary'
    baseline = candidate.replace(IMPORT,'',1).replace(GUARD,'',1).replace(FIELD,'',1)
    results = []
    version = subprocess.check_output([PHP, '-v'], env={'PATH':os.defpath}, text=True).splitlines()[0]
    with tempfile.TemporaryDirectory(prefix='fas-purgestore-csrf-') as temp:
        base = Path(temp)
        site = base/'site'
        for rel,data in source.items():
            target = site/rel
            target.parent.mkdir(parents=True,exist_ok=True)
            target.write_bytes(data)
            assert sha(target.read_bytes()) == hashes[rel]
        (site/'admin/purgestore.baseline.php').write_text(baseline,encoding='utf-8')
        # Entirely new synthetic config. Never read or copy the source root's config.php.
        (site/'src/config/config.php').write_text("<?php return ['database'=>['path'=>':memory:'],'site'=>['timezone'=>'UTC'],'security'=>['admin_password_salt'=>'synthetic-purge-review-only']];\n",encoding='utf-8')
        runner = base/'runner.php'
        runner.write_text(RUNNER,encoding='utf-8')
        disabled = 'mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec'
        command = [PHP,'-d','disable_functions='+disabled,'-d','allow_url_fopen=0','-d','allow_url_include=0',str(runner)]

        def request(variant,case):
            payload = base64.b64encode(json.dumps(case).encode()).decode()
            proc = subprocess.run(command+[variant,payload], env={'PATH':os.defpath}, capture_output=True,text=True,timeout=15)
            assert proc.returncode == 0 and not proc.stderr, (variant,proc.returncode,proc.stderr,proc.stdout)
            try:
                return json.loads(proc.stdout)
            except json.JSONDecodeError as error:
                raise AssertionError('Runner returned non-JSON output: '+proc.stdout[:1000]) from error

        for variant in ('baseline','candidate'):
            phrase_response = None
            for name,case,expected,status in CASES:
                row = request(variant,case)
                target,target_status = expected,status
                if variant=='baseline' and expected=='csrf': target,target_status = 'purged',200
                if variant=='baseline' and expected=='allpost': target,target_status = 'same',200
                if variant=='candidate' and expected in ('csrf','allpost'): target = 'same'
                extra = True
                if name in ('GET renders','Wrong phrase'):
                    token = field(row['body'])
                    extra = token is None if variant=='baseline' else token == TOKEN
                if name=='Valid token permits intentional purge': extra = 'Purge Complete!' in row['body']
                if name=='Delete failure rolls back both tables': extra = 'Synthetic rollback test' in row['body']
                if name=='Wrong phrase':
                    extra = extra and row['session_csrf'] == TOKEN and 'Confirmation text did not match' in row['body']
                    phrase_response = row
                record(results,variant,name,row,target,target_status,extra)

            # Fresh session has no CSRF secret until the actual form renders it.
            fresh = request(variant,{'method':'GET','post':{},'no_session_csrf':True})
            token = field(fresh['body'])
            generated = token is not None and re.fullmatch(r'[0-9a-f]{64}',token) is not None and token == fresh['session_csrf']
            extra = token is None and fresh['session_csrf'] is None if variant=='baseline' else generated
            record(results,variant,'Fresh GET generates and renders a session token',fresh,'same',200,extra)
            fresh_post = {k:v for k,v in POST.items() if k!='csrf_token'}
            if token is not None: fresh_post['csrf_token'] = token
            followup = request(variant,{'post':fresh_post,'session_data':fresh['session_data']})
            record(results,variant,'Fresh GET token supports intentional POST in same synthetic session',followup,'purged',200,'Purge Complete!' in followup['body'])

            # Reuse the error response's actual session and form field, not a new secret.
            retry_post = {k:v for k,v in POST.items() if k!='csrf_token'}
            retry_token = field(phrase_response['body'])
            if retry_token is not None: retry_post['csrf_token'] = retry_token
            retried = request(variant,{'post':retry_post,'session_data':phrase_response['session_data']})
            record(results,variant,'Failed phrase preserves usable token for intentional retry',retried,'purged',200,'Purge Complete!' in retried['body'])

        for rel,data in source.items():
            assert (ROOT/rel).read_bytes() == data, 'Source changed during test: '+rel
            assert (site/rel).read_bytes() == data, 'Fixture source changed during test: '+rel

    report = {'scope':'Offline CLI, actual unchanged controller/auth/helpers, synthetic serialized sessions, SQLite :memory:, no browser or production exploit claim.',
        'php':version, 'source_sha256':hashes, 'negative_control_sha256':sha(baseline.encode()),
        'checks':len(results), 'passed':sum(row['passed'] for row in results), 'results':results}
    print(json.dumps(report,indent=2))
    assert report['passed'] == report['checks'], 'Regression assertion failed'


if __name__ == '__main__':
    main()
