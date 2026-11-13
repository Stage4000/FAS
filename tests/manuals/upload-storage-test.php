<?php
declare(strict_types=1);
require_once getenv('FAS_TEST_MANUAL_LIBRARY') ?: __DIR__.'/../../src/manuals/ManualLibrary.php';
use FAS\Manuals\ManualLibrary;
use FAS\Manuals\ManualStorage;
$checks=0;
function check(bool $condition,string $label):void {global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS $label\n";}
function rejects(callable $action,string $label):void {try{$action();}catch(Throwable $e){check(true,$label);return;}check(false,$label);}
class CommitProbePDO extends PDO {
    public $failBeforeCommit=false; public $failAfterCommit=false; public $failRollback=false;
    public $emulateLegacyTracking=false; public $sqlOwned=false;
    public $sqlCommits=0; public $sqlRollbacks=0; public $pdoCommits=0; public $pdoRollbacks=0;
    public function exec($statement):int {
        $sql=strtoupper(trim($statement));
        if($sql==='COMMIT'){
            $this->sqlCommits++;
            if($this->failBeforeCommit){$this->failBeforeCommit=false;throw new RuntimeException('Synthetic failure before SQL COMMIT.');}
        }
        if($sql==='ROLLBACK'){
            $this->sqlRollbacks++;
            if($this->failRollback){$this->failRollback=false;throw new PDOException('Synthetic rollback unavailable.');}
        }
        $result=parent::exec($statement);
        if($sql==='BEGIN IMMEDIATE')$this->sqlOwned=true;
        if($sql==='COMMIT'||$sql==='ROLLBACK')$this->sqlOwned=false;
        if($sql==='COMMIT'&&$this->failAfterCommit){$this->failAfterCommit=false;throw new RuntimeException('Synthetic lost SQL COMMIT acknowledgment.');}
        if($result===false)throw new RuntimeException('Unexpected non-exception SQL failure.');
        return $result;
    }
    public function inTransaction():bool {return $this->emulateLegacyTracking&&$this->sqlOwned ? false : parent::inTransaction();}
    public function commit():bool {
        $this->pdoCommits++;
        if($this->emulateLegacyTracking&&$this->sqlOwned)throw new PDOException('There is no active transaction (emulated legacy PDO tracking).');
        return parent::commit();
    }
    public function rollBack():bool {
        $this->pdoRollbacks++;
        if($this->emulateLegacyTracking&&$this->sqlOwned)throw new PDOException('There is no active transaction (emulated legacy PDO tracking).');
        return parent::rollBack();
    }
}
function fixture(bool $legacy=false):array {
    $root=sys_get_temp_dir().'/fas-manual-upload-storage-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/site');mkdir($root.'/private');
    file_put_contents($root.'/input.pdf',"%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n");
    $db=new CommitProbePDO('sqlite:'.$root.'/catalog.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->emulateLegacyTracking=$legacy||in_array('--legacy',$GLOBALS['argv'],true);$db->exec('PRAGMA foreign_keys=ON');$db->exec('PRAGMA busy_timeout=1000');ManualLibrary::install($db);
    $input=ManualLibrary::blank();$input['title']='Synthetic retry';$input['manufacturer']='Example';
    return [$root,$db,new ManualLibrary($db,static function(){return true;}),$input];
}
function storage(string $root):ManualStorage {return new ManualStorage($root.'/private',$root.'/site');}
function files(string $root):array {$r=glob($root.'/private/*.pdf');sort($r);return $r;}
function caught(callable $action):Throwable {try{$action();}catch(Throwable $e){return $e;}throw new RuntimeException('Expected an exception.');}
// PHP 8.3 SQLite does not track SQL BEGIN through PDO::inTransaction(). Do not
// bypass the legacy emulator to use newer PDO bookkeeping as a test oracle.
// Observe committed rows and real writer reservations on a separate connection.
function observer(string $root):PDO {
    $other=new PDO('sqlite:'.$root.'/catalog.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$other->exec('PRAGMA busy_timeout=0');
    return $other;
}
function catalogRows(PDO $db):array {return $db->query('SELECT * FROM manuals ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);}
function committedCounts(string $root):array {
    $other=observer($root);
    return [(int)$other->query('SELECT count(*) FROM manuals')->fetchColumn(),(int)$other->query('SELECT count(*) FROM manual_history')->fetchColumn()];
}
function completionCalls(CommitProbePDO $db):array {return [$db->sqlCommits,$db->sqlRollbacks,$db->pdoCommits,$db->pdoRollbacks];}
function writerAvailable(string $root):bool {
    $other=observer($root);
    try{$other->exec('BEGIN IMMEDIATE');}
    catch(PDOException $e){
        // Only SQLITE_BUSY proves contention. An unrelated probe failure must
        // fail the test rather than impersonating a caller-owned writer lock.
        if((int)($e->errorInfo[1]??0)!==5)throw $e;
        return false;
    }
    $other->exec('ROLLBACK');return true;
}
function upload(string $root,PDO $db,ManualLibrary $repo,array $input,string $key):array {
    $store=storage($root);
    try {$file=$store->importLocal($root.'/input.pdf',true);return $repo->create($input,$file,1,$key);}
    finally {$store->settleNewFiles($db);}
}
[$root,$db,$repo,$input]=fixture();$key=str_repeat('a',48);
$first=upload($root,$db,$repo,$input,$key);$original=files($root);
check(count($original)===1 && (int)$db->query('SELECT count(*) FROM manuals')->fetchColumn()===1,'First upload leaves one committed row and one original');
for($i=0;$i<3;$i++){$retry=upload($root,$db,$repo,$input,$key);check($retry['id']===$first['id']&&$retry['revision']===1&&files($root)===$original,'Exact retry retains only the first original: '.($i+1));}
check((int)$db->query('SELECT count(*) FROM manual_history')->fetchColumn()===1,'Retries create no additional audit history');
rejects(fn()=>upload($root,$db,$repo,array_replace($input,['title'=>'Different']),$key),'Conflicting metadata retry rejected');
check(files($root)===$original && $repo->getAdmin(1,1)['draft']===$input,'Conflicting metadata copy removed without changing original or row');
file_put_contents($root.'/input.pdf',str_replace('Catalog','Changed',file_get_contents($root.'/input.pdf')));
rejects(fn()=>upload($root,$db,$repo,$input,$key),'Conflicting PDF retry rejected');
check(files($root)===$original && hash_file('sha256',$original[0])===$first['file']['sha256'],'Conflicting PDF copy removed and original unchanged');
file_put_contents($root.'/input.pdf',file_get_contents($original[0]));
$second=upload($root,$db,$repo,$input,str_repeat('b',48));
check($second['id']!==$first['id'] && count(files($root))===2,'Distinct request keys retain independent originals; no global dedupe policy added');

[$root,$db,$repo,$input]=fixture();
$db->exec("CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON manual_history BEGIN SELECT RAISE(ABORT,'Synthetic audit failure'); END");
rejects(fn()=>upload($root,$db,$repo,$input,str_repeat('c',48)),'Failure after row insert but before audit/commit propagates');
check(files($root)===[] && (int)$db->query('SELECT count(*) FROM manuals')->fetchColumn()===0,'Rolled-back create leaves no row and no unused new file');
$db->exec('DROP TRIGGER synthetic_audit_failure');
$db->failBeforeCommit=true;
rejects(fn()=>upload($root,$db,$repo,$input,str_repeat('d',48)),'Synthetic failure immediately before commit propagates');
check(files($root)===[] && writerAvailable($root) && catalogRows($db)===[] && committedCounts($root)===[0,0],'Failed commit with confirmed rollback safely releases its file');
$db->failAfterCommit=true;
rejects(fn()=>upload($root,$db,$repo,$input,str_repeat('e',48)),'Lost acknowledgment after a real commit propagates');
check(count(files($root))===1 && (int)$db->query('SELECT count(*) FROM manuals')->fetchColumn()===1,'Uncertain commit is resolved by references and keeps committed original');
$existing=$repo->getAdmin(1,1);$retry=upload($root,$db,$repo,$input,str_repeat('e',48));
check($retry['id']===$existing['id'] && count(files($root))===1,'Retry after lost commit acknowledgment does not duplicate storage');

[$root,$db,$repo,$input]=fixture();$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
$denied=new ManualLibrary($db,static function(){return false;});
rejects(fn()=>$denied->create($input,$file,1,str_repeat('f',48)),'Revoked authorization after copy blocks create');
$store->settleNewFiles($db);check(files($root)===[],'Revoked create releases only its unused newly created file');
$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
$repo->create($input,$file,1,str_repeat('1',48));$repo->create($input,$file,1,str_repeat('2',48));
$store->settleNewFiles($db);
check(count(files($root))===1 && (int)$db->query('SELECT count(*) FROM manuals')->fetchColumn()===2,'Every catalog reference is honored, including multiple rows sharing an original');
$store->settleNewFiles($db);check(count(files($root))===1,'Repeated settlement cannot delete a previously retained original');

[$root,$db,$repo,$input]=fixture();$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);$path=$root.'/private/'.$file['key'];
$db->beginTransaction();rejects(fn()=>$store->settleNewFiles($db),'Settlement refuses an unresolved caller transaction');
check(is_file($path),'Unresolved transaction preserves the pending file');$db->rollBack();
$other=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
rejects(fn()=>$store->settleNewFiles($other),'Missing catalog schema prevents cleanup');check(is_file($path),'Unavailable reference catalog preserves the pending file');
$store->settleNewFiles($db);check(!is_file($path),'Known unreferenced file can be settled later after reference checks recover');

[$root,$db,$repo,$input]=fixture();$first=upload($root,$db,$repo,$input,str_repeat('3',48));$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
$db->exec("UPDATE manuals SET file_json='invalid json'");
rejects(fn()=>$store->settleNewFiles($db),'Malformed reference data makes cleanup fail closed');
check(count(files($root))===2 && writerAvailable($root),'Reference-check error retains both old and pending originals and releases the lock');
$q=$db->prepare('UPDATE manuals SET file_json=?');$q->execute([json_encode($first['file'])]);$store->settleNewFiles($db);
check(count(files($root))===1,'Cleanup resumes only after valid reference evidence is available');

[$root,$db,$repo,$input]=fixture();$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);$path=$root.'/private/'.$file['key'];
rename($path,$path.'.held');file_put_contents($path,'Synthetic unrelated replacement');
rejects(fn()=>$store->settleNewFiles($db),'A replaced path cannot impersonate an owned new file');
check(file_get_contents($path)==='Synthetic unrelated replacement' && is_file($path.'.held'),'Path replacement preserves both replacement and originally opened inode');
unlink($path);rename($path.'.held',$path);$store->settleNewFiles($db);check(!is_file($path),'Owned descriptor can be settled after original path identity is restored');
$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);$path=$root.'/private/'.$file['key'];
rename($path,$path.'.held');symlink($root.'/input.pdf',$path);
rejects(fn()=>$store->settleNewFiles($db),'Symlink substitution cannot be removed as an owned regular file');
check(is_link($path) && is_file($root.'/input.pdf') && is_file($path.'.held'),'Symlink target and owned file remain intact');

[$root,$db,$repo,$input]=fixture();$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);unset($store);
check(count(files($root))===1,'Storage destruction never guesses whether an abandoned file is safe to delete');
storage($root)->settleNewFiles($db);check(count(files($root))===1,'A different storage instance cannot clean historical/unowned files');
$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);$store->settleNewFiles($db);
check(count(files($root))===1,'Fresh cleanup does not sweep old unreferenced files');

