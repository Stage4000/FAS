<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/admin-accounts.php';
$checks = 0;
function accountCheck(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
$file = tempnam(sys_get_temp_dir(), 'fas-accounts-');
$db = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE admin_users(id INTEGER PRIMARY KEY,username TEXT UNIQUE,email TEXT UNIQUE,full_name TEXT,password_hash TEXT,role TEXT,is_active INTEGER,updated_at TEXT)");
fas_admin_accounts_init($db);
$hash = password_hash('Synthetic-password-123', PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO admin_users VALUES(?,?,?, ?,?,'admin',1,NULL)");
foreach ([1,2] as $id) $stmt->execute([$id,'fixture-'.$id,'fixture-'.$id.'@example.invalid','Fixture '.$id,$hash]);
$one = fas_admin_session_token(fas_admin_account($db,1));
$two = fas_admin_session_token(fas_admin_account($db,2));
accountCheck($one !== $two, 'Sessions are bound to identity even with identical password hashes');
accountCheck(!fas_admin_password_valid(str_repeat('a',73)), 'Passwords over bcrypt byte limit rejected');
accountCheck(!fas_admin_password_valid(str_repeat('a',12)."\0"), 'NUL bytes rejected');
accountCheck(!fas_admin_password_valid(['array']), 'Non-string password rejected');
require_once __DIR__.'/../admin/auth.php';
$initial = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$initial->exec("CREATE TABLE admin_users(id INTEGER PRIMARY KEY,username TEXT UNIQUE,email TEXT UNIQUE,full_name TEXT,password_hash TEXT,role TEXT,is_active INTEGER DEFAULT 1,updated_at TEXT)");
$bootstrap = new AdminAuth($initial);
try {
    $bootstrap->createInitialAdmin('admin','admin@example.invalid','admin123');
    throw new RuntimeException('Weak bootstrap password accepted');
} catch (InvalidArgumentException $e) {
    accountCheck((int)$initial->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()===0, 'Weak bootstrap password creates no account');
}
accountCheck($bootstrap->createInitialAdmin('owner-fixture','owner@example.invalid','Bootstrap-password-123'), 'Strong initial administrator can be created');
accountCheck(!$bootstrap->createInitialAdmin('other-fixture','other@example.invalid','Another-password-123'), 'Bootstrap cannot create a second administrator');
unset($initial,$bootstrap);
// Two independently authenticated workers attempt to remove each other's access.
$children=[];
foreach ([[1,$one,2],[2,$two,1]] as [$actor,$token,$target]) {
    $code = 'require '.var_export(__DIR__.'/../includes/admin-accounts.php',true).';'
        .'$db=new PDO('.var_export('sqlite:'.$file,true).');fas_admin_accounts_init($db);'
        .'try {fas_admin_account_change($db,'.(int)$actor.','.var_export($token,true).',"deactivate",["id"=>'.(int)$target.']);echo "ok";}'
        .'catch(InvalidArgumentException $e){echo "denied";}';
    $process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $children[]=[$process,$pipes];
}
$results=[];
foreach ($children as [$process,$pipes]) {
    $results[]=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    accountCheck(proc_close($process)===0 && $error==='', 'Concurrent account worker completed');
}
sort($results);
accountCheck($results===['denied','ok'], 'Only one concurrent deactivation succeeds');
accountCheck((int)$db->query('SELECT SUM(is_active) FROM admin_users')->fetchColumn()===1, 'At least one active admin survives concurrent changes');
accountCheck((int)$db->query('SELECT COUNT(*) FROM admin_account_events')->fetchColumn()===1, 'Denied concurrent change leaves no success audit');
$active=(int)$db->query('SELECT id FROM admin_users WHERE is_active=1')->fetchColumn();
$token=fas_admin_session_token(fas_admin_account($db,$active));
try {
    fas_admin_account_change($db,$active,$token,'deactivate',['id'=>$active]);
    throw new RuntimeException('Last-admin protection missing');
} catch (InvalidArgumentException $e) { accountCheck(strpos($e->getMessage(),'At least one')!==false, 'Last-active-admin guard is explicit'); }
// Audit must be atomic with the account write, even on storage failure.
$db->exec("CREATE TRIGGER reject_account_audit BEFORE INSERT ON admin_account_events BEGIN SELECT RAISE(ABORT,'fixture storage failure'); END");
try {
    fas_admin_account_change($db,$active,$token,'update',['id'=>$active,'username'=>'changed-fixture','email'=>'changed@example.invalid','full_name'=>'Changed']);
    throw new RuntimeException('Expected audit failure');
} catch (PDOException $e) {
    accountCheck(fas_admin_account($db,$active)['username']==='fixture-'.$active, 'Audit failure rolls back account changes');
}
unset($stmt,$db);unlink($file);
echo "PASS $checks account security assertions, including concurrent deactivation.\n";
