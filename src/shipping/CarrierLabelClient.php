<?php
declare(strict_types=1);
namespace FAS\Shipping;
require_once __DIR__.'/CarrierRates.php';
require_once __DIR__.'/CarrierLabelHttp.php';
require_once __DIR__.'/CarrierLabelResponses.php';

/** One carrier label POST per call. The durable operation ledger must claim it first. */
final class CarrierLabelClient
{
    private string $name;
    private array $config;
    private CarrierRates $oauth;
    private CarrierLabelHttp $http;

    public function __construct(string $name,array $config,ShippingCache $cache,
        CarrierHttp $authHttp,CarrierLabelHttp $labelHttp)
    {
        if (!in_array($name,['usps','ups'],true)) throw new \InvalidArgumentException('Unknown direct carrier.');
        $this->name=$name;
        $this->config=$config;
        $this->oauth=new CarrierRates($name,$config,$cache,$authHttp);
        $this->http=$labelHttp;
    }

    /** Preflight authorization before claiming the one permitted purchase submission. */
    public function authorize(): array
    {
        return $this->authorizeFor('label_purchasing_enabled');
    }

    public function authorizeCancellation(): array
    {
        return $this->authorizeFor('label_cancellation_enabled');
    }

    private function authorizeFor(string $switch): array
    {
        if (($this->config['enabled'] ?? false)!==true
            || ($this->config[$switch] ?? false)!==true
            || !in_array($this->config['environment'] ?? '',['sandbox','production'],true)
            || (($this->config['environment'] ?? '')==='production'
                && ($this->config['production_verified'] ?? false)!==true)) {
            throw new \RuntimeException('Carrier label action is disabled.');
        }
        $access=$this->oauth->accessToken();
        $result=['access_token'=>$access];
        if ($this->name==='usps') {
            $result['payment_token']=$this->paymentToken($access);
        }
        return $result;
    }

    /** The caller must durably claim this exact cancellation before this one DELETE. */
    public function cancel(string $tracking,array $authorization): array
    {
        if (($this->name==='usps' && !preg_match('/\A[0-9]{20,34}\z/D',$tracking))
            || ($this->name==='ups' && !preg_match('/\A1Z[A-Z0-9]{16}\z/D',$tracking))) {
            throw new \InvalidArgumentException('Invalid carrier shipment reference.');
        }
        $access=self::token($authorization['access_token'] ?? null);
        $headers=['Authorization: Bearer '.$access,'Accept: application/json'];
        if ($this->name==='usps') {
            $headers[]='X-Payment-Authorization-Token: '.self::token($authorization['payment_token'] ?? null);
            $path='/labels/v3/label/'.$tracking;
        } else {
            $headers[]='transId: '.bin2hex(random_bytes(16));
            $headers[]='transactionSrc: FlipAndStrip';
            $path='/api/shipments/v2409/void/cancel/'.$tracking;
        }
        $response=$this->http->delete($this->oauth->baseUrl().$path,$headers);
        if ($response['status']!==200
            || !str_starts_with(strtolower($response['content_type']),'application/json')) {
            throw new \RuntimeException('Carrier cancellation outcome needs reconciliation.');
        }
        $data=json_decode($response['body'],true,32);
        if (!is_array($data)) throw new \RuntimeException('Carrier cancellation outcome needs reconciliation.');
        return CarrierLabelResponses::cancellation($this->name,$tracking,$data);
    }

    /** Caller must persist markSubmitted before invoking this method; never retry it. */
    public function purchase(array $payload,string $carrierReference,int $expectedPackages,array $authorization): array
    {
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$carrierReference)
            || $expectedPackages<1 || $expectedPackages>10
            || ($this->name==='usps' && $expectedPackages!==1)
            || !isset($payload[$this->name==='ups'?'ShipmentRequest':'packageDescription'])) {
            throw new \InvalidArgumentException('Invalid carrier label operation.');
        }
        $access=self::token($authorization['access_token'] ?? null);
        $headers=['Authorization: Bearer '.$access,'Content-Type: application/json'];
        if ($this->name==='usps') {
            $headers[]='X-Payment-Authorization-Token: '.self::token($authorization['payment_token'] ?? null);
            $headers[]='X-Idempotency-Key: '.$carrierReference;
            $path='/labels/v3/label';
        } else {
            $headers[]='transId: '.str_replace('-','',$carrierReference);
            $headers[]='transactionSrc: FlipAndStrip';
            $path='/api/shipments/v2409/ship';
        }
        $response=$this->http->post($this->oauth->baseUrl().$path,$headers,
            json_encode($payload,JSON_THROW_ON_ERROR));
        if ($response['status']<200 || $response['status']>=300) {
            // The carrier may have created a label despite a lost or malformed reply.
            // The caller keeps the submitted operation for reconciliation.
            throw new \RuntimeException('Carrier label outcome needs reconciliation.');
        }
        if ($this->name==='usps') {
            return CarrierLabelResponses::usps($response['content_type'],$response['body']);
        }
        if (!str_starts_with(strtolower($response['content_type']),'application/json')) {
            throw new \RuntimeException('UPS label outcome needs reconciliation.');
        }
        $data=json_decode($response['body'],true,64);
        if (!is_array($data)) throw new \RuntimeException('UPS label outcome needs reconciliation.');
        return CarrierLabelResponses::ups($data,$expectedPackages);
    }

    private function paymentToken(string $access): string
    {
        $fields=[];
        foreach (['crid','mid','manifest_mid','eps_account_number'] as $key) {
            $value=$this->config[$key] ?? null;
            if (!is_string($value) || !preg_match('/\A[0-9]{6,20}\z/D',$value)) {
                throw new \RuntimeException('Verified USPS payment account is required.');
            }
            $fields[$key]=$value;
        }
        $role=['CRID'=>$fields['crid'],'MID'=>$fields['mid'],
            'manifestMID'=>$fields['manifest_mid'],'accountType'=>'EPS',
            'accountNumber'=>$fields['eps_account_number']];
        $response=$this->http->post($this->oauth->baseUrl().'/payments/v3/payment-authorization',
            ['Authorization: Bearer '.$access,'Content-Type: application/json'],
            json_encode(['roles'=>[$role+['roleName'=>'PAYER'],
                $role+['roleName'=>'LABEL_OWNER']]],JSON_THROW_ON_ERROR),1048576);
        if ($response['status']<200 || $response['status']>=300
            || !str_starts_with(strtolower($response['content_type']),'application/json')) {
            throw new \RuntimeException('USPS payment authorization unavailable.');
        }
        $data=json_decode($response['body'],true,32);
        if (!is_array($data)) throw new \RuntimeException('USPS payment authorization unavailable.');
        return self::token($data['paymentAuthorizationToken'] ?? null);
    }

    private static function token($value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9._~+\/=-]{1,16000}\z/D',$value)) {
            throw new \RuntimeException('Carrier authorization unavailable.');
        }
        return $value;
    }
}
