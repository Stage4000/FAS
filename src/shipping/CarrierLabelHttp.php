<?php
declare(strict_types=1);
namespace FAS\Shipping;

/** Bounded raw response transport for label and payment-authorization calls. */
final class CarrierLabelHttp
{
    private float $deadline;
    private $transport;

    /** Injectable transport is only for test code; never loaded from a request or config file. */
    public function __construct(float $deadline,?callable $transport=null)
    {
        $this->deadline=$deadline;
        $this->transport=$transport;
    }

    public function post(string $url,array $headers,string $body,int $responseLimit=16777216): array
    {
        return $this->request('POST',$url,$headers,$body,$responseLimit);
    }

    /** Only carrier cancellation paths with validated shipment identifiers are accepted. */
    public function delete(string $url,array $headers): array
    {
        return $this->request('DELETE',$url,$headers,'',1048576);
    }

    private function request(string $method,string $url,array $headers,string $body,int $responseLimit): array
    {
        $parts=parse_url($url);
        $paths=[
            'apis.usps.com'=>['/payments/v3/payment-authorization','/labels/v3/label'],
            'apis-tem.usps.com'=>['/payments/v3/payment-authorization','/labels/v3/label'],
            'api.usps.com'=>['/payments/v3/payment-authorization','/labels/v3/label'],
            'api-cat.usps.com'=>['/payments/v3/payment-authorization','/labels/v3/label'],
            'onlinetools.ups.com'=>['/api/shipments/v2409/ship'],
            'wwwcie.ups.com'=>['/api/shipments/v2409/ship'],
        ];
        $host=$parts['host'] ?? '';
        $path=$parts['path'] ?? '';
        $allowed=$method==='POST' ? (isset($paths[$host]) && in_array($path,$paths[$host],true))
            : ($method==='DELETE' && ((in_array($host,['apis.usps.com','apis-tem.usps.com','api.usps.com','api-cat.usps.com'],true)
                    && preg_match('~\A/labels/v3/label/[0-9]{20,34}\z~D',$path))
                || (in_array($host,['onlinetools.ups.com','wwwcie.ups.com'],true)
                    && preg_match('~\A/api/shipments/v2409/void/cancel/1Z[A-Z0-9]{16}\z~D',$path))));
        if (($parts['scheme'] ?? '')!=='https' || !$allowed
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('Carrier label endpoint rejected.');
        }
        if (strlen($body)>32768 || $responseLimit<1 || $responseLimit>16777216
            || count($headers)>12) throw new \InvalidArgumentException('Invalid carrier label request bounds.');
        foreach ($headers as $header) {
            if (!is_string($header) || strlen($header)>16384
                || preg_match('/[\x00-\x1f\x7f]/',$header)
                || !preg_match('/\A[A-Za-z0-9-]+: [^\r\n]+\z/D',$header)) {
                throw new \InvalidArgumentException('Invalid carrier label header.');
            }
        }
        $timeout=min(20000,(int)(($this->deadline-microtime(true))*1000));
        if ($timeout<100) throw new \RuntimeException('Carrier label request budget exhausted.');
        if ($this->transport) {
            $result=($this->transport)($url,$headers,$body,$timeout,$responseLimit,$method);
        } else {
            $response=''; $retryAfter=0;
            $handle=curl_init($url);
            $options=[
                CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$timeout),CURLOPT_TIMEOUT_MS=>$timeout,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function($ch,$data) use (&$response,$responseLimit) {
                    if (strlen($response)+strlen($data)>$responseLimit) return 0;
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
            if ($method==='POST') {
                $options[CURLOPT_POST]=true;
                $options[CURLOPT_POSTFIELDS]=$body;
            } else {
                $options[CURLOPT_CUSTOMREQUEST]='DELETE';
            }
            curl_setopt_array($handle,$options);
            $ok=curl_exec($handle);
            $result=['status'=>(int)curl_getinfo($handle,CURLINFO_HTTP_CODE),
                'content_type'=>(string)curl_getinfo($handle,CURLINFO_CONTENT_TYPE),
                'body'=>$response,'retry_after'=>$retryAfter];
            curl_close($handle);
            if ($ok===false) throw new \RuntimeException('Carrier label transport unavailable.');
        }
        if (!is_array($result) || !is_int($result['status'] ?? null)
            || $result['status']<100 || $result['status']>599
            || !is_string($result['content_type'] ?? null)
            || strlen($result['content_type'])>200
            || !is_string($result['body'] ?? null)
            || strlen($result['body'])>$responseLimit) {
            throw new \RuntimeException('Carrier label response bounds exceeded.');
        }
        return $result;
    }
}
