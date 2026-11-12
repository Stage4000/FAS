<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$path=$argv[1]??dirname(__DIR__).'/admin/stale-inventory.php';
$source=file_get_contents($path);
if(preg_match_all('/^\s*\$campaignCode = (.+);$/m',$source,$matches)!==1)throw new RuntimeException('Unique actual campaign assignment required');
$assignment='$campaignCode = '.$matches[1][0].';';
$cases=[['input'=>'spring10','expected'=>'SPRING10'],['input'=>'SpringSale5','expected'=>'SPRINGSALE5'],['input'=>'sale','expected'=>'SALE'],['input'=>'SAVE10','expected'=>'SAVE10'],['input'=>'0','expected'=>'0'],['input'=>' sale-10! ','expected'=>'SALE10'],['input'=>'','expected'=>''],['missing'=>true,'expected'=>'OLDSTOCK5']];
$seed=20261112;
for($i=0;$i<128;$i++){
 $raw='';$oracle='';for($j=0;$j<24;$j++){$seed=($seed*48271)%2147483647;$c=chr(32+$seed%95);$raw.=$c;$n=ord($c);if($n>=97&&$n<=122)$n-=32;if(($n>=65&&$n<=90)||($n>=48&&$n<=57))$oracle.=chr($n);}
 $cases[]=['input'=>$raw,'expected'=>$oracle];
}
$checks=0;$failures=[];$failed=0;
foreach($cases as $case){$_POST=isset($case['missing'])?[]:['campaign_code'=>$case['input']];$discount=5;$campaignCode=null;eval($assignment);$checks++;if($campaignCode!==$case['expected']){$failed++;if(count($failures)<8)$failures[]=['input'=>$case['input']??null,'expected'=>$case['expected'],'actual'=>$campaignCode];}}
echo json_encode(['checks'=>$checks,'failed'=>$failed,'failures'=>$failures,'scope'=>'Actual controller assignment only; no controller request, database, account, coupon creation or network.'],JSON_PRETTY_PRINT)."\n";exit($failed?1:0);