// Simulate a copy that failed after exclusive creation but before returning file
// metadata. Only this fixture injects the live descriptor into the private map.
$store=storage($root);$key=str_repeat('9',48).'.pdf';$partial=fopen($root.'/private/'.$key,'xb');fwrite($partial,'%PDF-partial');fflush($partial);
$property=new ReflectionProperty(ManualStorage::class,'newFiles');$property->setAccessible(true);$property->setValue($store,[$key=>$partial]);
$store->settleNewFiles($db);check(!is_file($root.'/private/'.$key),'Owned partial copy can be removed without a returned file metadata record');
[$root,$db,$repo,$input]=fixture();$store=storage($root);$file=$store->importLocal($root.'/input.pdf');
$property=new ReflectionProperty(ManualStorage::class,'newFiles');$property->setAccessible(true);
check($property->getValue($store)===[],'Legacy CLI imports do not retain ownership descriptors unless explicitly opted in');
$store->settleNewFiles($db);check(is_file($root.'/private/'.$file['key']),'Legacy CLI import semantics are preserved; cleanup requires explicit ownership tracking');
check(ManualStorage::MAX_BYTES===52428800,'50 MiB intake ceiling unchanged');

// Emulate old PDO SQLite tracking on the available real PDO driver. This does
// not substitute for running the final candidate on the actual hosted PHP 8.3.
[$root,$db,$repo,$input]=fixture(true);$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
$pdoCommits=$db->pdoCommits;$pdoRollbacks=$db->pdoRollbacks;
$first=$repo->create($input,$file,1,str_repeat('a',48));
check(writerAvailable($root)&&committedCounts($root)===[1,1],'Legacy tracking: successful create commits row and history visibly and frees the writer lock');
check($db->sqlCommits===1&&$db->pdoCommits===$pdoCommits,'Legacy tracking: create completes through SQL COMMIT, not PDO commit');
$store->settleNewFiles($db);
check(writerAvailable($root)&&committedCounts($root)===[1,1]&&count(files($root))===1,'Legacy tracking: referenced-file settlement commits and releases its lock');
$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
$retry=$repo->create($input,$file,1,str_repeat('a',48));
check($retry['id']===$first['id']&&writerAvailable($root),'Legacy tracking: duplicate branch also completes its SQL transaction');
$store->settleNewFiles($db);
check(count(files($root))===1&&writerAvailable($root),'Legacy tracking: unused duplicate settlement removes only its own file and frees its lock');
$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
$error=caught(fn()=>$repo->create(array_replace($input,['title'=>'Conflict']),$file,1,str_repeat('a',48)));
check($error instanceof DomainException&&writerAvailable($root)&&committedCounts($root)===[1,1],'Legacy tracking: conflicting create rolls back through SQL and releases its lock');
$store->settleNewFiles($db);
check($db->pdoRollbacks===$pdoRollbacks&&count(files($root))===1,'Legacy tracking: owned error cleanup never relies on PDO rollback');

