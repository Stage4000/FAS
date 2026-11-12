<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/../candidate/src/ImageSourceSelection.php';
require_once __DIR__.'/../candidate/src/ImageSourceIntake.php';

/** Read-only experimental exact-v6 adapter. Never opens/commits DB or grants dispatch. */
final class ImageOperationBinding
{
    private const MANIFEST_SHA256='171a8d26e4a9e2979cd696a484728ba2b3031feab94daa50a0c9cecd7f0128a1';
    private array $binding;
    public function __construct(array $binding)
    {
        self::keys($binding,'attempt_id owner_token fence product_id revision snapshot_sha256 operation_key intent source_selector source_url receipt_preimage_sha256');
        foreach(['attempt_id','owner_token']as$k)if(!is_string($binding[$k])||!preg_match('/^[a-f0-9]{32}$/D',$binding[$k]))throw new \RuntimeException('Fixed private attempt identity required');
        foreach(['product_id','revision','fence']as$k)if(!is_int($binding[$k])||$binding[$k]<1)throw new \RuntimeException('Strict positive identity required');
        if($binding['fence']!==1||!self::hex($binding['snapshot_sha256'])||!self::hex($binding['operation_key'])||!in_array($binding['intent'],['initial','renew'],true))throw new \RuntimeException('Exact operation contract required');
        ImageSourceSelection::selector(['source_selector'=>$binding['source_selector']]);
        if(!is_string($binding['source_url']))throw new \RuntimeException('Exact URL required');ImageSourceIntake::source($binding['source_url']);
        if(($binding['intent']==='initial'&&$binding['receipt_preimage_sha256']!==null)||($binding['intent']==='renew'&&!self::hex($binding['receipt_preimage_sha256'])))throw new \RuntimeException('Null-distinct receipt intent required');
        if(self::operationHash($binding)!==$binding['operation_key'])throw new \RuntimeException('Canonical operation key differs');
        $this->binding=$binding;
    }
    private static function keys(array $value,string $keys):void{$a=array_keys($value);sort($a,SORT_STRING);$b=explode(' ',$keys);sort($b,SORT_STRING);if($a!==$b)throw new \RuntimeException('Exact fields required');}
    private static function hex(mixed $x):bool{return is_string($x)&&preg_match('/^[a-f0-9]{64}$/D',$x)===1;}
    private static function ordered(mixed $x):mixed{if(is_array($x)){if(!array_is_list($x))ksort($x,SORT_STRING);foreach($x as$k=>$v)$x[$k]=self::ordered($v);}return $x;}
    public static function canonical(array $value):string{return json_encode(self::ordered($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS);}
    public static function operationHash(array $b):string
    {
        return hash('sha256',self::canonical(['version'=>1,'product_id'=>$b['product_id'],'revision'=>$b['revision'],'snapshot_sha256'=>$b['snapshot_sha256'],'source_url'=>$b['source_url'],'source_selector'=>$b['source_selector'],'intent'=>$b['intent'],'receipt_preimage_sha256'=>$b['receipt_preimage_sha256']]));
    }
    private static function rows(\PDO $db,string $sql,array $params=[]):array{$q=$db->prepare($sql);foreach($params as$i=>$v)$q->bindValue($i+1,$v,is_int($v)?\PDO::PARAM_INT:\PDO::PARAM_STR);$q->execute();return $q->fetchAll(\PDO::FETCH_NUM);}
    private static function text(string $table,string $column,int $bound,bool $nullable=false):string
    { $c=$table.'.'.$column;return "CASE WHEN typeof($c)='text' AND length(CAST($c AS BLOB))<=$bound THEN $c ELSE NULL END"; }
    private static function gate(\PDO $db):void
    {
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='sqlite'||$db->getAttribute(\PDO::ATTR_ERRMODE)!==\PDO::ERRMODE_EXCEPTION||$db->getAttribute(\PDO::ATTR_STRINGIFY_FETCHES))throw new \RuntimeException('Strict SQLite connection required');
        if(self::rows($db,'PRAGMA foreign_keys')!==[[1]]||self::rows($db,'PRAGMA ignore_check_constraints')!==[[0]])throw new \RuntimeException('Constraint enforcement required');
        if(self::rows($db,"SELECT 1 FROM temp.sqlite_master LIMIT 1"))throw new \RuntimeException('TEMP objects refused');
        $file=__DIR__.'/schema-v6.json';$raw=file_get_contents($file,false,null,0,65537);
        if(!is_string($raw)||strlen($raw)>65536||hash('sha256',$raw)!==self::MANIFEST_SHA256)throw new \RuntimeException('Pinned exact-v6 companion required');
        $m=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        $inventory=self::rows($db,"SELECT type,CASE WHEN length(CAST(name AS BLOB))<=128 THEN name ELSE NULL END,CASE WHEN length(CAST(tbl_name AS BLOB))<=128 THEN tbl_name ELSE NULL END,CASE WHEN length(CAST(sql AS BLOB))<=16384 THEN sql ELSE NULL END FROM main.sqlite_master WHERE (tbl_name='products' AND type IN ('table','index')) OR name LIKE 'image_%' OR tbl_name LIKE 'image_%' OR name LIKE 'ledger_%' OR tbl_name LIKE 'ledger_%' OR type='trigger' ORDER BY type,name LIMIT 129");
        if($inventory!==$m['inventory']||self::rows($db,'SELECT singleton,version,contract FROM main.image_handoff_version LIMIT 2')!==[[1,6,'renewal-ledger-v6']])throw new \RuntimeException('Exact renewal-v6 schema/contract required');
    }
    public static function assertSchema(\PDO $db):void{self::gate($db);}
    /** No transaction claim: publication caller must already own its BEGIN IMMEDIATE reservation. */
    public function verify(\PDO $db,string $mode,int $now,?string $currentReceipt,?array $record=null,?array $intakePins=null):array
    {
        if(!in_array($mode,['acquisition_before','acquisition_after','publication','reconciliation'],true)||$now<0)throw new \RuntimeException('Explicit operation phase/time required');
        self::gate($db);$b=$this->binding;
        if($currentReceipt!==null&&strlen($currentReceipt)>16384)throw new \RuntimeException('Receipt bound');
        if(($currentReceipt===null?null:hash('sha256',$currentReceipt))!==$b['receipt_preimage_sha256'])throw new \RuntimeException('Receipt preimage changed');
        // All potentially large fields are projected bounded in this single snapshot statement.
        $cols=['a.product_id','a.revision','a.fence',self::text('a','attempt_id',32),self::text('a','owner_token',32),self::text('a','snapshot_sha256',64),self::text('a','operation_key',64),self::text('a','intent',16),self::text('a','source_url',2048),self::text('a','source_selector',128),self::text('a','receipt_preimage_sha256',64),self::text('a','phase',32),self::text('a','effect_state',16),'c.bytes',self::text('c','state',16),self::text('c','observation_json',2048),'d.revision','d.deleted','d.acknowledged_revision','p.is_active','p.show_on_website',self::text('p','image_url',2048),self::text('p','images',131072),self::text('h','snapshot_json',1048576),self::text('h','snapshot_sha256',64),self::text('s','snapshot_sha256',64),self::text('q','proof_json',8192),self::text('q','proof_sha256',64),"CASE WHEN typeof(p.image_url) IN ('text','null') AND COALESCE(length(CAST(p.image_url AS BLOB)),0)<=2048 AND typeof(p.images) IN ('text','null') AND COALESCE(length(CAST(p.images AS BLOB)),0)<=131072 THEN 1 ELSE 0 END",'q.attempt_id',"CASE WHEN typeof(a.receipt_preimage_sha256)='null' OR (typeof(a.receipt_preimage_sha256)='text' AND length(CAST(a.receipt_preimage_sha256 AS BLOB))=64) THEN 1 ELSE 0 END"];
        $sql='SELECT '.implode(',',$cols)." FROM main.ledger_attempts a JOIN main.ledger_capacity c ON c.attempt_id=a.attempt_id JOIN main.image_product_dirty d ON d.product_id=a.product_id JOIN main.products p ON p.id=a.product_id JOIN main.image_snapshot_handoff h ON h.product_id=a.product_id AND h.revision=a.revision JOIN main.ledger_snapshots s ON s.product_id=a.product_id AND s.revision=a.revision LEFT JOIN main.ledger_acquisitions q ON q.attempt_id=a.attempt_id WHERE a.attempt_id=? LIMIT 2";
        $rows=self::rows($db,$sql,[$b['attempt_id']]);if(count($rows)!==1)throw new \RuntimeException('One bound operation/capacity required');
        [$id,$rev,$fence,$attempt,$owner,$sha,$op,$intent,$url,$selector,$preimage,$phase,$effect,$bytes,$capacity,$observation,$currentRev,$deleted,$ack,$active,$visible,$primary,$gallery,$snapshot,$snapshotSha,$mappedSha,$sourceRaw,$sourceSha,$fieldsValid,$sourceAttempt,$preimageValid]=$rows[0];
        if([$id,$rev,$fence,$attempt,$owner,$sha,$op,$intent,$url,$selector,$preimage]!==[$b['product_id'],$b['revision'],$b['fence'],$b['attempt_id'],$b['owner_token'],$b['snapshot_sha256'],$b['operation_key'],$b['intent'],$b['source_url'],self::canonical($b['source_selector']),$b['receipt_preimage_sha256']])throw new \RuntimeException('Stored immutable operation differs');
        if($preimageValid!==1||$effect!=='unresolved'||($mode==='reconciliation'?!in_array($phase,['release_intent','execution_unknown'],true):($mode==='publication'?$phase!=='release_intent':$phase!=='registered'))||($mode==='reconciliation'?!in_array($capacity,['reserved','running','unknown'],true):($mode==='publication'?!in_array($capacity,['reserved','running'],true):$capacity!=='reserved'))||!is_int($bytes)||$bytes<67108864)throw new \RuntimeException('Operation/capacity phase not executable');
        if([$currentRev,$deleted,$ack,$active,$visible,$fieldsValid]!==[$rev,0,$rev,1,1,1])throw new \RuntimeException('Current visible acknowledged revision differs');
        $catalog=['id'=>(string)$id,'is_active'=>1,'show_on_website'=>1,'image_url'=>$primary,'images'=>$gallery];
        if(ImageSourceSelection::selected($catalog,['source_selector'=>$b['source_selector']])!==$url)throw new \RuntimeException('Selected source differs');
        $payload=['version'=>2,'product_id'=>$id,'revision'=>$rev,'deleted'=>false,'image_url'=>$primary,'images'=>$gallery,'is_active'=>1,'show_on_website'=>1];
        if($snapshot!==self::canonical($payload)||hash('sha256',$snapshot)!==$sha||$snapshotSha!==$sha||$mappedSha!==$sha)throw new \RuntimeException('Exact lifecycle/source proof differs');
        if(self::rows($db,"SELECT 1 FROM main.image_handoff_quarantine WHERE product_id=? AND revision=? AND source_scope IN ('*',?) LIMIT 1",[$id,$rev,self::canonical($b['source_selector'])]))throw new \RuntimeException('Source quarantined');
        if(self::rows($db,"SELECT attempt_id FROM main.ledger_capacity WHERE state<>'released' LIMIT 2")!==[[$attempt]])throw new \RuntimeException('Single active reservation required');
        if(self::rows($db,"SELECT 1 FROM main.ledger_attempts WHERE product_id=? AND attempt_id<>? AND (effect_state<>'settled' OR phase<>'terminal_recorded') LIMIT 1",[$id,$attempt]))throw new \RuntimeException('Unresolved product effect remains');
        if(!is_string($observation))throw new \RuntimeException('Quota evidence absent');$quota=json_decode($observation,true,8,JSON_THROW_ON_ERROR);self::keys($quota,'observed_at quota_limit quota_used private_free public_free tmp_free');if($observation!==self::canonical($quota))throw new \RuntimeException('Canonical quota evidence required');foreach($quota as$v)if(!is_int($v)||$v<0)throw new \RuntimeException('Strict quota bytes required');
        if($quota['observed_at']>$now||($mode!=='reconciliation'&&$now-$quota['observed_at']>300)||$quota['quota_limit']<$quota['quota_used']||$quota['quota_limit']-$quota['quota_used']<134217728||min($quota['private_free'],$quota['public_free'],$quota['tmp_free'])<134217728)throw new \RuntimeException('Fresh independent quota headroom required');
        if($mode==='acquisition_before'){
            if($sourceAttempt!==null||$record!==null||$intakePins!==null)throw new \RuntimeException('Previously acquired source cannot be fetched again');
        }else{
            if(!is_string($sourceRaw)||!self::hex($sourceSha)||hash('sha256',$sourceRaw)!==$sourceSha||$record===null)throw new \RuntimeException('Immutable acquisition/manifest required');
            $source=json_decode($sourceRaw,true,16,JSON_THROW_ON_ERROR);self::keys($source,'version attempt_id operation_key snapshot_sha256 source_selector source_url source_sha256 source_bytes mime width height verified_at valid_until intake_journal_sha256 intake_manifest_sha256');
            if($sourceRaw!==self::canonical($source)||$source['version']!==1||$source['attempt_id']!==$attempt||$source['operation_key']!==$op||$source['snapshot_sha256']!==$sha||self::canonical($source['source_selector'])!==$selector||$source['source_url']!==$url)throw new \RuntimeException('Acquisition identity differs');
            foreach(['source_bytes','width','height','verified_at','valid_until']as$k)if(!is_int($source[$k]))throw new \RuntimeException('Strict acquisition metadata required');
            if(!self::hex($source['source_sha256'])||$source['source_bytes']<1||$source['source_bytes']>2097152||$source['width']<1||$source['height']<1||$source['width']*$source['height']>4000000||!in_array($source['mime'],['image/png','image/jpeg','image/webp'],true)||$source['verified_at']<0||$source['verified_at']>$now||$source['valid_until']<=$now||$source['valid_until']-$source['verified_at']!==86400)throw new \RuntimeException('Acquisition bounds/expiry differ');
            if($intakePins===null)throw new \RuntimeException('Verified intake artifact hashes required');self::keys($intakePins,'journal_sha256 manifest_sha256');
            if(!self::hex($source['intake_journal_sha256'])||!self::hex($source['intake_manifest_sha256'])||$source['intake_journal_sha256']!==$intakePins['journal_sha256']||$source['intake_manifest_sha256']!==$intakePins['manifest_sha256'])throw new \RuntimeException('Durable intake proof differs');
            if(!is_array($record['intake']??null)||!array_key_exists('receipt_preimage_sha256',$record['intake']))throw new \RuntimeException('Explicit manifest receipt preimage required');
            $image=$record['intake']['image']??null;
            if(($record['product_id']??null)!==(string)$id||($record['source_url']??null)!==$url||ImageSourceSelection::selector($record)!==$b['source_selector']||($record['source_sha256']??null)!==$source['source_sha256']||($record['intake']['job']??null)!==$attempt||($record['intake']['intent']??null)!==$intent||($record['intake']['receipt_preimage_sha256']??null)!==$preimage||($record['intake']['catalog_snapshot_sha256']??null)!==ImageSourceIntake::snapshotHash($catalog)||!is_array($image))throw new \RuntimeException('Intake manifest binding differs');
            foreach(['bytes'=>'source_bytes','sha256'=>'source_sha256','mime'=>'mime','width'=>'width','height'=>'height']as$k=>$v)if(($image[$k]??null)!==$source[$v])throw new \RuntimeException('Intake image metadata differs');
            if(($record['verified_at']??null)!==gmdate('c',$source['verified_at'])||($record['valid_until']??null)!==gmdate('c',$source['valid_until']))throw new \RuntimeException('Intake timestamp differs');
        }
        return ['version'=>6,'attempt_id'=>$attempt,'operation_key'=>$op,'binding_sha256'=>hash('sha256',self::canonical($b)),'acquisition_sha256'=>$sourceSha,'dispatch_authorized'=>false];
    }
}
