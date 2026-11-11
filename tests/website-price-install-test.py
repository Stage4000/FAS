"""SQLite installer regression tests: disposable data, native PHP/PDO, no network.
Lock waits are zero; retry limits are not a native DDL runtime guarantee.
"""
from pathlib import Path
import json, os, sqlite3, subprocess, tempfile, time, unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT/'scripts/install-website-prices.php'
HELPER = ROOT/'src/utils/WebsitePrice.php'
WORKER = r'''<?php
declare(strict_types=1);
require $argv[1];
use FAS\Utils\WebsitePrice;
class InstrumentedPDO extends PDO {
    public array $ops=[];
    public string $fault='';
    public bool $legacy=false;
    public $beforeBegin=null;
    public $afterBusy=null;
    public bool $probeAfterRollback=false;
    public string $path='';
    public function inTransaction():bool { return $this->legacy ? false : parent::inTransaction(); }
    public function exec(string $sql):int|false {
        $this->ops[]=$sql;
        if ($sql==='BEGIN IMMEDIATE' && $this->beforeBegin) {
            $hook=$this->beforeBegin;$this->beforeBegin=null;$hook();
        }
        if ($sql==='BEGIN IMMEDIATE' && $this->fault==='locked') {
            $e=new PDOException('Synthetic SQLITE_LOCKED');$e->errorInfo=['HY000',6,'locked'];throw $e;
        }
        if (str_starts_with($sql,'CREATE TABLE website_price_history') && in_array($this->fault,['ddl','rollback'],true)) throw new RuntimeException('Synthetic DDL fault');
        if ($sql==='ROLLBACK' && $this->fault==='rollback') throw new RuntimeException('Synthetic rollback fault');
        if ($sql==='PRAGMA busy_timeout=1234' && $this->fault==='restore' && in_array('COMMIT',$this->ops,true)) throw new RuntimeException('Synthetic restore fault');
        try { $result=parent::exec($sql); }
        catch(PDOException $e) {
            if ($sql==='BEGIN IMMEDIATE' && $this->afterBusy && (((int)($e->errorInfo[1]??0)&255)===5)) {
                $hook=$this->afterBusy;$this->afterBusy=null;$hook();
            }
            throw $e;
        }
        if ($sql==='ROLLBACK' && $this->path!=='') {
            $probe=new PDO('sqlite:'.$this->path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $probe->exec('PRAGMA busy_timeout=0');$probe->exec('BEGIN IMMEDIATE');$probe->exec('ROLLBACK');$this->probeAfterRollback=true;
        }
        return $result;
    }
}
function state(PDO $db):array {
    $schema=$db->query("SELECT name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $rows=[];foreach($schema as $s) {
        if (str_starts_with($s['sql'],'CREATE TABLE')) $rows[$s['name']]=$db->query('SELECT * FROM "'.str_replace('"','""',$s['name']).'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    return [$schema,$rows];
}
$db=new InstrumentedPDO('sqlite:'.$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->path=$argv[2];$db->exec('PRAGMA busy_timeout=1234');$before=state($db);$mode=$argv[3];$other=null;
if(in_array($mode,['legacy','fault_legacy','ambient_legacy'],true))$db->legacy=true;
if($mode==='ddl'||$mode==='fault_legacy')$db->fault='ddl';
if($mode==='rollback')$db->fault='rollback';
if($mode==='restore')$db->fault='restore';
if($mode==='locked')$db->fault='locked';
if($mode==='silent')$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_SILENT);
if(in_array($mode,['transient','deadline','competitor'],true)) {
    $other=new PDO('sqlite:'.$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if($mode==='competitor')$db->beforeBegin=static function()use($other){WebsitePrice::install($other);};
    else {
        $other->exec('BEGIN IMMEDIATE');
        $db->afterBusy=static function()use($other,$mode){if($mode==='deadline')usleep(240000);$other->exec('ROLLBACK');};
    }
}
if($mode==='commit_reader') {
    $other=new PDO('sqlite:'.$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->exec('BEGIN');$other->query('SELECT * FROM products')->fetchAll(PDO::FETCH_ASSOC);
}
if($mode==='ambient'||$mode==='ambient_legacy') {
    if($mode==='ambient')$db->beginTransaction();else$db->exec('BEGIN IMMEDIATE');
    $db->exec('UPDATE products SET quantity=77 WHERE id=1');
}
$caught=null;$started=hrtime(true);
try {WebsitePrice::install($db);}catch(Throwable $e){$caught=['class'=>get_class($e),'message'=>$e->getMessage(),'previous'=>$e->getPrevious()?->getMessage()];}
$elapsed=(hrtime(true)-$started)/1000000;
$after=state($db);$busy=(int)$db->query('PRAGMA busy_timeout')->fetchColumn();
$report=['error'=>$caught,'elapsed_ms'=>$elapsed,'busy_timeout'=>$busy,'ops'=>$db->ops,'same_state'=>$before===$after,
    'probe_after_rollback'=>$db->probeAfterRollback,'quantity'=>(int)$db->query('SELECT quantity FROM products WHERE id=1')->fetchColumn()];
// Test-only cleanup of intentionally retained ambient/uncertain transactions.
$db->fault='';try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}
$other=null;$db=null;echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
'''