[$root,$db,$repo,$input]=fixture(true);$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
$db->exec("CREATE TRIGGER synthetic_auto_rollback BEFORE INSERT ON manual_history BEGIN SELECT RAISE(ROLLBACK,'Synthetic automatic rollback'); END");
$error=caught(fn()=>$repo->create($input,$file,1,str_repeat('b',48)));
check(strpos($error->getMessage(),'Synthetic automatic rollback')!==false,'Automatic SQLite rollback preserves the original operation error');
check(writerAvailable($root)&&catalogRows($db)===[]&&committedCounts($root)===[0,0],'Automatic rollback leaves no pending row, committed row or writer lock');
$store->settleNewFiles($db);check(files($root)===[],'Settlement after automatic rollback removes the known unused copy');

foreach(['pdo','sql'] as $callerMode){
    [$root,$db,$repo,$input]=fixture(true);$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
    $path=$root.'/private/'.$file['key'];$before=file_get_contents($path);
    if($callerMode==='pdo')$db->beginTransaction();else $db->exec('BEGIN IMMEDIATE');
    $db->exec("INSERT INTO manuals(revision,request_key,draft_json,file_json,created_at,updated_at) VALUES(1,'caller-owned','{}','{}','test','test')");
    $callerRows=catalogRows($db);$completions=completionCalls($db);
    check(count($callerRows)===1&&committedCounts($root)===[0,0]&&!writerAvailable($root),
        'Caller '.$callerMode.' transaction starts with private pending rows and a real writer reservation');
    caught(fn()=>$repo->create($input,$file,1,str_repeat('c',48)));
    check(completionCalls($db)===$completions&&catalogRows($db)===$callerRows&&committedCounts($root)===[0,0]&&!writerAvailable($root),
        'Caller '.$callerMode.' transaction survives failed nested create without rollback');
    caught(fn()=>$store->settleNewFiles($db));
    check(completionCalls($db)===$completions&&catalogRows($db)===$callerRows&&committedCounts($root)===[0,0]
        &&files($root)===[$path]&&file_get_contents($path)===$before&&!writerAvailable($root),
        'Caller '.$callerMode.' transaction and fresh bytes survive refused settlement');
    if($callerMode==='pdo')$db->rollBack();else $db->exec('ROLLBACK');
    $completions[$callerMode==='pdo'?3:1]++;
    check(completionCalls($db)===$completions&&catalogRows($db)===[]&&committedCounts($root)===[0,0]
        &&writerAvailable($root)&&file_get_contents($path)===$before,
        'Caller '.$callerMode.' owner rollback alone removes the pending row and releases its writer reservation');
    $store->settleNewFiles($db);
    check(writerAvailable($root)&&files($root)===[]&&committedCounts($root)===[0,0],'Caller '.$callerMode.' transaction is resolved only by its owner before settlement resumes');
}

