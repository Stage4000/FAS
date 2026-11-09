<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Transport reports headers once, then bounded chunks. It must never follow redirects. */
interface ImageIntakeTransport
{
    public function get(string $url, callable $headers, callable $chunk): void;
}

/** One private job workspace; persistent attempts are never retried implicitly. */
interface ImageIntakeStore
{
    public function receipt(string $productId, string $source): ?string;
    public function claim(string $job, array $context): void;
    public function event(string $state, array $evidence = []): void;
    public function original(string $job, string $extension, string $bytes): string;
    public function ready(array $record): void;
    public function release(): void;
}

final class ImageSourceIntake
{
    public const MAX_BYTES = 2097152;
    public const MAX_PIXELS = 4000000;
    public const TIMEOUT_MS = 10000;
    private \Closure $wall;
    private \Closure $mono;

    public function __construct(private \PDO $db, private ImageIntakeStore $store,
        private ImageIntakeTransport $transport, private bool $enabled = false,
        ?\Closure $wall = null, ?\Closure $mono = null)
    {
        $this->wall = $wall ?? static fn(): int => time();
        $this->mono = $mono ?? static fn(): int => hrtime(true);
    }

    public static function source(string $url): void
    {
        $p = parse_url($url);
        if (strlen($url) > 2048 || preg_match('~[\x00-\x20\x7f\\\\]~', $url)
            || str_ends_with($url, ',') || !is_array($p) || ($p['scheme'] ?? '') !== 'https'
            || ($p['host'] ?? '') !== 'i.ebayimg.com' || !str_starts_with($p['path'] ?? '', '/')
            || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['fragment'])) {
            throw new \RuntimeException('Exact HTTPS i.ebayimg.com source required');
        }
    }

    public static function snapshot(\PDO $db, string $id): array
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id) || $db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || $db->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION
            || $db->getAttribute(\PDO::ATTR_STRINGIFY_FETCHES) || $db->inTransaction()) throw new \RuntimeException('Owned SQLite read connection required');
        $sql=<<<'SQL'
SELECT id,is_active,show_on_website,
 CASE WHEN typeof(image_url) IN ('text','null') AND COALESCE(length(CAST(image_url AS BLOB)),0)<=2048 THEN image_url ELSE NULL END AS image_url,
 CASE WHEN typeof(images) IN ('text','null') AND COALESCE(length(CAST(images AS BLOB)),0)<=131072 THEN images ELSE NULL END AS images,
 CASE WHEN typeof(image_url) IN ('text','null') AND COALESCE(length(CAST(image_url AS BLOB)),0)<=2048
       AND typeof(images) IN ('text','null') AND COALESCE(length(CAST(images AS BLOB)),0)<=131072 THEN 1 ELSE 0 END AS snapshot_valid
