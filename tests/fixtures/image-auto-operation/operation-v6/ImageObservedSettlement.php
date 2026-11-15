<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/ImageOperationBinding.php';
require_once __DIR__.'/../candidate/src/ImageEffectObservation.php';

/** TEST-ONLY same-process adapter. Caller owns PDO transaction and commit/rollback. */
final class ImageObservedSettlement
{
    private ?ImageEffectObservation $observer=null;
    private static array $failedAbortHolds=[];
    private array $evidence;
    private array $context;
    private string $sourceSha;
    private ImageOperationBinding $operation;
    public function __construct(private \PDO $db,private array $binding,string $state,string $site,bool $enabled=false)
    {
        if(!$enabled||$db->inTransaction())throw new \RuntimeException('Explicit disabled-by-default observer before DB reservation required');
        $this->operation=new ImageOperationBinding($binding);ImageOperationBinding::assertSchema($db);
        $q=$db->prepare("SELECT CASE WHEN typeof(proof_json)='text' AND length(CAST(proof_json AS BLOB))<=8192 THEN proof_json ELSE NULL END,CASE WHEN typeof(proof_sha256)='text' AND length(CAST(proof_sha256 AS BLOB))=64 THEN proof_sha256 ELSE NULL END FROM main.ledger_acquisitions WHERE attempt_id=? LIMIT 2");$q->execute([$binding['attempt_id']]);$rows=$q->fetchAll(\PDO::FETCH_NUM);
        if(count($rows)!==1||!is_string($rows[0][0])||!is_string($rows[0][1])||hash('sha256',$rows[0][0])!==$rows[0][1])throw new \RuntimeException('Exact stored acquisition proof required');
        $this->sourceSha=$rows[0][1];
        try{
            $this->observer=new ImageEffectObservation($state,$site,true);
            $pins=[];foreach(['attempt_id','product_id','revision','snapshot_sha256','operation_key','source_selector','source_url','receipt_preimage_sha256']as$k)$pins[$k]=$binding[$k];
            $result=$this->observer->observe($pins,$rows[0][0],$rows[0][1]);
            if(($result['observation']??null)!=='matches_after_complete'||!is_array($result['evidence']??null))throw new \RuntimeException('Complete actual effect observation required');
            $this->evidence=$result['evidence'];$this->context=$this->observer->privateContext();
        }catch(\Throwable$e){if($this->observer!==null)$this->observer->release();$this->observer=null;throw$e;}
    }
    public function observedAt():int{return $this->evidence['observed_at'];}
    private static function encode(array $v):string{return ImageOperationBinding::canonical($v);}
    private static function one(\PDO $db,string $sql,array $params):array{$q=$db->prepare($sql);$q->execute($params);return$q->fetchAll(\PDO::FETCH_NUM);}
    /** Caller beginTransaction(), this method obtains write lock; caller commits before close(). */
    public function apply(array $quota,int $now,?\Closure $checkpoint=null):array
    {
        if($this->observer===null||!$this->db->inTransaction())throw new \RuntimeException('Live observer and caller-owned PDO transaction required');
        $checkpoint??=static function(string $_):void{};
        ImageOperationBinding::assertSchema($this->db);$b=$this->binding;$attempt=$b['attempt_id'];
        // Guarded semantic no-op obtains the SQLite write reservation on caller's deferred transaction.
        // We do NOT accept a caller boolean claiming BEGIN IMMEDIATE or use PDO state heuristically.
        $q=$this->db->prepare("UPDATE main.ledger_capacity SET state=state WHERE attempt_id=? AND state<>'released'");$q->execute([$attempt]);$checkpoint('write_reserved');
        $raw=self::encode($this->evidence);if(strlen($raw)>65536)throw new \RuntimeException('Settlement evidence bound');$digest=hash('sha256',$raw);
        $prior=self::one($this->db,"SELECT CASE WHEN typeof(evidence_json)='text' AND length(CAST(evidence_json AS BLOB))<=65536 THEN evidence_json ELSE NULL END,evidence_sha256 FROM main.ledger_settlements WHERE attempt_id=? LIMIT 2",[$attempt]);
        if($prior){
            if(count($prior)!==1||!is_string($prior[0][0])||hash('sha256',$prior[0][0])!==$prior[0][1])throw new \RuntimeException('Stored settlement corrupt');
            $old=json_decode($prior[0][0],true,24,JSON_THROW_ON_ERROR);$fresh=$this->evidence;
            if(!is_array($old)||!is_int($old['observed_at']??null)||$fresh['observed_at']<$old['observed_at']||$fresh['observed_at']>$now||$now-$fresh['observed_at']>300)throw new \RuntimeException('Existing settlement observation differs');
            unset($old['observed_at'],$fresh['observed_at']);
            if(self::encode($old)!==self::encode($fresh)||self::one($this->db,"SELECT a.owner_token,a.fence,a.operation_key,a.phase,a.effect_state,c.state FROM main.ledger_attempts a JOIN main.ledger_capacity c ON c.attempt_id=a.attempt_id WHERE a.attempt_id=? LIMIT 2",[$attempt])!==[[$b['owner_token'],$b['fence'],$b['operation_key'],'terminal_recorded','settled','released']])throw new \RuntimeException('Existing settlement conflict');
            return ['state'=>'already_settled','dispatch_authorized'=>false,'locks_held'=>true];
        }
        if($q->rowCount()!==1)throw new \RuntimeException('Owned retained reservation required');
        $verified=$this->operation->verify($this->db,'reconciliation',$now,$this->context['before_receipt'],$this->context['record'],$this->context['intake_pins']);
        if($verified['acquisition_sha256']!==$this->sourceSha)throw new \RuntimeException('Acquisition changed after observation');
        $keys=array_keys($quota);sort($keys,SORT_STRING);$expected=explode(' ','observed_at private_free public_free quota_limit quota_used tmp_free');sort($expected,SORT_STRING);
        if($keys!==$expected||$now<0)throw new \RuntimeException('Exact quota accounting fields');foreach($quota as$v)if(!is_int($v)||$v<0)throw new \RuntimeException('Strict quota observations required');
        $seen=$this->evidence['observed_at'];if($seen>$now||$now-$seen>300||$quota['observed_at']<$seen||$quota['observed_at']>$now||$now-$quota['observed_at']>300||$quota['quota_limit']<1||$quota['quota_used']>$quota['quota_limit'])throw new \RuntimeException('Fresh post-effect subscription accounting required');
        $receipt=json_decode($this->evidence['receipt_json'],true,16,JSON_THROW_ON_ERROR);$retained=$receipt['original_bytes']+strlen($this->evidence['receipt_json']);foreach($this->evidence['variants']as$v)$retained+=$v['bytes'];
        if($quota['quota_used']<$retained)throw new \RuntimeException('Quota observation cannot account for retained artifacts');
        $statement=$this->db->prepare('INSERT INTO main.ledger_settlements(attempt_id,evidence_json,evidence_sha256,quota_json)VALUES(?,?,?,?)');$statement->execute([$attempt,$raw,$digest,self::encode($quota)]);$checkpoint('evidence');
        $summary=self::encode(['version'=>'synthetic-settlement-v1','attempt_id'=>$attempt,'operation_key'=>$b['operation_key'],'evidence_sha256'=>$digest]);
        $statement=$this->db->prepare('INSERT INTO main.ledger_receipts(attempt_id,receipt_sha256,canonical_packet)VALUES(?,?,?)');$statement->bindValue(1,$attempt);$statement->bindValue(2,hash('sha256',$summary));$statement->bindValue(3,$summary,\PDO::PARAM_LOB);$statement->execute();$checkpoint('receipt');
        $statement=$this->db->prepare("UPDATE main.ledger_attempts SET effect_state='settled' WHERE attempt_id=? AND owner_token=? AND fence=? AND phase='terminal_recorded' AND effect_state='unresolved'");$statement->execute([$attempt,$b['owner_token'],$b['fence']]);if($statement->rowCount()!==1)throw new \RuntimeException('Settlement CAS failed');
        $statement=$this->db->prepare("UPDATE main.ledger_capacity SET state='released' WHERE attempt_id=? AND state IN ('reserved','running','unknown')");$statement->execute([$attempt]);if($statement->rowCount()!==1)throw new \RuntimeException('Reservation release CAS failed');$checkpoint('released');
        return ['state'=>'settlement_staged','commit_required'=>true,'evidence_sha256'=>$digest,'retained_bytes'=>$retained,'dispatch_authorized'=>false,'locks_held'=>true];
    }
    public function close():void{if($this->db->inTransaction())throw new \RuntimeException('Commit/rollback before releasing observation locks');if($this->observer!==null)$this->observer->release();$this->observer=null;}
    public function __destruct(){
        if($this->observer===null)return;
        if($this->db->inTransaction()){
            try{if(!$this->db->rollBack()||$this->db->inTransaction())throw new \RuntimeException('Abandoned settlement rollback unconfirmed');}
            catch(\Throwable){self::$failedAbortHolds[]=[$this->db,$this->observer];$this->observer=null;return;}
        }
        $this->observer->release();$this->observer=null;
    }
}