foreach(['create','settlement'] as $operation){
    [$root,$db,$repo,$input]=fixture(true);$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
    $other=new PDO('sqlite:'.$root.'/catalog.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$other->exec('BEGIN IMMEDIATE');
    $db->exec('PRAGMA busy_timeout=0');$completions=completionCalls($db);
    $error=caught($operation==='create'?fn()=>$repo->create($input,$file,1,str_repeat('d',48)):fn()=>$store->settleNewFiles($db));
    check(stripos($error->getMessage(),'locked')!==false&&completionCalls($db)===$completions&&committedCounts($root)===[0,0]
        &&count(files($root))===1&&hash_file('sha256',files($root)[0])===$file['sha256']&&!writerAvailable($root),
        'Failed BEGIN for '.$operation.' claims no ownership and preserves the pending file');
    $other->exec('ROLLBACK');
    check(writerAvailable($root)&&catalogRows($db)===[],
        'Failed BEGIN for '.$operation.' leaves no extra writer lock or pending row after the competing owner rolls back');
    $store->settleNewFiles($db);
    check(writerAvailable($root)&&files($root)===[],'Settlement recovers after the '.$operation.' BEGIN lock is released');
}

[$root,$db,$repo,$input]=fixture(true);$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
$db->failBeforeCommit=true;
$error=caught(fn()=>$repo->create($input,$file,1,str_repeat('e',48)));
check($error->getMessage()==='Synthetic failure before SQL COMMIT.'&&writerAvailable($root)&&catalogRows($db)===[]&&committedCounts($root)===[0,0],'Legacy tracking: pre-COMMIT failure rolls back and releases the lock');
$store->settleNewFiles($db);check(files($root)===[],'Legacy tracking: confirmed failed COMMIT has no referenced original to retain');
$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);$db->failAfterCommit=true;
$error=caught(fn()=>$repo->create($input,$file,1,str_repeat('f',48)));
check($error->getMessage()==='Synthetic lost SQL COMMIT acknowledgment.'&&writerAvailable($root),'Legacy tracking: lost SQL COMMIT acknowledgment preserves the original error and frees the lock');
$store->settleNewFiles($db);
check(count(files($root))===1&&(int)$db->query('SELECT count(*) FROM manuals')->fetchColumn()===1,'Legacy tracking: lost COMMIT acknowledgment never deletes its referenced original');
$retry=upload($root,$db,$repo,$input,str_repeat('f',48));
check($retry['id']===1&&count(files($root))===1&&writerAvailable($root),'Legacy tracking: retry after lost COMMIT acknowledgment stays idempotent');

