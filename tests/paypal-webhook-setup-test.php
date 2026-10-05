<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/payments/PayPalWebhookSetup.php';
use FAS\Payments\PayPalWebhookSetup;
class SetupGateway extends PayPalWebhookSetup {
    public array $hooks=[];
    public array $calls=[];
    public bool $fail=false;
    public function __construct() {}
    protected function makeRequest($method,$path,$data=null) {
        $this->calls[]=[$method,$path,$data];
        if($this->fail)return ['success'=>false];
        if($method==='GET' && $path==='/v1/notifications/webhooks')return ['success'=>true,'data'=>['webhooks'=>array_values($this->hooks)]];
        if($method==='POST') {$hook=['id'=>'WH-TEST']+$data;$this->hooks['WH-TEST']=$hook;return ['success'=>true,'data'=>$hook];}
        $id=basename($path);
        if($method==='PATCH'){$this->hooks[$id]['event_types']=$data[0]['value'];return ['success'=>true];}
        return ['success'=>true,'data'=>$this->hooks[$id]];
    }
}
$checks=0;
function checkSetup(bool $ok,string $message):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$url='https://example.invalid/api/paypal-webhook.php';
$p=new SetupGateway();$hook=$p->ensure($url);
checkSetup($hook['url']===$url,'registered correct URL');
checkSetup(array_column($hook['event_types'],'name')===PayPalWebhookSetup::EVENTS,'only required capture events registered');
checkSetup(!in_array('CHECKOUT.ORDER.APPROVED',array_column($hook['event_types'],'name'),true),'approval does not signal payment');
$same=$p->ensure($url);
checkSetup($same['id']===$hook['id'],'repeat setup reuses registration');
checkSetup(count(array_filter($p->calls,fn($c)=>$c[0]==='POST'))===1,'only one registration POST');
$p->hooks['WH-TEST']['event_types']=[['name'=>'CUSTOMER.DISPUTE.CREATED']];
$p->ensure($url);$events=array_column($p->hooks['WH-TEST']['event_types'],'name');
checkSetup(in_array('CUSTOMER.DISPUTE.CREATED',$events,true),'existing event subscriptions preserved');
checkSetup(!array_diff(PayPalWebhookSetup::EVENTS,$events),'missing capture events added');
$p->hooks['WH-TEST']['event_types']=[['name'=>'*']];$before=count($p->calls);$p->ensure($url);
checkSetup(!array_filter(array_slice($p->calls,$before),fn($c)=>$c[0]==='PATCH'),'all-event subscription not rewritten');
$p->hooks['WH-OTHER']=['id'=>'WH-OTHER','url'=>'https://another.invalid/hook','event_types'=>[]];$p->ensure($url);
checkSetup($p->hooks['WH-OTHER']['event_types']===[],'another URL left untouched');
foreach (['http://example.invalid/hook','invalid'] as $bad) {
    try{$p->ensure($bad);throw new RuntimeException('Accepted unsafe URL');}
    catch(InvalidArgumentException $e){checkSetup(true,'HTTPS URL required');}
}
$p->fail=true;
try{$p->ensure($url);throw new LogicException('Accepted provider failure');}
catch(RuntimeException $e){checkSetup(true,'provider failure stops registration');}
$p->fail=false;$p->hooks['WH-DUP']=['id'=>'WH-DUP','url'=>$url,'event_types'=>[]];
try{$p->ensure($url);throw new LogicException('Accepted duplicate subscriptions');}
catch(RuntimeException $e){checkSetup(str_contains($e->getMessage(),'Multiple webhooks'),'duplicate URL needs review');}
echo "PASS {$checks} webhook setup assertions; PayPal transport mocked.\n";
