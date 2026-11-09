<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/../candidate/src/ImagePilot.php';
require_once __DIR__.'/../candidate/src/PosixImageIntakeStore.php';
require_once __DIR__.'/../operation-v6/ImageOperationBinding.php';

/** Default-off ENCODER-PROCESS adapter, fixed gates only; no caller callback or network launch. */
final class ImageOperationPilot extends ImagePilot
{
    private ImageOperationBinding $operation;
    private PosixImageIntakeStore $receipts;
    private ?array $record=null;
    private ?array $intakePins=null;
    public function __construct(private \PDO $ledger,private array $binding,private string $state,private string $publicSite,private bool $operationEnabled=false)
    {
        if(hash_file('sha256',__DIR__.'/../candidate/src/ImagePilot.php')!=='ee8c09146ca5d20ed9e0933b7afd389a202ef3cb05a5df34ef1028557090085a')throw new \RuntimeException('Pinned superclass effect boundaries changed');
        parent::__construct($ledger,$publicSite,$state,$state.'/inputs',$operationEnabled,true);
        $this->operation=new ImageOperationBinding($binding);$this->receipts=new PosixImageIntakeStore($state,$publicSite);
    }
    private function enabled():void{if(!$this->operationEnabled||PHP_SAPI!=='cli'||$this->ledger->inTransaction())throw new \RuntimeException('Explicit isolated encoder adapter required');}
    private function readRecord():array
    {
        $file=$this->state.'/approved-record.json';if(is_link($file)||!is_file($file)||(fileperms($file)&0077)!==0)throw new \RuntimeException('Private fixed manifest required');$raw=file_get_contents($file,false,null,0,16385);if(!is_string($raw)||strlen($raw)>16384)throw new \RuntimeException('Manifest bound');$record=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($record))throw new \RuntimeException('Manifest object required');return$record;
    }
    private function before():?string{return $this->receipts->receipt((string)$this->binding['product_id'],$this->binding['source_url']);}
    private function setPins(array $record):void
    {
        $intake=new ImageIntakeBinding($this->state,$this->state.'/inputs');
        try{$catalog=ImageSourceIntake::snapshot($this->ledger,(string)$this->binding['product_id']);$this->intakePins=$intake->verify($this->binding['attempt_id'],$record,$catalog,$this->before());$this->record=$record;}finally{$intake->release();}
    }
    /** Queue only the actual fixed approved record, then admit one release intent. */
    public function prepare():array
    {
        $this->enabled();$record=$this->readRecord();$this->setPins($record);
        $this->operation->verify($this->ledger,'acquisition_after',time(),$this->before(),$record,$this->intakePins);
        $queued=parent::enqueue($this->binding['attempt_id'],$record);
        $this->ledger->beginTransaction();$committing=false;
        try{
            $q=$this->ledger->prepare("UPDATE main.ledger_capacity SET state=state WHERE attempt_id=? AND state='reserved'");$q->execute([$this->binding['attempt_id']]);if($q->rowCount()!==1)throw new \RuntimeException('Reserved encoder capacity required');
            $this->operation->verify($this->ledger,'acquisition_after',time(),$this->before(),$record,$this->intakePins);
            $q=$this->ledger->prepare("UPDATE main.ledger_attempts SET phase='release_intent' WHERE attempt_id=? AND owner_token=? AND fence=? AND phase='registered' AND effect_state='unresolved'");$q->execute([$this->binding['attempt_id'],$this->binding['owner_token'],$this->binding['fence']]);if($q->rowCount()!==1)throw new \RuntimeException('Release intent CAS refused');$committing=true;if(!$this->ledger->commit()||$this->ledger->inTransaction())throw new \RuntimeException('Commit outcome unconfirmed');
        }catch(\Throwable$e){if($this->ledger->inTransaction())$this->ledger->rollBack();throw new \RuntimeException($committing?'Release intent commit unknown; reconcile queued state':'Release intent refused; queued state retained, no retry',0,$e);}
        return $queued+['release_intent_recorded'=>true,'dispatch_authorized'=>false,'retry_authorized'=>false];
    }
    /** Original arbitrary enqueue API is deliberately closed on this operation adapter. */
    public function enqueue(string $id,array $record):array{throw new \RuntimeException('Use fixed-record prepare; arbitrary enqueue refused');}
    public function run(string $id):array
    {
        $this->enabled();if($id!==$this->binding['attempt_id'])throw new \RuntimeException('Fixed attempt required');$this->setPins($this->readRecord());
        $file=$this->state.'/job-'.$id.'.json';if(is_link($file)||!is_file($file)||(fileperms($file)&0077)!==0)throw new \RuntimeException('Private queued job required');$raw=file_get_contents($file,false,null,0,262145);if(!is_string($raw)||strlen($raw)>262144||($job=json_decode($raw,true,24,JSON_THROW_ON_ERROR))['state']!=='queued')throw new \RuntimeException('Only never-started queued job may run');
        $this->ledger->beginTransaction();$committing=false;
        try{
            $q=$this->ledger->prepare("UPDATE main.ledger_capacity SET state='running' WHERE attempt_id=? AND state='reserved'");$q->execute([$id]);if($q->rowCount()!==1)throw new \RuntimeException('Encoder already started/uncertain');
            $this->operation->verify($this->ledger,'publication',time(),$this->before(),$this->record,$this->intakePins);$committing=true;if(!$this->ledger->commit()||$this->ledger->inTransaction())throw new \RuntimeException('Commit outcome unconfirmed');
        }catch(\Throwable$e){if($this->ledger->inTransaction())$this->ledger->rollBack();throw new \RuntimeException($committing?'Encoder admission commit unknown; no rerun':'Encoder admission refused',0,$e);}
        // Any exception leaves running capacity and the durable pilot state. Never restore reserved.
        return parent::run($id)+['settlement_required'=>true,'retry_authorized'=>false];
    }
    protected function checkpoint(string $point):void
    {
        if(in_array($point,['running','prepared','publishing','variant'],true)){
            if($this->record===null||$this->intakePins===null)throw new \RuntimeException('Operation pins absent');
            $this->operation->verify($this->ledger,'publication',time(),$this->before(),$this->record,$this->intakePins);
        }
        // Receipt checkpoint is AFTER the public commit point and is not used as an admission gate.
    }
}