// Settlement's COMMIT failure must not change a referenced file or retain a lock.
foreach(['before','after'] as $when){
    [$root,$db,$repo,$input]=fixture(true);$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
    $repo->create($input,$file,1,str_repeat('1',48));
    if($when==='before')$db->failBeforeCommit=true;else $db->failAfterCommit=true;
    $error=caught(fn()=>$store->settleNewFiles($db));
    check(strpos($error->getMessage(),'SQL COMMIT')!==false&&writerAvailable($root)&&committedCounts($root)===[1,1],
        'Settlement '.$when.'-COMMIT failure is reported without retaining its writer lock');
    check(count(files($root))===1&&hash_file('sha256',files($root)[0])===$file['sha256'],
        'Settlement '.$when.'-COMMIT failure preserves the referenced original bytes');
}

[$root,$db,$repo,$input]=fixture(true);$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
$db->exec("CREATE TRIGGER synthetic_abort BEFORE INSERT ON manual_history BEGIN SELECT RAISE(ABORT,'Synthetic original create error'); END");
$db->failRollback=true;$rollbacks=$db->sqlRollbacks;$path=$root.'/private/'.$file['key'];$before=file_get_contents($path);
$error=caught(fn()=>$repo->create($input,$file,1,str_repeat('2',48)));
$pendingRows=catalogRows($db);
check(strpos($error->getMessage(),'Synthetic original create error')!==false&&$db->sqlRollbacks===$rollbacks+1
    &&count($pendingRows)===1&&committedCounts($root)===[0,0]&&!writerAvailable($root),
    'Unavailable rollback preserves the original create error and exposes an unresolved SQL transaction');
