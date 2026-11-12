<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/../operation-v6/ImageOperationBinding.php';
require_once __DIR__.'/ImageQuotaAdmission.php';
final class ImageFrontEndCommitUnknown extends \RuntimeException {}
/** Already-installed exact-v6 only. No installer, launcher, network or implicit approval. */
final class ImageOperationFrontEnd
{
    public function __construct(private \PDO$db,private bool$enabled=false){}
    private static function keys(array$v,string$expected):void{$a=array_keys($v);sort($a);$b=explode(' ',$expected);sort($b);if($a!==$b)throw new \InvalidArgumentException('Exact front-end fields required');}
    private static function hex(mixed$v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{64}$/D',$v)===1;}
    private function rows(string$sql,array$params=[]):array{$q=$this->db->prepare($sql);foreach($params as$i=>$v)$q->bindValue($i+1,$v,is_int($v)?\PDO::PARAM_INT:\PDO::PARAM_STR);$q->execute();return$q->fetchAll(\PDO::FETCH_NUM);}
    private function ready():void
    {
        if(!$this->enabled||PHP_SAPI!=='cli'||$this->db->inTransaction())throw new \RuntimeException('Explicit owned front end required');
        $wait=$this->db->query('PRAGMA busy_timeout')->fetchColumn();if(!is_int($wait)||$wait<0||$wait>1000)throw new \RuntimeException('Bounded strict busy timeout required');
        ImageOperationBinding::assertSchema($this->db);
    }
    private function transaction(\Closure$work):array
    {
        $this->ready();
        ImageOperationBinding::assertSchema($this->db);if(!$this->db->beginTransaction())throw new \RuntimeException('Transaction start unconfirmed');$committing=false;
        try{
            // Establish a real write reservation before current-proof reads; never infer it from a bool.
            if($this->db->exec('UPDATE main.image_handoff_cursor SET last_id=last_id WHERE singleton=1')!==1)throw new \RuntimeException('Owned singleton cursor required');
            ImageOperationBinding::assertSchema($this->db);$out=$work();$committing=true;if(!$this->db->commit()||$this->db->inTransaction())throw new ImageFrontEndCommitUnknown('Commit not confirmed');return$out;
        }catch(\Throwable$e){try{if($this->db->inTransaction()&&(!$this->db->rollBack()||$this->db->inTransaction()))throw new \RuntimeException('Rollback not confirmed');}catch(\Throwable$cleanup){throw new ImageFrontEndCommitUnknown('Rollback uncertain; preserve state, no retry',0,$e);}if($committing)throw new ImageFrontEndCommitUnknown('Commit outcome unknown; reopen/reconcile, never dispatch',0,$e);throw$e;}
    }
    private function product(int$id):?array
    {
        $r=$this->rows("SELECT is_active,show_on_website,
 CASE WHEN typeof(image_url) IN ('text','null') AND COALESCE(length(CAST(image_url AS BLOB)),0)<=2048 THEN image_url ELSE NULL END,
 CASE WHEN typeof(images) IN ('text','null') AND COALESCE(length(CAST(images AS BLOB)),0)<=131072 THEN images ELSE NULL END,
 CASE WHEN typeof(image_url) IN ('text','null') AND COALESCE(length(CAST(image_url AS BLOB)),0)<=2048 AND typeof(images) IN ('text','null') AND COALESCE(length(CAST(images AS BLOB)),0)<=131072 THEN 1 ELSE 0 END FROM main.products WHERE id=? LIMIT 2",[$id]);
        if(count($r)>1)throw new \RuntimeException('Ambiguous product');return$r[0]??null;
    }
    private function handoff(int$id,int$rev):array{return$this->rows("SELECT CASE WHEN typeof(snapshot_json)='text' AND length(CAST(snapshot_json AS BLOB))<=1048576 THEN snapshot_json ELSE NULL END,CASE WHEN typeof(snapshot_sha256)='text' AND length(CAST(snapshot_sha256 AS BLOB))=64 THEN snapshot_sha256 ELSE NULL END FROM main.image_snapshot_handoff WHERE product_id=? AND revision=? LIMIT 2",[$id,$rev]);}
    private function producerBudget(array$quota,int$now,int$limit,int$byteBudget):array
    {
        $active=$this->rows("SELECT attempt_id,bytes,state FROM main.ledger_capacity WHERE state<>'released' LIMIT 1001");$reservations=[];foreach($active as[$job,$bytes,$state])$reservations[]=['job'=>$job,'bytes'=>$bytes,'state'=>$state];
        // Pure policy sentinel only; NEVER allocated as a ledger attempt or reservation.
        $plan=ImageQuotaAdmission::plan($quota,$now,$reservations,str_repeat('0',32));
        if($plan['state']!=='reservation_planned')return['state'=>'pause','reason'=>$plan['reason'],'dispatch_authorized'=>false,'scanned'=>0,'handed_off'=>0];
        $journal=$this->rows('PRAGMA main.journal_mode');$page=$this->rows('PRAGMA main.page_size')[0][0];$pages=$this->rows('PRAGMA main.page_count')[0][0];
        if($journal!==[['delete']]||!is_int($page)||$page<512||$page>65536||($page&($page-1))!==0||!is_int($pages)||$pages<1||$pages>intdiv(PHP_INT_MAX,$page))throw new \RuntimeException('Bounded reviewed rollback-journal/page accounting required');
        $counts=[];foreach(['image_snapshot_handoff','image_handoff_quarantine','image_product_dirty']as$table){$count=$this->rows('SELECT count(*) FROM (SELECT 1 FROM main.'.$table.' LIMIT 10001)')[0][0];if(!is_int($count)||$count<0||$count>10000)return['state'=>'pause','reason'=>'producer_retained_history_bound','dispatch_authorized'=>false,'scanned'=>0,'handed_off'=>0];$counts[]=$count;}
        $raw=$this->rows('SELECT COALESCE(sum(length(CAST(snapshot_json AS BLOB))+length(CAST(snapshot_sha256 AS BLOB))),0) FROM main.image_snapshot_handoff')[0][0];
        if(!is_int($raw)||$raw<0||$raw>16777216)return['state'=>'pause','reason'=>'producer_retained_byte_bound','dispatch_authorized'=>false,'scanned'=>0,'handed_off'=>0];
        // Deliberately re-charge retained history/full current DB, even when already in quota_used.
        // This bounds repeated batches with a reused still-fresh observation without a new queue.
        $charge=max($pages*$page,$raw+array_sum($counts)*4*$page)+$byteBudget+2*($limit+1)*$page;
        if(!is_int($charge)||$charge>PHP_INT_MAX-134217728)throw new \RuntimeException('Producer budget overflow');$required=134217728+$charge;
        if($quota['quota_limit']-$quota['quota_used']<$required||min($quota['private_free'],$quota['public_free'],$quota['tmp_free'])<$required)return['state'=>'pause','reason'=>'producer_history_and_write_headroom','dispatch_authorized'=>false,'scanned'=>0,'handed_off'=>0];
        return['state'=>'producer_budget_planned','logical_write_cap'=>$byteBudget,'charged_bytes'=>$charge,'dispatch_authorized'=>false];
    }
    /** Explicit fresh quota per batch; no unbudgeted default, max8 rows and1MiB logical writes. */
    public function consume(int$limit,array$quota,int$now,int$byteBudget=1048576):array
    {
        if($limit<1||$limit>8||$byteBudget<4096||$byteBudget>1048576)throw new \InvalidArgumentException('Producer batch1..8 and4096..1048576byte cap required');
        $this->ready();$budget=$this->producerBudget($quota,$now,$limit,$byteBudget);if($budget['state']!=='producer_budget_planned')return$budget;
        return$this->transaction(function()use($limit,$quota,$now,$byteBudget):array{
            $budget=$this->producerBudget($quota,$now,$limit,$byteBudget);if($budget['state']!=='producer_budget_planned')return$budget;
            $c=$this->rows('SELECT last_id,upper_id FROM main.image_handoff_cursor WHERE singleton=1');if(count($c)!==1||!is_int($c[0][0])||!is_int($c[0][1])||$c[0][0]<0||$c[0][1]<$c[0][0])throw new \RuntimeException('Strict producer cursor required');[$last,$upper]=$c[0];
            if($last>=$upper){$last=0;$upper=$this->rows('SELECT COALESCE(max(product_id),0) FROM main.image_product_dirty')[0][0];if(!is_int($upper)||$upper<0)throw new \RuntimeException('Dirty high-water type');}
            $rows=$this->rows('SELECT product_id,revision,deleted,acknowledged_revision FROM main.image_product_dirty WHERE product_id>? AND product_id<=? ORDER BY product_id LIMIT ?',[$last,$upper,$limit]);$out=['state'=>'produced','scanned'=>0,'handed_off'=>0,'quarantined'=>0,'skipped'=>0,'logical_write_bytes'=>512,'dispatch_authorized'=>false];$actions=[];
            if(!$rows)$last=$upper;
            // Plan and bound the WHOLE batch before any handoff/ACK/quarantine mutations.
            foreach($rows as[$id,$rev,$deleted,$ack]){
                if(!is_int($id)||$id<1||!is_int($rev)||$rev<1||!in_array($deleted,[0,1],true)||!is_int($ack)||$ack<0||$ack>$rev)throw new \RuntimeException('Strict marker identity required');$last=$id;$out['scanned']++;
                if($ack===$rev||$this->rows("SELECT 1 FROM main.image_handoff_quarantine WHERE product_id=? AND revision=? AND source_scope='*' LIMIT 1",[$id,$rev])){$out['skipped']++;continue;}
                $p=$this->product($id);if(($deleted===1)!==($p===null))throw new \RuntimeException('Tombstone/catalog conflict');$payload=['version'=>2,'product_id'=>$id,'revision'=>$rev,'deleted'=>$deleted===1];$reason=null;
                if($p!==null){[$active,$visible,$primary,$gallery,$valid]=$p;if($valid!==1||!in_array($active,[0,1],true)||!in_array($visible,[0,1],true))$reason='field_bounds';else$payload+=['image_url'=>$primary,'images'=>$gallery,'is_active'=>$active,'show_on_website'=>$visible];}
                if($reason===null){try{$raw=ImageOperationBinding::canonical($payload);if(strlen($raw)>1048576)throw new \RuntimeException('Payload bound');}catch(\JsonException$e){if($e->getCode()!==JSON_ERROR_UTF8)throw$e;$reason='invalid_utf8';}}
                $prior=$this->handoff($id,$rev);
                if($reason!==null){if($prior)throw new \RuntimeException('Existing handoff conflicts with rejected source');$actions[]=['quarantine',$id,$rev,$reason];$out['logical_write_bytes']+=512;$out['quarantined']++;}
                else{$sha=hash('sha256',$raw);if($prior&&$prior!==[[$raw,$sha]])throw new \RuntimeException('Immutable handoff conflict');$actions[]=['handoff',$id,$rev,$raw,$sha,!$prior];$out['logical_write_bytes']+=512+(!$prior?strlen($raw)+64:0);$out['handed_off']++;}
                if($out['logical_write_bytes']>$byteBudget)throw new \RuntimeException('Whole producer batch exceeds admitted logical write budget');
            }
            foreach($actions as$a){[$kind,$id,$rev]=$a;if($kind==='quarantine'){$q=$this->db->prepare("INSERT INTO main.image_handoff_quarantine VALUES(?,?,?,'*')");$q->execute([$id,$rev,$a[3]]);continue;}
                if($a[5]){$q=$this->db->prepare('INSERT INTO main.image_snapshot_handoff VALUES(?,?,?,?)');$q->execute([$id,$rev,$a[3],$a[4]]);}
                $q=$this->db->prepare('UPDATE main.image_product_dirty SET acknowledged_revision=? WHERE product_id=? AND revision=? AND acknowledged_revision<?');$q->execute([$rev,$id,$rev,$rev]);if($q->rowCount()!==1)throw new \RuntimeException('Conditional ACK failed');
            }
            $q=$this->db->prepare('UPDATE main.image_handoff_cursor SET last_id=?,upper_id=? WHERE singleton=1');$q->execute([$last,$upper]);if($q->rowCount()!==1)throw new \RuntimeException('Cursor CAS failed');return$out;
        });
    }
    /** One rotating visit; absence of a higher row is established by SQL, not page truncation. */
    public function nextProduct():array
    {
        return$this->transaction(function():array{$last=$this->rows('SELECT admission_last_id FROM main.image_handoff_cursor WHERE singleton=1')[0][0];if(!is_int($last)||$last<0)throw new \RuntimeException('Admission cursor type');$r=$this->rows('SELECT product_id FROM main.image_product_dirty WHERE product_id>? ORDER BY product_id LIMIT 1',[$last]);if(!$r)$r=$this->rows('SELECT product_id FROM main.image_product_dirty WHERE product_id<=? ORDER BY product_id LIMIT 1',[$last]);$id=$r[0][0]??null;if($id!==null){if(!is_int($id)||$id<1)throw new \RuntimeException('Visit identity');$q=$this->db->prepare('UPDATE main.image_handoff_cursor SET admission_last_id=? WHERE singleton=1');$q->execute([$id]);}return['product_id'=>$id,'dispatch_authorized'=>false,'requires'=>'current_snapshot_source_policy_and_receipt_observations'];});
    }
    /** Atomic one-operation registration+capacity; supplied policy/receipt/quota need trusted collectors. */
    public function registerRemote(array$s,array$policy,?string$receipt,array$quota,int$now):array
    {
        self::keys($s,'product_id revision snapshot_sha256 source_selector source_url intent receipt_preimage_sha256');
        if(!is_int($s['product_id'])||$s['product_id']<1||!is_int($s['revision'])||$s['revision']<1||!self::hex($s['snapshot_sha256'])||!is_string($s['source_url'])||!in_array($s['intent'],['initial','renew'],true)||$now<0)throw new \InvalidArgumentException('Strict selected identity');$selector=ImageSourceSelection::selector(['source_selector'=>$s['source_selector']]);ImageSourceIntake::source($s['source_url']);
        if(($s['intent']==='initial'&&$s['receipt_preimage_sha256']!==null)||($s['intent']==='renew'&&!self::hex($s['receipt_preimage_sha256'])))throw new \InvalidArgumentException('Null-distinct receipt intent');
        if($receipt!==null&&strlen($receipt)>16384)throw new \InvalidArgumentException('Receipt byte bound');if(($receipt===null?null:hash('sha256',$receipt))!==$s['receipt_preimage_sha256'])throw new \RuntimeException('Receipt observation changed');
        self::keys($policy,'product_id revision snapshot_sha256 source_selector source_url state observed_at reference');
        foreach(['product_id','revision','snapshot_sha256','source_selector','source_url']as$k)if($policy[$k]!==$s[$k])throw new \InvalidArgumentException('Policy identity differs');
        if(!in_array($policy['state'],['allowed','excluded','unknown'],true)||!is_int($policy['observed_at'])||$policy['observed_at']<0||$policy['observed_at']>$now||$now-$policy['observed_at']>300||!is_string($policy['reference'])||trim($policy['reference'])===''||strlen($policy['reference'])>2048)throw new \InvalidArgumentException('Fresh explicit authoritative policy observation required');
        if(strlen(ImageOperationBinding::canonical($policy))>8192)throw new \InvalidArgumentException('Policy evidence byte bound');
        return$this->transaction(function()use($s,$policy,$receipt,$quota,$now,$selector):array{
            $id=$s['product_id'];$rev=$s['revision'];$marker=$this->rows('SELECT revision,deleted,acknowledged_revision FROM main.image_product_dirty WHERE product_id=? LIMIT 2',[$id]);$p=$this->product($id);
            if($marker!==[[$rev,0,$rev]]||$p===null||[$p[0],$p[1],$p[4]]!==[1,1,1])throw new \RuntimeException('Current visible acknowledged lifecycle required');
            $payload=['version'=>2,'product_id'=>$id,'revision'=>$rev,'deleted'=>false,'image_url'=>$p[2],'images'=>$p[3],'is_active'=>1,'show_on_website'=>1];$raw=ImageOperationBinding::canonical($payload);$sha=hash('sha256',$raw);
            if($sha!==$s['snapshot_sha256']||$this->handoff($id,$rev)!==[[$raw,$sha]])throw new \RuntimeException('Exact current handoff differs');
            if(ImageSourceSelection::selected(['image_url'=>$p[2],'images'=>$p[3]],['source_selector'=>$selector])!==$s['source_url'])throw new \RuntimeException('Selected source differs');
            if($this->rows("SELECT 1 FROM main.image_handoff_quarantine WHERE product_id=? AND revision=? AND source_scope IN ('*',?) LIMIT 1",[$id,$rev,ImageOperationBinding::canonical($selector)]))return['state'=>'held_quarantined','dispatch_authorized'=>false];
            if($policy['state']!=='allowed')return['state'=>$policy['state']==='excluded'?'excluded_original_only':'held_policy_unknown','dispatch_authorized'=>false];
            if($receipt!==null){$r=json_decode($receipt,true,16,JSON_THROW_ON_ERROR);if(!is_array($r)||($r['product_id']??null)!==(string)$id||($r['source_url']??null)!==$s['source_url']||!in_array($r['excluded']??false,[false,true],true))throw new \RuntimeException('Receipt identity/exclusion invalid');if(($r['excluded']??false)===true)return['state'=>'excluded_original_only','dispatch_authorized'=>false];}
            $op=ImageOperationBinding::operationHash($s);$old=$this->rows("SELECT attempt_id,product_id,revision,snapshot_sha256,operation_key,intent,source_url,CASE WHEN typeof(source_selector)='text' AND length(CAST(source_selector AS BLOB))<=128 THEN source_selector ELSE NULL END,CASE WHEN typeof(receipt_preimage_sha256)='null' THEN NULL WHEN typeof(receipt_preimage_sha256)='text' AND length(CAST(receipt_preimage_sha256 AS BLOB))=64 THEN receipt_preimage_sha256 ELSE '__invalid' END,phase FROM main.ledger_attempts WHERE operation_key=? LIMIT 2",[$op]);
            if($old){if(count($old)!==1||array_slice($old[0],1,8)!==[$id,$rev,$sha,$op,$s['intent'],$s['source_url'],ImageOperationBinding::canonical($selector),$s['receipt_preimage_sha256']])throw new \RuntimeException('Stored operation identity conflict');return['state'=>'existing_no_retry','attempt_id'=>$old[0][0],'phase'=>$old[0][9],'dispatch_authorized'=>false,'acquisition_authorized'=>false];}
            if($this->rows("SELECT 1 FROM main.ledger_attempts WHERE product_id=? AND (effect_state<>'settled' OR phase<>'terminal_recorded') LIMIT 1",[$id]))return['state'=>'held_unresolved_product_effect','dispatch_authorized'=>false];
            $attempt=bin2hex(random_bytes(16));$owner=bin2hex(random_bytes(16));$inventory=$this->rows("SELECT attempt_id,bytes,state FROM main.ledger_capacity WHERE state<>'released' LIMIT 1001");$reservations=[];foreach($inventory as[$job,$bytes,$state])$reservations[]=['job'=>$job,'bytes'=>$bytes,'state'=>$state];$budget=ImageQuotaAdmission::plan($quota,$now,$reservations,$attempt);if($budget['state']!=='reservation_planned')return$budget;
            if($this->rows('SELECT 1 FROM main.ledger_attempts WHERE attempt_id=? OR owner_token=? LIMIT 1',[$attempt,$owner]))throw new \RuntimeException('Random identity collision; no retry');
            $mapped=$this->rows('SELECT snapshot_sha256 FROM main.ledger_snapshots WHERE product_id=? AND revision=? LIMIT 2',[$id,$rev]);if(!$mapped){$q=$this->db->prepare('INSERT INTO main.ledger_snapshots VALUES(?,?,?)');$q->execute([$id,$rev,$sha]);}elseif($mapped!==[[$sha]])throw new \RuntimeException('Immutable snapshot mapping conflict');
            $q=$this->db->prepare("INSERT INTO main.ledger_attempts(attempt_id,product_id,revision,snapshot_sha256,owner_token,fence,phase,operation_key,intent,source_url,receipt_preimage_sha256,source_selector)VALUES(?,?,?,?,?,1,'registered',?,?,?,?,?)");$q->execute([$attempt,$id,$rev,$sha,$owner,$op,$s['intent'],$s['source_url'],$s['receipt_preimage_sha256'],ImageOperationBinding::canonical($selector)]);
            $q=$this->db->prepare("INSERT INTO main.ledger_capacity VALUES(?,?,'reserved',?)");$q->execute([$attempt,ImageQuotaAdmission::RESERVE,ImageOperationBinding::canonical($quota)]);
            $binding=['attempt_id'=>$attempt,'owner_token'=>$owner,'fence'=>1]+$s+['operation_key'=>$op];
            (new ImageOperationBinding($binding))->verify($this->db,'acquisition_before',$now,$receipt);
            return['state'=>'registered_and_reserved','binding'=>$binding,'policy_reference'=>$policy['reference'],'policy_revalidation_required'=>true,'fresh_acquisition_required'=>true,'dispatch_authorized'=>false,'acquisition_authorized'=>false];
        });
    }
}
