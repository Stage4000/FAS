<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Read-only intake commit gate. Holds the producer's lock until explicitly released. */
final class ImageIntakeBinding
{
    private $lock=null;
    public function __construct(private string $state, private string $input)
    {
        if ($input !== $state.'/inputs') throw new \RuntimeException('Fixed intake input directory required');
        foreach ([$state,$input] as $path) {
            clearstatcache(true,$path);
            if (is_link($path) || !is_dir($path) || realpath($path)!==$path || (fileperms($path)&0077)!==0) throw new \RuntimeException('Private canonical intake directory required');
        }
        $path=$state.'/.intake.lock';
        if (is_link($path) || !is_file($path)) throw new \RuntimeException('Existing intake lock required');
        $h=fopen($path,'rb');
        if (!$h) throw new \RuntimeException('Intake lock open failed');
        if (!flock($h,LOCK_SH|LOCK_NB)) { fclose($h); throw new \RuntimeException('Intake publication in progress'); }
        $this->lock=$h;
    }
    private static function read(string $path,int $max):string
    {
        clearstatcache(true,$path);
        if (is_link($path) || !is_file($path) || (fileperms($path)&0077)!==0) throw new \RuntimeException('Private regular intake artifact required');
        $raw=file_get_contents($path,false,null,0,$max+1);
        if (!is_string($raw) || strlen($raw)>$max) throw new \RuntimeException('Intake artifact bound');
        return $raw;
    }
    private static function json(array $value):string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); }
    private static function timestamp($value):void
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$value)) throw new \RuntimeException('Intake event timestamp');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$value);$errors=\DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) throw new \RuntimeException('Invalid intake event timestamp');
    }
    /** Caller supplies current catalog/preimage; never opens a DB or changes state. */
    public function verify(string $job,array $record,array $product,?string $before):array
    {
        if (!is_resource($this->lock) || !preg_match('/^[a-f0-9]{32}$/D',$job)) throw new \RuntimeException('Owned intake gate required');
        $manifest=self::read($this->state.'/approved-record.json',16384);
        if ($manifest!==self::json($record)."\n") throw new \RuntimeException('Intake manifest changed/noncanonical');
        $meta=$record['intake']??null;
        $preimage=$before===null?null:hash('sha256',$before);
        if (!is_array($meta) || ($meta['version']??null)!==1 || ($meta['job']??null)!==$job
            || ($meta['catalog_snapshot_sha256']??null)!==hash('sha256',self::json($product))
            || !array_key_exists('receipt_preimage_sha256',$meta) || $meta['receipt_preimage_sha256']!==$preimage
            || !in_array($meta['intent']??null,['initial','renew'],true)
            || ($meta['intent']==='initial' ? $before!==null : $before===null)
            || ($product['id']??null)!==($record['product_id']??null)
            || ($product['image_url']??null)!==($record['source_url']??null)) throw new \RuntimeException('Intake catalog/preimage/identity binding');
        $image=$meta['image']??null;
        $types=['jpg'=>['image/jpeg',IMAGETYPE_JPEG],'png'=>['image/png',IMAGETYPE_PNG],'webp'=>['image/webp',IMAGETYPE_WEBP]];
        $ext=is_array($image)?($image['extension']??null):null;
        if (!is_string($ext) || !isset($types[$ext]) || ($image['mime']??null)!==$types[$ext][0]
            || ($image['orientation']??null)!==1 || ($image['sha256']??null)!==($record['source_sha256']??null)
            || !is_string($image['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$image['sha256'])
            || !is_int($image['bytes']??null) || $image['bytes']<1 || $image['bytes']>2097152
            || !is_int($image['width']??null) || !is_int($image['height']??null)
            || $image['width']<1 || $image['height']<1 || $image['width']>4000000 || $image['height']>4000000
            || $image['width']*$image['height']>4000000
            || ($record['local_file']??null)!=='source-'.$job.'.'.$ext) throw new \RuntimeException('Intake image metadata binding');
        $source=self::read($this->input.'/'.$record['local_file'],2097152);
        $size=@getimagesizefromstring($source);
        if (strlen($source)!==$image['bytes'] || hash('sha256',$source)!==$image['sha256'] || !$size
            || $size[0]!==$image['width'] || $size[1]!==$image['height'] || $size[2]!==$types[$ext][1]) throw new \RuntimeException('Intake original changed');
        $journal=self::read($this->state.'/intake-attempt.jsonl',262144);
        if (!str_ends_with($journal,"\n")) throw new \RuntimeException('Incomplete intake journal');
        $lines=explode("\n",substr($journal,0,-1));
        if (count($lines)!==3) throw new \RuntimeException('Intake receiving/staged/ready sequence required');
        $events=[];
        foreach ($lines as $index=>$line) {
            $event=json_decode($line,true,24,JSON_THROW_ON_ERROR);
            if (!is_array($event) || self::json($event)!==$line || ($event['state']??null)!==['receiving','staged','ready'][$index]
                || ($event['job']??null)!==$job || ($event['retry_authorized']??null)!==false) throw new \RuntimeException('Invalid intake commit sequence');
            $events[]=$event;
        }
        $context=['catalog_snapshot'=>$product,'receipt_preimage_sha256'=>$preimage,'source_url'=>$record['source_url'],'intent'=>$meta['intent']];
        if ($events[0]!==['state'=>'receiving','job'=>$job,'context'=>$context,'retry_authorized'=>false]) throw new \RuntimeException('Intake receiving context changed');
        foreach ([1,2] as $index) {
            self::timestamp($events[$index]['utc']??null);
            if (array_keys($events[$index])!==['state','job','utc','evidence','retry_authorized']) throw new \RuntimeException('Intake event schema');
        }
        if ($events[1]['evidence']!==['image'=>$image,'local_file'=>$record['local_file']]
            || $events[2]['evidence']!==['record_sha256'=>hash('sha256',self::json($record))]) throw new \RuntimeException('Intake ready evidence mismatch');
        return ['journal_sha256'=>hash('sha256',$journal),'manifest_sha256'=>hash('sha256',$manifest)];
    }
    public function release():void { if (is_resource($this->lock)) { flock($this->lock,LOCK_UN);fclose($this->lock); } $this->lock=null; }
    public function __destruct() { $this->release(); }
}