class Installer(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.area=tempfile.TemporaryDirectory(prefix='price-install-',dir=ROOT.parent)
        cls.base=Path(cls.area.name);cls.worker=cls.base/'worker.php';cls.worker.write_text(WORKER)

    @classmethod
    def tearDownClass(cls):
        assert cls.base.resolve().parent==ROOT.parent.resolve() and cls.base.name.startswith('price-install-')
        cls.area.cleanup()

    def fixture(self,mode='DELETE'):
        path=self.base/(self.id().rsplit('.',1)[-1]+'-'+os.urandom(4).hex()+'.sqlite')
        db=sqlite3.connect(path,timeout=0)
        db.executescript((ROOT/'tests/fixtures/security-catalog.sql').read_text())
        db.execute("UPDATE products SET ebay_item_id='QA-1',source='ebay',sale_price=80 WHERE id=1")
        db.execute("INSERT INTO orders(id,order_number,subtotal,total_amount) VALUES(1,'SYNTHETIC',75,84)")
        db.execute('INSERT INTO order_items(id,order_id,product_id,unit_price,total_price) VALUES(1,1,1,75,75)')
        db.commit();db.execute('PRAGMA journal_mode='+mode);db.close();return path

    def snapshot(self,path):
        db=sqlite3.connect(path,timeout=0)
        try:
            schema=db.execute("SELECT name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name").fetchall()
            return schema,{name:db.execute('SELECT * FROM "'+name+'" ORDER BY rowid').fetchall() for name,sql in schema if sql.startswith('CREATE TABLE')}
        finally:db.close()

    def cli(self,path,check=False):
        args=['php',str(SCRIPT)]+(['--check'] if check else [])+['--database',str(path)]
        started=time.monotonic();r=subprocess.run(args,capture_output=True,text=True,timeout=5)
        try:data=json.loads(r.stdout or r.stderr)
        except ValueError:self.fail('Non-JSON installer result: '+r.stdout+r.stderr)
        return r,data,time.monotonic()-started

    def worker_case(self,path,mode,helper=HELPER):
        r=subprocess.run(['php',str(self.worker),str(helper),str(path),mode],capture_output=True,text=True,timeout=5)
        self.assertEqual(r.returncode,0,r.stderr);return json.loads(r.stdout)

    def test_no_op_and_read_only_check(self):
        path=self.fixture();before=self.snapshot(path)
        r,data,_=self.cli(path,True);self.assertEqual(r.returncode,3);self.assertTrue(data['read_only']);self.assertEqual(before,self.snapshot(path))
        r,data,_=self.cli(path);self.assertEqual(r.returncode,0);self.assertFalse(data['native_ddl_runtime_is_bounded']);self.assertEqual(data['sqlite_lock_wait_ms'],0)
        db=sqlite3.connect(path);db.execute('UPDATE products SET website_override_cents=6025,price=60.25 WHERE id=1')
        db.execute("INSERT INTO website_price_source VALUES(1,11000,'USD',2,'fixture')")
        db.execute("INSERT INTO website_price_history(product_id,actor_id,action,before_json,after_json,created_at) VALUES(1,17,'override','{}','{}','fixture')");db.commit();db.close()
        installed=self.snapshot(path)
        self.assertEqual(self.cli(path)[0].returncode,0);self.assertEqual(installed,self.snapshot(path))
        r,data,_=self.cli(path,True);self.assertEqual(r.returncode,0);self.assertTrue(data['read_only']);self.assertEqual(installed,self.snapshot(path))

    def held_writer(self,mode):
        path=self.fixture(mode);before=self.snapshot(path);db=sqlite3.connect(path,timeout=0)
        try:
            db.execute('BEGIN IMMEDIATE');db.execute('UPDATE products SET quantity=77 WHERE id=1')
            r,data,elapsed=self.cli(path);self.assertEqual(r.returncode,75,data);self.assertEqual(data['status'],'busy')
            self.assertLess(elapsed,1.5,'Must not inherit long SQLite busy waits');self.assertIn('begin',data['error'])
            self.assertEqual(db.execute('SELECT quantity FROM products WHERE id=1').fetchone()[0],77)
        finally:db.rollback();db.close()
        self.assertEqual(before,self.snapshot(path));self.assertEqual(self.cli(path)[0].returncode,0)
        db=sqlite3.connect(path);self.assertEqual(db.execute('PRAGMA journal_mode').fetchone()[0],mode.lower());db.close()

    def test_held_delete_writer(self):self.held_writer('DELETE')
    def test_held_wal_writer(self):self.held_writer('WAL')

    def test_delete_reader_blocks_commit_atomically(self):
        path=self.fixture();before=self.snapshot(path);reader=sqlite3.connect(path,timeout=0)
        try:
            reader.execute('BEGIN');reader.execute('SELECT * FROM products').fetchall()
            r,data,elapsed=self.cli(path);self.assertEqual(r.returncode,75,data);self.assertIn('commit',data['error']);self.assertLess(elapsed,1.5)
            self.assertEqual(before,self.snapshot(path),'All column/table/marker writes must roll back on commit contention')
            probe=sqlite3.connect(path,timeout=0);probe.execute('BEGIN IMMEDIATE');probe.rollback();probe.close()
        finally:reader.rollback();reader.close()
        self.assertEqual(self.cli(path)[0].returncode,0)

    def test_wal_reader_retains_snapshot_while_install_succeeds(self):
        path=self.fixture('WAL');reader=sqlite3.connect(path,timeout=0)
        try:
            reader.execute('BEGIN');reader.execute('SELECT * FROM products').fetchall()
            self.assertEqual(self.cli(path)[0].returncode,0)
            self.assertNotIn('website_override_cents',[x[1] for x in reader.execute('PRAGMA table_info(products)')])
        finally:reader.rollback();reader.close()
        self.assertEqual(self.cli(path,True)[0].returncode,0)

    def test_commit_contention_restores_timeout_and_releases_writer(self):
        data=self.worker_case(self.fixture(),'commit_reader')
        self.assertIn('Busy',data['error']['class']);self.assertEqual(data['busy_timeout'],1234)
        self.assertTrue(data['same_state']);self.assertTrue(data['probe_after_rollback'])
        self.assertEqual(data['ops'].count('COMMIT'),3);self.assertEqual(data['ops'].count('ROLLBACK'),3)
        self.assertLess(data['elapsed_ms'],1000,'Must not inherit1234ms busy handler for each commit')

    def test_transient_writer_retries_only_after_release(self):
        path=self.fixture();data=self.worker_case(path,'transient')
        self.assertIsNone(data['error']);self.assertEqual(data['ops'].count('BEGIN IMMEDIATE'),2);self.assertEqual(data['busy_timeout'],1234)

    def test_competing_installer_is_rechecked_under_writer_lock(self):
        path=self.fixture();data=self.worker_case(path,'competitor')
        self.assertIsNone(data['error']);self.assertFalse(any(x.startswith('ALTER TABLE') for x in data['ops']))
        self.assertEqual(self.cli(path,True)[0].returncode,0)

    def test_two_real_processes_install_once(self):
        path=self.fixture();args=['php',str(SCRIPT),'--database',str(path)]
        jobs=[subprocess.Popen(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(2)]
        results=[]
        try:
            for job in jobs:
                out,err=job.communicate(timeout=5);results.append((job.returncode,out+err))
        finally:
            for job in jobs:
                if job.poll() is None:job.kill();job.communicate()
        for code,message in results:self.assertEqual(code,0,message)
        db=sqlite3.connect(path);self.assertEqual(db.execute('SELECT * FROM website_price_meta').fetchall(),[(1,1)]);db.close()

    def test_concurrent_read_only_status_is_old_or_new_or_busy(self):
        path=self.fixture();jobs=[subprocess.Popen(['php',str(SCRIPT)]+flags+['--database',str(path)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for flags in [[],['--check']]]
        try:
            install_out,install_err=jobs[0].communicate(timeout=5);check_out,check_err=jobs[1].communicate(timeout=5)
            self.assertIn(jobs[0].returncode,[0,75],install_out+install_err)
            self.assertIn(jobs[1].returncode,[0,3,75],check_out+check_err)
            self.assertTrue(json.loads(check_out or check_err)['read_only'])
        finally:
            for job in jobs:
                if job.poll() is None:job.kill();job.communicate()
        if jobs[0].returncode==75:self.assertEqual(self.cli(path)[0].returncode,0)
        self.assertEqual(self.cli(path,True)[0].returncode,0)

    def test_legacy_pdo_transaction_reporting_does_not_break_install(self):
        data=self.worker_case(self.fixture(),'legacy');self.assertIsNone(data['error']);self.assertEqual(data['busy_timeout'],1234)
        self.assertIn('BEGIN IMMEDIATE',data['ops']);self.assertIn('COMMIT',data['ops'])

    def test_failure_rolls_back_with_legacy_pdo_reporting(self):
        data=self.worker_case(self.fixture(),'fault_legacy');self.assertIsNotNone(data['error']);self.assertTrue(data['same_state']);self.assertTrue(data['probe_after_rollback']);self.assertEqual(data['busy_timeout'],1234)

    def test_nonbusy_failure_does_not_retry(self):
        data=self.worker_case(self.fixture(),'ddl');self.assertEqual(data['ops'].count('BEGIN IMMEDIATE'),1);self.assertTrue(data['same_state']);self.assertTrue(data['probe_after_rollback'])
        locked=self.worker_case(self.fixture(),'locked');self.assertEqual(locked['ops'].count('BEGIN IMMEDIATE'),1);self.assertTrue(locked['same_state']);self.assertEqual(locked['error']['class'],'PDOException')
        silent=self.worker_case(self.fixture(),'silent');self.assertEqual(silent['ops'].count('BEGIN IMMEDIATE'),0);self.assertTrue(silent['same_state']);self.assertEqual(silent['busy_timeout'],1234)

    def test_rollback_uncertainty_is_not_retried(self):
        data=self.worker_case(self.fixture(),'rollback');self.assertEqual(data['ops'].count('BEGIN IMMEDIATE'),1)
        self.assertIn('Uncertain',data['error']['class']);self.assertIn('Synthetic DDL fault',data['error']['previous']);self.assertIn('rollback',data['error']['message'])

    def test_restoration_failure_after_commit_does_not_claim_rollback(self):
        path=self.fixture();data=self.worker_case(path,'restore');self.assertIn('Uncertain',data['error']['class']);self.assertIn('commit was confirmed',data['error']['message'])
        self.assertEqual(self.cli(path,True)[0].returncode,0)

    def test_ambient_transactions_are_never_rolled_back_by_installer(self):
        for mode in ['ambient','ambient_legacy']:
            with self.subTest(mode=mode):
                path=self.fixture();before=self.snapshot(path);data=self.worker_case(path,mode)
                self.assertIsNotNone(data['error']);self.assertEqual(data['quantity'],77);self.assertEqual(before,self.snapshot(path))

    def test_deadline_exhaustion_prevents_further_attempts(self):
        data=self.worker_case(self.fixture(),'deadline');self.assertIn('Busy',data['error']['class']);self.assertEqual(data['ops'].count('BEGIN IMMEDIATE'),1);self.assertTrue(data['same_state']);self.assertEqual(data['busy_timeout'],1234)

    def test_partial_and_missing_schema_are_not_repaired(self):
        path=self.fixture();db=sqlite3.connect(path);db.execute('ALTER TABLE products ADD COLUMN website_override_cents INTEGER DEFAULT NULL');db.close();before=self.snapshot(path)
        self.assertEqual(self.cli(path)[0].returncode,1);self.assertEqual(before,self.snapshot(path))
        missing=self.base/'missing-products.sqlite';sqlite3.connect(missing).close();before=self.snapshot(missing)
        self.assertEqual(self.cli(missing)[0].returncode,1);self.assertEqual(before,self.snapshot(missing))

    def test_missing_target_is_never_created(self):
        path=self.base/'does-not-exist.sqlite'
        for check in [False,True]:
            args=['php',str(SCRIPT)]+(['--check'] if check else [])+['--database',str(path)]
            result=subprocess.run(args,capture_output=True,text=True,timeout=5);self.assertEqual(result.returncode,2);self.assertFalse(path.exists())

if __name__=='__main__':unittest.main()