$completions=completionCalls($db);caught(fn()=>$store->settleNewFiles($db));
check(files($root)===[$path]&&file_get_contents($path)===$before&&completionCalls($db)===$completions
    &&catalogRows($db)===$pendingRows&&committedCounts($root)===[0,0]&&!writerAvailable($root),
    'Unresolved rollback makes settlement preserve files without taking over that transaction');
$db->exec('ROLLBACK');$completions[1]++;
check(completionCalls($db)===$completions&&catalogRows($db)===[]&&committedCounts($root)===[0,0]
    &&writerAvailable($root)&&file_get_contents($path)===$before,
    'Explicit SQL rollback removes the unresolved row and releases its reservation without changing pending bytes');
$store->settleNewFiles($db);
check(files($root)===[]&&writerAvailable($root),'After explicit transaction recovery, settlement can safely resume');

// A failed descriptor check must never overwrite SQL transaction ownership.
// Exercise both this suite's native mode and its --legacy mode without changing
// the preserved file or replacing the original identity-check exception.
foreach(['closed','invalid'] as $descriptorState){
    [$root,$db,$repo,$input]=fixture();$store=storage($root);$file=$store->importLocal($root.'/input.pdf',true);
    $path=$root.'/private/'.$file['key'];$before=file_get_contents($path);
    $property=new ReflectionProperty(ManualStorage::class,'newFiles');$property->setAccessible(true);
    $handles=$property->getValue($store);fclose($handles[$file['key']]);
    if($descriptorState==='invalid'){$handles[$file['key']]=null;$property->setValue($store,$handles);}
    $rollbacks=$db->sqlRollbacks;
    $error=caught(fn()=>$store->settleNewFiles($db));
    check($error instanceof RuntimeException&&$error->getMessage()==='An unused new manual file was preserved because its identity changed.',
        ucfirst($descriptorState).' descriptor preserves the original identity-check error');
    check(is_file($path)&&file_get_contents($path)===$before,
        ucfirst($descriptorState).' descriptor leaves its original bytes intact');
    check($db->sqlRollbacks===$rollbacks+1&&writerAvailable($root)&&committedCounts($root)===[0,0],
        ucfirst($descriptorState).' descriptor rolls back its owned SQL transaction and releases the writer lock');
    $retryError=caught(fn()=>$store->settleNewFiles($db));
    check($retryError->getMessage()===$error->getMessage()&&writerAvailable($root)&&committedCounts($root)===[0,0]&&file_get_contents($path)===$before,
        ucfirst($descriptorState).' descriptor repeated failure preserves bytes and never leaks a writer lock');
}

echo "\n$checks upload-storage checks passed; ".(in_array('--legacy',$argv,true)?'all fixtures use emulated legacy PDO tracking':'native and targeted emulated legacy PDO tracking')." on PHP ".PHP_VERSION."; synthetic fixtures only.\n";