FROM products WHERE id=?
SQL;
        $q=$db->prepare($sql); $q->execute([$id]); $p=$q->fetch(\PDO::FETCH_ASSOC);
        if (!$p || (int)$p['snapshot_valid'] !== 1) throw new \RuntimeException('Catalog snapshot bound');
        unset($p['snapshot_valid']);
        if (!$p || (int)$p['is_active'] !== 1 || (int)$p['show_on_website'] !== 1) throw new \RuntimeException('Visible catalog product required');
        $p['id'] = (string)$p['id']; $p['is_active'] = (int)$p['is_active']; $p['show_on_website'] = (int)$p['show_on_website'];
        return $p;
    }

    public static function snapshotHash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function binding(array $job): void
    {
        $p = self::snapshot($this->db, $job['product_id']);
        if ($p !== $job['catalog_snapshot'] || $p['image_url'] !== $job['source_url']) throw new \RuntimeException('Catalog/source changed');
        $raw = $this->store->receipt($job['product_id'], $job['source_url']);
        if ($raw !== null) {
            if (strlen($raw) > 16384) throw new \RuntimeException('Receipt bound');
            $r = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($r) || ($r['excluded'] ?? false) === true || ($r['product_id'] ?? null) !== $job['product_id']
                || ($r['source_url'] ?? null) !== $job['source_url']) throw new \RuntimeException('Excluded or unbound receipt');
        }
        if (($raw === null ? null : hash('sha256', $raw)) !== $job['receipt_preimage_sha256']) throw new \RuntimeException('Receipt/exclusion changed');
        if (($job['intent'] === 'initial' && $raw !== null) || ($job['intent'] === 'renew' && $raw === null)) throw new \RuntimeException('Explicit receipt intent required');
    }

    /** Metadata inspection follows the existing builder's reject-rotated-JPEG rule. */
    public static function inspect(string $bytes, string $mime): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) throw new \RuntimeException('Image byte bound');
        $size = @getimagesizefromstring($bytes);
        $types = [IMAGETYPE_JPEG => ['image/jpeg','jpg'], IMAGETYPE_PNG => ['image/png','png'], IMAGETYPE_WEBP => ['image/webp','webp']];
        if (!$size || !isset($types[$size[2]]) || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS
            || $mime !== $types[$size[2]][0] || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $mime) throw new \RuntimeException('Image MIME/dimension bound');
        $orientation = 1;
        if ($size[2] === IMAGETYPE_JPEG) {
            if (!function_exists('exif_read_data')) throw new \RuntimeException('JPEG orientation verification unavailable');
            // Determine whether EXIF exists without treating malformed EXIF as "no orientation".
            $offset=2; $hasExif=false; $segments=0;
            while($offset<strlen($bytes)) {
                if(++$segments>4096 || ord($bytes[$offset++])!==255)throw new \RuntimeException('JPEG marker structure');
                while($offset<strlen($bytes)&&ord($bytes[$offset])===255)$offset++;
                if($offset>=strlen($bytes))throw new \RuntimeException('JPEG truncated marker');
                $marker=ord($bytes[$offset++]); if($marker===218||$marker===217)break;
                if($marker===1||($marker>=208&&$marker<=215))continue;
                if($offset+2>strlen($bytes))throw new \RuntimeException('JPEG truncated segment');
                $length=unpack('n',substr($bytes,$offset,2))[1];
                if($length<2||$offset+$length>strlen($bytes))throw new \RuntimeException('JPEG segment length');
                if($marker===225){if($hasExif||substr($bytes,$offset+2,6)!=="Exif\0\0")throw new \RuntimeException('Ambiguous JPEG metadata');$hasExif=true;}
                $offset+=$length;
            }
            if($hasExif){
                $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
                try { fwrite($stream, $bytes); rewind($stream); $exif = @exif_read_data($stream, 'IFD0', true, false); }
                finally { fclose($stream); }
                if (!is_array($exif) || !isset($exif['IFD0']['Orientation'])) throw new \RuntimeException('JPEG metadata unreadable');
                $orientation = $exif['IFD0']['Orientation'];
                if (!is_int($orientation)) throw new \RuntimeException('JPEG orientation type invalid');
            }
            if ($orientation !== 1) throw new \RuntimeException('Rotated JPEG keeps original');
        } else {
            // Reject ancillary orientation and animation instead of guessing or rewriting either.
            $offset = $size[2] === IMAGETYPE_PNG ? 8 : 12; $end = strlen($bytes);
            if ($size[2] === IMAGETYPE_WEBP && unpack('V', substr($bytes, 4, 4))[1] + 8 !== $end) throw new \RuntimeException('WebP container length');
            $finished = false;
            while ($offset < $end) {
                if ($offset + 8 > $end) throw new \RuntimeException('Image chunk truncated');
                $png = $size[2] === IMAGETYPE_PNG;
                $length = unpack($png ? 'N' : 'V', substr($bytes, $offset + ($png ? 0 : 4), 4))[1];
                $kind = substr($bytes, $offset + ($png ? 4 : 0), 4);
                if (in_array($kind, ['eXIf','acTL','EXIF','ANIM','ANMF'], true)) throw new \RuntimeException('Orientation/animation metadata unsupported');
                $next = $offset + 8 + $length + ($png ? 4 : $length % 2);
                if ($next > $end || $next <= $offset) throw new \RuntimeException('Image chunk size');
                $offset = $next;
                if ($png && $kind === 'IEND') { if ($length !== 0 || $offset !== $end) throw new \RuntimeException('PNG trailing bytes'); $finished = true; break; }
            }
            if ($size[2] === IMAGETYPE_PNG && !$finished) throw new \RuntimeException('PNG incomplete');
        }
        $warning=false;
        set_error_handler(static function()use(&$warning):bool{$warning=true;return true;});
        try{$image=imagecreatefromstring($bytes);}finally{restore_error_handler();}
        if ($warning || !$image || imagesx($image) !== $size[0] || imagesy($image) !== $size[1]) throw new \RuntimeException('Image decode failed');
        // No re-encoding. The original bytes are preserved exactly for the existing pilot.
        unset($image);
        return ['bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'mime'=>$mime,'width'=>$size[0],'height'=>$size[1],
            'orientation'=>$orientation,'extension'=>$types[$size[2]][1]];
    }

    public function acquire(array $job): array
    {
        if (!$this->enabled || PHP_SAPI !== 'cli') throw new \RuntimeException('Source intake disabled or non-CLI');
        foreach (['job','product_id','source_url','reference','intent'] as $key) if (!is_string($job[$key] ?? null)) throw new \RuntimeException('Fixed explicit job required');
        if (!preg_match('/^[a-f0-9]{32}$/D', $job['job']) || trim($job['reference']) === '' || strlen($job['reference']) > 2048
            || !in_array($job['intent'], ['initial','renew'], true) || !is_array($job['catalog_snapshot'] ?? null)
            || !array_key_exists('receipt_preimage_sha256', $job) || ($job['receipt_preimage_sha256'] !== null
                && (!is_string($job['receipt_preimage_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $job['receipt_preimage_sha256'])))
            || ($job['optimization_authorized'] ?? null) !== true) throw new \RuntimeException('Explicit authorization/preimage required');
        self::source($job['source_url']); $this->binding($job);
        $claimed = false;
        try {
            $this->store->claim($job['job'], ['catalog_snapshot'=>$job['catalog_snapshot'],'receipt_preimage_sha256'=>$job['receipt_preimage_sha256'],'source_url'=>$job['source_url'],'intent'=>$job['intent']]);
            $claimed = true; $this->binding($job);
            $start = ($this->mono)(); $body = ''; $meta = null;
            $deadline = function() use ($start): void { if (($this->mono)() - $start > self::TIMEOUT_MS * 1000000) throw new \RuntimeException('Source deadline'); };
            $this->transport->get($job['source_url'], function(int $status, array $headers) use (&$meta, $deadline): void {
                $deadline(); if ($meta !== null || $status !== 200) throw new \RuntimeException('One HTTP200 required; redirects refused');
                $h=[]; $total=0;
                foreach ($headers as $k=>$v) { if (!is_string($k) || !is_string($v) || isset($h[strtolower($k)])) throw new \RuntimeException('Ambiguous response headers'); $total+=strlen($k)+strlen($v); $h[strtolower($k)]=trim($v); }
                if ($total>16384 || isset($h['location']) || (isset($h['content-encoding']) && strtolower($h['content-encoding'])!=='identity')) throw new \RuntimeException('Redirect/encoding/header bound');
                $mime=strtolower($h['content-type'] ?? '');
                if (!in_array($mime,['image/png','image/jpeg','image/webp'],true)) throw new \RuntimeException('Image response MIME required');
                $length=$h['content-length'] ?? null;
                if ($length!==null && (!ctype_digit($length) || strlen($length)>7 || (int)$length<1 || (int)$length>self::MAX_BYTES)) throw new \RuntimeException('Response length bound');
                $meta=['mime'=>$mime,'length'=>$length===null?null:(int)$length];
            }, function(string $chunk) use (&$body,&$meta,$deadline): void {
                $deadline(); if ($meta===null || strlen($chunk)>65536 || strlen($body)+strlen($chunk)>self::MAX_BYTES) throw new \RuntimeException('Response stream byte/order bound');
                $body.=$chunk;
            });
            $deadline();
            if ($meta===null || ($meta['length']!==null && $meta['length']!==strlen($body))) throw new \RuntimeException('Incomplete response');
            $image=self::inspect($body,$meta['mime']); $deadline(); $this->binding($job);
            $verifiedAt=($this->wall)();
            $local=$this->store->original($job['job'],$image['extension'],$body);
            $this->store->event('staged',['image'=>$image,'local_file'=>$local]); $this->binding($job); $deadline();
            $now=$verifiedAt;
            $record=['product_id'=>$job['product_id'],'source_url'=>$job['source_url'],'local_file'=>$local,'source_sha256'=>$image['sha256'],
                'verified_at'=>gmdate('c',$now),'valid_until'=>gmdate('c',$now+86400),
                'provenance'=>['basis'=>'existing_catalog','reference'=>$job['reference'],'optimization_authorized'=>true,'excluded'=>false],
                'intake'=>['version'=>1,'job'=>$job['job'],'catalog_snapshot_sha256'=>self::snapshotHash($job['catalog_snapshot']),
                    'receipt_preimage_sha256'=>$job['receipt_preimage_sha256'],'image'=>$image,'intent'=>$job['intent']]];
            $deadline(); $this->store->ready($record); $deadline(); $this->store->event('ready',['record_sha256'=>hash('sha256',json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))]);
            return $record;
        } catch (\Throwable $e) {
            if ($claimed) { try { $this->store->event('failed_or_unknown',['exception'=>get_class($e),'reason'=>$e->getMessage(),'retry_authorized'=>false]); } catch (\Throwable) {} }
            throw $e;
        } finally { if ($claimed) $this->store->release(); }
    }
}

/** No executable entry point; enabling the intake never implicitly enables network. */
final class CurlImageIntakeTransport implements ImageIntakeTransport
{
    public function __construct(private bool $networkEnabled=false) {}
    public function get(string $url, callable $headers, callable $chunk): void
    {
        if (!$this->networkEnabled) throw new \RuntimeException('Network source transport disabled');
        ImageSourceIntake::source($url);
        if (!extension_loaded('curl')) throw new \RuntimeException('cURL unavailable');
        $ch=curl_init($url); if ($ch===false) throw new \RuntimeException('cURL initialization failed'); $status=0; $raw=[]; $bytes=0; $finished=false;
        try {
            if (!curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT_MS=>3000,CURLOPT_TIMEOUT_MS=>ImageSourceIntake::TIMEOUT_MS,
                CURLOPT_NOSIGNAL=>true,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',
                CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Accept: image/png, image/jpeg, image/webp','Accept-Encoding: identity'],
                CURLOPT_HTTP_CONTENT_DECODING=>false,CURLOPT_MAXFILESIZE=>ImageSourceIntake::MAX_BYTES,
                CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$status,&$raw,&$bytes,&$finished,$headers):int{
                    $bytes+=strlen($line); if($bytes>16384)throw new \RuntimeException('HTTP headers too large');
                    if(preg_match('~^HTTP/\S+ (\d{3})~',$line,$m)){if($status!==0||$finished)throw new \RuntimeException('Multiple HTTP responses refused');$status=(int)$m[1];}
                    elseif(trim($line)===''){$headers($status,$raw);$finished=true;}
                    else{$parts=explode(':',$line,2);if(count($parts)!==2||$finished)throw new \RuntimeException('Malformed response header');$key=strtolower(trim($parts[0]));if(isset($raw[$key]))throw new \RuntimeException('Duplicate response header');$raw[$key]=trim($parts[1]);}
                    return strlen($line);
                },CURLOPT_WRITEFUNCTION=>static function($handle,string $data)use($chunk):int{$chunk($data);return strlen($data);}])) throw new \RuntimeException('cURL limits configuration failed');
            if(curl_exec($ch)!==true || curl_getinfo($ch,CURLINFO_EFFECTIVE_URL)!==$url || !$finished)throw new \RuntimeException('Source transfer incomplete');
        } finally { curl_close($ch); }
    }
}
