<?php
require_once __DIR__ . '/../src/utils/ProductAltText.php';
use FAS\Utils\ProductAltText;
$checks=0;$failures=[];
function check($ok, $label) { global $checks,$failures;$checks++;if(!$ok)$failures[]=$label; }
foreach ([1, 2, 9, 10, 99] as $index) {
    $suffix = ' alternate view ' . ($index + 1);
    foreach ([0, 1, 50, 100, 109, 110, 124, 125, 126, 200, 400] as $length) {
        foreach (['A', 'marine impeller '] as $seed) {
            $name=substr(str_repeat($seed, 401),0,$length);
            $result=ProductAltText::forProductImage(['name'=>$name],$index);
            check(str_ends_with($result,$suffix),"suffix $index/$length/$seed");
            check(strlen($result)<=125,"length $index/$length/$seed");
            check(trim($result)===$result,"trim $index/$length/$seed");
            if ($length===0)check($result==='Product image'.$suffix,"fallback $index");
        }
    }
}
foreach ([0,-1] as $index) {
    check(ProductAltText::forProductImage([],$index)==='Product image','primary fallback');
    check(ProductAltText::forProductImage(['name'=>'Marine water pump'],$index)==='Marine water pump','primary unchanged');
}
foreach (['Café motor','Moteur réparé','水ポンプ', 'Motor &amp; pump'] as $name) {
    $result=ProductAltText::forProductImage(['name'=>$name],1);
    check(preg_match('//u',$result)===1,'UTF8 short input');
    check(str_ends_with($result,' alternate view 2'),'UTF8 suffix');
}
$long=['name'=>str_repeat('Marine impeller replacement assembly ',8)];
check(ProductAltText::forProductImage($long,1)!==ProductAltText::forProductImage($long,2),'long gallery distinction');
check(ProductAltText::forProductImage(['name'=>'Pump','manufacturer'=>'Yamaha','model'=>'123'],1)==='Yamaha 123 Pump alternate view 2','identifier behavior preserved');
echo json_encode(['checks'=>$checks,'failed'=>count($failures),'samples'=>array_slice($failures,0,8)],JSON_PRETTY_PRINT)."\n";
exit($failures ? 1 : 0);
