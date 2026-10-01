<?php
declare(strict_types=1);
namespace FAS\Shipping;

final class CarrierHttp
{
    private $transport;
    private float $deadline;

    /** Injectable transport is code-only for tests; never loaded from request/config data. */
    public function __construct(float $deadline, ?callable $transport = null)
    {
        $this->deadline=$deadline; $this->transport=$transport;
    }

    public function post(string $url, array $headers, string $body): array
    {
        $parts = parse_url($url);
        $hosts = ['apis.usps.com','apis-tem.usps.com','api.usps.com','api-cat.usps.com',
            'onlinetools.ups.com','wwwcie.ups.com'];
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '',$hosts,true)
            || isset($parts['user']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('Carrier endpoint rejected.');
        }
        $timeout = min(6000, (int)(($this->deadline-microtime(true))*1000));
        if ($timeout<100) throw new \RuntimeException('Carrier request budget exhausted.');
        if ($this->transport) return ($this->transport)($url,$headers,$body,$timeout);
        $response=''; $retryAfter=0;
        $handle=curl_init($url);
        curl_setopt_array($handle,[
            CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json'],$headers),
            CURLOPT_CONNECTTIMEOUT_MS=>min(2000,$timeout), CURLOPT_TIMEOUT_MS=>$timeout,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
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
        ]);
        $ok=curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($ok===false) throw new \RuntimeException('Carrier transport unavailable.');
        // Never include response bodies, credentials or addresses in exceptions/logs.
        $data=json_decode($response,true,64);
        return ['status'=>$status,'data'=>is_array($data)?$data:[], 'retry_after'=>$retryAfter];
    }
}
