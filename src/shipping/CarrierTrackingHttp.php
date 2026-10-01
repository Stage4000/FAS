<?php
declare(strict_types=1);
namespace FAS\Shipping;

/** Bounded HTTPS transport limited to the two direct tracking endpoints. */
final class CarrierTrackingHttp
{
    private float $deadline;
    private $transport;

    public function __construct(float $deadline,?callable $transport=null)
    {
        $this->deadline=$deadline;
        $this->transport=$transport;
    }

    public function request(string $method,string $url,array $headers,string $body=''): array
    {
        $parts=parse_url($url);
        $host=$parts['host'] ?? '';
        $path=$parts['path'] ?? '';
        $usps=in_array($host,['apis.usps.com','apis-tem.usps.com','api.usps.com','api-cat.usps.com'],true)
            && $method==='POST' && $path==='/tracking/v3r2/tracking';
        $ups=in_array($host,['onlinetools.ups.com','wwwcie.ups.com'],true)
            && $method==='GET'
            && preg_match('~\A/api/track/v1/details/1Z[A-Z0-9]{16}\z~D',$path)===1;
        if (($parts['scheme'] ?? '')!=='https' || (!$usps && !$ups)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])
            || strlen($body)>1024 || ($ups && $body!=='') || count($headers)>8) {
            throw new \RuntimeException('Carrier tracking endpoint rejected.');
        }
        foreach ($headers as $header) {
            if (!is_string($header) || strlen($header)>16384
                || preg_match('/[\x00-\x1f\x7f]/',$header)
                || !preg_match('/\A[A-Za-z0-9-]+: [^\r\n]+\z/D',$header)) {
                throw new \InvalidArgumentException('Invalid carrier tracking header.');
            }
        }
        $timeout=min(10000,(int)(($this->deadline-microtime(true))*1000));
        if ($timeout<100) throw new \RuntimeException('Carrier tracking request budget exhausted.');
        if ($this->transport) {
            $result=($this->transport)($method,$url,$headers,$body,$timeout);
        } else {
            $response=''; $retryAfter=0;
            $handle=curl_init($url);
            $options=[
                CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$timeout),CURLOPT_TIMEOUT_MS=>$timeout,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function($ch,$data) use (&$response) {
                    if (strlen($response)+strlen($data)>1048576) return 0;
                    $response.=$data; return strlen($data);
                },
                CURLOPT_HEADERFUNCTION=>static function($ch,$line) use (&$retryAfter) {
                    if (stripos($line,'Retry-After:')===0) {
                        $value=trim(substr($line,12));
                        $retryAfter=ctype_digit($value)?(int)$value:max(0,(strtotime($value) ?: time())-time());
                    }
                    return strlen($line);
                },
            ];
            if ($method==='POST') { $options[CURLOPT_POST]=true; $options[CURLOPT_POSTFIELDS]=$body; }
            curl_setopt_array($handle,$options);
            $ok=curl_exec($handle);
            $result=['status'=>(int)curl_getinfo($handle,CURLINFO_HTTP_CODE),
                'content_type'=>(string)curl_getinfo($handle,CURLINFO_CONTENT_TYPE),
                'body'=>$response,'retry_after'=>$retryAfter];
            curl_close($handle);
            if ($ok===false) throw new \RuntimeException('Carrier tracking transport unavailable.');
        }
        if (!is_array($result) || !is_int($result['status'] ?? null)
            || $result['status']<100 || $result['status']>599
            || !is_string($result['content_type'] ?? null) || strlen($result['content_type'])>200
            || !is_string($result['body'] ?? null) || strlen($result['body'])>1048576
            || !is_int($result['retry_after'] ?? null)) {
            throw new \RuntimeException('Carrier tracking response bounds exceeded.');
        }
        return $result;
    }
}
