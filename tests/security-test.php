<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/security/SecurityStore.php';
use FAS\Security\SecurityStore;
use FAS\Security\ClientIp;
$checks = 0;
function expectSecurity($ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function denied(callable $fn): void {
    try { $fn(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected validation rejection');
}
$now = 10000;
$store = new SecurityStore(new PDO('sqlite::memory:'),static function () use (&$now) { return $now; });
$ip = '192.0.2.1';
expectSecurity(!$store->active(),'Starts in observation until deployment verification');
$store->activate(true);
for ($i=0;$i<5;$i++) expectSecurity($store->check(['contact'=>$ip],$ip)['allowed'],'Initial burst');
$r = $store->check(['contact'=>$ip],$ip);
expectSecurity(!$r['allowed'] && $r['retry_after']===120,'Exact refill delay');
$now += 30;
expectSecurity($store->check(['contact'=>$ip],$ip)['retry_after']===90,'Denials do not extend restriction');
$now += 90;
expectSecurity($store->check(['contact'=>$ip],$ip)['allowed'],'Refills one token');
expectSecurity($store->check(['contact'=>'192.0.2.2'],'192.0.2.2')['allowed'],'Independent IP');
// Two independent service instances share counters, regardless of cookies/session IDs.
$other = new SecurityStore($store->db,static function () use (&$now) { return $now; });
expectSecurity(!$other->check(['contact'=>$ip],$ip)['allowed'],'Counters shared across instances');
$store->saveRule('shipping',1,60,'enforce',$ip,1);
expectSecurity($store->check(['shipping'=>$ip],$ip)['allowed'],'First shipping route');
expectSecurity(!$other->check(['shipping'=>$ip],$ip)['allowed'],'Alternate shipping route shares budget');
for ($i=0;$i<5;$i++) expectSecurity($store->check(['login_pair'=>$ip.'|user','login_ip'=>$ip,'login_account'=>'user'],$ip)['allowed'],'Login burst');
expectSecurity(!$store->check(['login_pair'=>$ip.'|user','login_ip'=>$ip,'login_account'=>'user'],$ip)['allowed'],'Username and IP throttle');
$before = $store->run("SELECT tokens FROM security_buckets WHERE rule='login_ip'")->fetchColumn();
for ($i=0;$i<8;$i++) $store->check(['login_pair'=>$ip.'|user','login_ip'=>$ip,'login_account'=>'user'],$ip);
expectSecurity($before===$store->run("SELECT tokens FROM security_buckets WHERE rule='login_ip'")->fetchColumn(),'Atomic denial does not consume unrelated bucket');
for ($i=0;$i<15;$i++) expectSecurity($store->check(['login_account'=>'user'], '198.51.100.'.($i+1))['allowed'],'Distributed login attempts consume account budget');
expectSecurity(!$store->check(['login_account'=>'user'],'203.0.113.9')['allowed'],'Distributed account throttle');
expectSecurity(count($store->restrictions())>=3,'Active restrictions visible');
for ($i=0;$i<10;$i++) expectSecurity($store->check(['settings_account'=>'admin-1','settings_ip'=>$ip],$ip)['allowed'],'Settings burst');
expectSecurity(!$other->check(['settings_account'=>'admin-1','settings_ip'=>'198.51.100.10'],'198.51.100.10')['allowed'],'Settings account limit survives new connection and service instance');
expectSecurity($store->check(['settings_account'=>'admin-2','settings_ip'=>$ip],$ip)['allowed'],'Other administrator retains independent account budget');
$now += 30;
expectSecurity($store->check(['settings_account'=>'admin-1','settings_ip'=>$ip],$ip)['allowed'],'Settings token refills after thirty seconds');
$store->saveRule('settings_ip',1,300,'enforce',$ip,1);
expectSecurity($store->check(['settings_account'=>'admin-3','settings_ip'=>$ip],$ip)['allowed'],'Settings IP burst');
expectSecurity(!$store->check(['settings_account'=>'admin-4','settings_ip'=>$ip],$ip)['allowed'],'Settings IP budget spans administrator accounts');
$store->saveRule('contact',1,60,'observe',$ip,1);
expectSecurity($store->check(['contact'=>$ip],$ip)['allowed'],'Observation first token');
expectSecurity($store->check(['contact'=>$ip],$ip)['allowed'],'Observation permits exhausted budget');
expectSecurity($store->summary()['observed']>0,'Observation logged');
$store->block('203.0.113.1',900,'Repeated submissions',$ip,1);
expectSecurity(!$store->check(['shipping'=>'203.0.113.1'],'203.0.113.1')['allowed'],'Manual block applies');
expectSecurity($store->check(['payment_recovery'=>'203.0.113.1'],'203.0.113.1',true)['allowed'],'Payment recovery bypasses manual block');
denied(fn()=>$store->block($ip,900,'self',$ip,1));
denied(fn()=>$store->block('173.245.48.1',900,'edge',$ip,1));
denied(fn()=>$store->block('203.0.113.1/24',900,'cidr',$ip,1));
denied(fn()=>$store->block('203.0.113.1',999,'duration',$ip,1));
denied(fn()=>$store->saveRule('invented',5,60,'enforce',$ip,1));
denied(fn()=>$store->saveRule('contact',0,60,'enforce',$ip,1));
$now += 901;
expectSecurity($store->check(['shipping'=>'203.0.113.1'],'203.0.113.1')['allowed'],'Block expires');
$store->block('203.0.113.2',900,'test',$ip,1);
$store->unblock('203.0.113.2',$ip,1);
expectSecurity($store->check(['contact'=>'203.0.113.2'],'203.0.113.2')['allowed'],'Emergency unblock');
$_SERVER['SCRIPT_FILENAME']='/site/api/contact-form.php';
$_SERVER['REQUEST_URI']='/api/contact-form.php?password=NEVER_RECORD';
for ($i=0;$i<10;$i++) $store->event($ip,'contact','throttled');
$logged = $store->run("SELECT * FROM security_events WHERE path='contact-form.php'")->fetchAll();
expectSecurity(strpos(json_encode($logged),'NEVER_RECORD')===false,'Request query not logged');
expectSecurity(count($logged)===1 && (int)$logged[0]['count']===10,'Repeated denials aggregate');
$now+=2592001; $store->prune();
expectSecurity((int)$store->run('SELECT COUNT(*) FROM security_events')->fetchColumn()===0,'Thirty-day retention');
expectSecurity(ClientIp::normalize('2001:0DB8:0000::1')==='2001:db8::1','IPv6 canonicalization');
expectSecurity(ClientIp::normalize('::ffff:192.0.2.1')===$ip,'IPv4-mapped canonicalization');
expectSecurity(ClientIp::resolve(['REMOTE_ADDR'=>$ip,'HTTP_CF_CONNECTING_IP'=>'203.0.113.5','HTTP_X_FORWARDED_FOR'=>'203.0.113.6'])['ip']===$ip,'Forged headers ignored');
expectSecurity(ClientIp::resolve(['REMOTE_ADDR'=>'173.245.48.1','HTTP_CF_CONNECTING_IP'=>'203.0.113.5'])['ip']==='203.0.113.5','Cloudflare peer trusted');
expectSecurity(ClientIp::resolve(['REMOTE_ADDR'=>'173.245.48.1','HTTP_CF_CONNECTING_IP'=>'240.0.0.1','HTTP_CF_CONNECTING_IPV6'=>'2001:db8::1'])['ip']==='2001:db8::1','Pseudo IPv4 restores original IPv6');
putenv('FAS_TRUSTED_PROXY_CIDRS=127.0.0.1/32');
expectSecurity(ClientIp::resolve(['REMOTE_ADDR'=>'127.0.0.1','HTTP_CF_CONNECTING_IP'=>$ip])['ip']===$ip,'Explicit sanitized local proxy trusted');
putenv('FAS_TRUSTED_PROXY_CIDRS');
expectSecurity(ClientIp::inRanges('104.27.255.255',ClientIp::CLOUDFLARE),'CIDR last address');
expectSecurity(!ClientIp::inRanges('104.28.0.0',ClientIp::CLOUDFLARE),'CIDR outside boundary');
// Separate PHP processes contend for the same SQLite writer lock.
$file = tempnam(sys_get_temp_dir(),'fas-security-');
$shared = new SecurityStore(new PDO('sqlite:'.$file));
$shared->activate(true); $shared->saveRule('contact',5,3600,'enforce',$ip,1);
$children = [];
for ($i=0;$i<12;$i++) {
    $code = 'require '.var_export(__DIR__.'/../src/security/SecurityStore.php',true).'; $s=new \FAS\Security\SecurityStore(new PDO('.var_export('sqlite:'.$file,true).')); echo $s->check(["contact"=>"192.0.2.1"],"192.0.2.1")["allowed"]?"1":"0";';
    $process = proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $children[] = [$process,$pipes];
}
$accepted=0;
foreach ($children as [$process,$pipes]) {
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    expectSecurity(proc_close($process)===0 && $error==='', 'Concurrent worker completed');
    $accepted+=(int)$output;
}
expectSecurity($accepted===5,'Concurrent workers cannot overrun capacity');
unset($shared); unlink($file);
// Path checks and outage behavior run in a fresh process; never touch production storage.
$code = 'putenv('.var_export('FAS_SECURITY_DB_PATH='.__DIR__.'/forbidden.sqlite',true).'); require '.var_export(__DIR__.'/../includes/security.php',true).'; echo json_encode([fas_security_check(["contact"=>"x"]),fas_security_check(["payment_recovery"=>"x"],true)]);';
$process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$out=json_decode(stream_get_contents($pipes[1]),true);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
expectSecurity($out[0]['status']===503 && !$out[0]['allowed'],'Unsafe storage fails closed for new submissions');
expectSecurity($out[1]['allowed'],'Storage outage preserves recovery');
expectSecurity(!file_exists(__DIR__.'/forbidden.sqlite'),'No public database created');
echo "PASS $checks security assertions; isolated SQLite only.\n";
