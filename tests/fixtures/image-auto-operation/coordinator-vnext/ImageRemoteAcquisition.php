<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/../operation-v6/ImageOperationBinding.php';
require_once __DIR__.'/../candidate/src/PosixImageIntakeStore.php';
require_once __DIR__.'/../candidate/src/ImageIntakeBinding.php';

/** Default-off SOURCE-PROCESS adapter. No encoder, dispatcher, scheduler or retry. */
final class ImageRemoteAcquisition
{
    public function __construct(private \PDO $db,private array $binding,private string $state,private string $site,private bool $enabled=false){}
    private function capacity(string $from,string $to):void
    {
        $q=$this->db->prepare('UPDATE main.ledger_capacity SET state=? WHERE attempt_id=? AND state=?');$q->execute([$to,$this->binding['attempt_id'],$from]);if($q->rowCount()!==1)throw new \RuntimeException('Capacity phase already claimed or uncertain');
    }
    /** Transport is supplied only by trusted fixed source bootstrap; no encoder may share its process. */
    public function acquire(array $request,ImageIntakeTransport $transport):array
    {
        if(!$this->enabled||PHP_SAPI!=='cli'||$this->db->inTransaction())throw new \RuntimeException('Explicit isolated source adapter required');
        $timeout=$this->db->query('PRAGMA busy_timeout');$wait=$timeout===false?null:$timeout->fetchColumn();if(!is_int($wait)||$wait<0||$wait>1000)throw new \RuntimeException('Bounded integer SQLite busy timeout required');
        $b=$this->binding;$operation=new ImageOperationBinding($b);$store=new PosixImageIntakeStore($this->state,$this->site);
        if(($request['job']??null)!==$b['attempt_id']||($request['product_id']??null)!==(string)$b['product_id']||($request['source_url']??null)!==$b['source_url']||($request['intent']??null)!==$b['intent']||!array_key_exists('receipt_preimage_sha256',$request)||$request['receipt_preimage_sha256']!==$b['receipt_preimage_sha256']||ImageSourceSelection::selector($request)!==$b['source_selector']||($request['optimization_authorized']??null)!==true)throw new \RuntimeException('Exact explicit source request required');
        $expected=explode(' ','job product_id source_url reference intent catalog_snapshot receipt_preimage_sha256 optimization_authorized');if(array_key_exists('source_selector',$request))$expected[]='source_selector';$actual=array_keys($request);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected||!is_string($request['reference']??null)||trim($request['reference'])===''||strlen($request['reference'])>2048||!is_array($request['catalog_snapshot']??null)||$request['catalog_snapshot']!==ImageSourceIntake::snapshot($this->db,(string)$b['product_id'])||strlen(json_encode($request,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES,24))>262144)throw new \RuntimeException('Complete bounded current request required before admission');
        // Durable source admission precedes any acquisition IO. A crash leaves running capacity,
        // which the acquisition_before contract refuses; existing private outputs are never retried.
        $this->db->beginTransaction();$committing=false;
        try{
            $this->capacity('reserved','reserved');$operation->verify($this->db,'acquisition_before',time(),$store->receipt((string)$b['product_id'],$b['source_url']));
            $this->capacity('reserved','running');$committing=true;if(!$this->db->commit()||$this->db->inTransaction())throw new \RuntimeException('Commit outcome unconfirmed');
        }catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw new \RuntimeException($committing?'Source admission commit unknown; reconcile, never refetch':'Source admission refused',0,$e);}
        // Exceptions from here retain running capacity and original attempt identity.
        $record=(new ImageSourceIntake($this->db,$store,$transport,true))->acquire($request);
        $intake=new ImageIntakeBinding($this->state,$this->state.'/inputs');
        try{
            $catalog=ImageSourceIntake::snapshot($this->db,(string)$b['product_id']);$before=$store->receipt((string)$b['product_id'],$b['source_url']);$pins=$intake->verify($b['attempt_id'],$record,$catalog,$before);
            $image=$record['intake']['image'];$verified=strtotime($record['verified_at']);$until=strtotime($record['valid_until']);if(!is_int($verified)||!is_int($until))throw new \RuntimeException('Exact source times required');
            $proof=['version'=>1,'attempt_id'=>$b['attempt_id'],'operation_key'=>$b['operation_key'],'snapshot_sha256'=>$b['snapshot_sha256'],'source_selector'=>$b['source_selector'],'source_url'=>$b['source_url'],'source_sha256'=>$image['sha256'],'source_bytes'=>$image['bytes'],'mime'=>$image['mime'],'width'=>$image['width'],'height'=>$image['height'],'verified_at'=>$verified,'valid_until'=>$until,'intake_journal_sha256'=>$pins['journal_sha256'],'intake_manifest_sha256'=>$pins['manifest_sha256']];
            $raw=ImageOperationBinding::canonical($proof);if(strlen($raw)>8192)throw new \RuntimeException('Acquisition proof bound');
            $this->db->beginTransaction();$committing=false;
            try{
                // Returning to reserved is atomic with immutable proof insertion. It cannot reopen fetch:
                // acquisition_before also requires NO ledger_acquisitions row.
                $this->capacity('running','reserved');$operation->verify($this->db,'acquisition_before',time(),$before);
                $q=$this->db->prepare('INSERT INTO main.ledger_acquisitions(attempt_id,proof_json,proof_sha256)VALUES(?,?,?)');$q->execute([$b['attempt_id'],$raw,hash('sha256',$raw)]);
                $operation->verify($this->db,'acquisition_after',time(),$before,$record,$pins);$committing=true;if(!$this->db->commit()||$this->db->inTransaction())throw new \RuntimeException('Commit outcome unconfirmed');
            }catch(\Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw new \RuntimeException($committing?'Source pin commit unknown; reconcile, never refetch':'Source pin refused; retained attempt requires reconciliation',0,$e);}
            return ['state'=>'acquired_and_pinned','attempt_id'=>$b['attempt_id'],'acquisition_sha256'=>hash('sha256',$raw),'retry_authorized'=>false,'dispatch_authorized'=>false];
        }finally{$intake->release();}
    }
}
