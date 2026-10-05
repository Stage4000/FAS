<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../src/integrations/PayPalAPI.php';
class VerificationPayPal extends \FAS\Integrations\PayPalAPI {
    public array $calls=[];
    public array $response=['success'=>true,'data'=>['verification_status'=>'SUCCESS']];
    protected function makeRequest($method,$endpoint,$data=null) {
        $this->calls[]=[$method,$endpoint,$data];return $this->response;
    }
}
$config=['paypal'=>['client_id'=>'fixture','client_secret'=>'fixture','mode'=>'sandbox','currency'=>'USD','webhook_id'=>'WH-FIXTURE']];
$headers=['PayPal-Transmission-Id'=>'fixture','PayPal-Transmission-Time'=>'2026-10-05T00:00:00Z',
    'PayPal-Transmission-Sig'=>'fixture-signature','PayPal-Cert-Url'=>'https://api.paypal.com/cert/fixture','PayPal-Auth-Algo'=>'SHA256withRSA'];
$body=json_encode(['id'=>'WH-EVENT','event_type'=>'PAYMENT.CAPTURE.COMPLETED']);
function webhookCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$p=new VerificationPayPal($config);
webhookCheck($p->verifyWebhookSignature($headers,$body),'Provider-confirmed signature accepted');
webhookCheck($p->calls[0][0]==='POST' && $p->calls[0][1]==='/v1/notifications/verify-webhook-signature','Correct verification endpoint');
webhookCheck($p->calls[0][2]['webhook_id']==='WH-FIXTURE' && $p->calls[0][2]['webhook_event']['id']==='WH-EVENT','Webhook bound to app and event');
foreach ([['success'=>true,'data'=>['verification_status'=>'FAILURE']],['success'=>false],['data'=>['verification_status'=>'SUCCESS']]] as $response) {
    $p->response=$response;webhookCheck(!$p->verifyWebhookSignature($headers,$body),'Reject unverified response');
}
$count=count($p->calls);
webhookCheck(!$p->verifyWebhookSignature([],$body),'Reject missing headers');
webhookCheck(!$p->verifyWebhookSignature($headers,'broken JSON'),'Reject malformed body');
webhookCheck(count($p->calls)===$count,'Invalid input does not request provider verification');
$config['paypal']['webhook_id']='';$missing=new VerificationPayPal($config);
webhookCheck(!$missing->verifyWebhookSignature($headers,$body)&&!$missing->calls,'Missing app webhook ID fails closed');
echo "PASS 10 webhook signature assertions; provider verification mocked.\n";
