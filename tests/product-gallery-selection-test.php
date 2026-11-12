<?php
/** Render actual gallery initialization/template using synthetic photos; no database or network. */
namespace FAS\Utils {
 class ResponsiveImage { static function attributes(...$args) { return 'src="'.htmlspecialchars($args[0]).'"'; } }
}
namespace {
 class ProductAltText { static function forProductImage(...$args) { return 'Synthetic photo'; } }
 $source = file_get_contents(getenv('FAS_PRODUCT_SOURCE') ?: __DIR__.'/../product.php');
 function section($source, $start, $end) {
     $a=strpos($source,$start);$b=strpos($source,$end,$a);
     if($a===false || $b===false) throw new \RuntimeException('Source boundary missing');
     return substr($source,$a,$b-$a);
 }
 $normalizer=section($source,'function normalizeImagePath(', '// Normalize all image paths');
 eval($normalizer);
 $init=section($source,'// Parse images from JSON if available','// Set meta tags for social media sharing');
 $init=str_replace($normalizer,'',$init);
 $gallery=section($source,'            <?php if (!empty($images) && count($images) > 1): ?>','<label for="quantity-input"');
 // Template end is just after its outer endif, before the next layout block.
 $end=strpos($gallery,'<?php endif; ?>',strpos($gallery,'<?php endforeach; ?>'));
 $gallery=substr($gallery,0,$end+strlen('<?php endif; ?>'));
 $a='https://example.invalid/a.jpg';$b='https://example.invalid/b.jpg';$c='https://example.invalid/c.jpg';
 $cases=[
 ['main first',$a,[$a,$b],0], ['main second',$b,[$a,$b],1],
 ['main third',$c,[$a,$b,$c],2], ['main absent',$c,[$a,$b],0],
 ['no main',null,[$a,$b],0], ['duplicate main',$b,[$a,$b,$b],1],
 ['empty',null,[],null], ['single',$a,[$a],null],
 ];
 $checks=0;$failed=[];
 foreach($cases as [$name,$primary,$photos,$expected]) {
     $product=['id'=>1,'image_url'=>$primary,'images'=>json_encode($photos)];
     eval($init);ob_start();eval('?>'.$gallery);$html=ob_get_clean();
     preg_match_all('/<button\b[^>]*>/s',$html,$buttons);
     $pressed=[];$active=[];
     foreach($buttons[0] as $i=>$button){
         if(str_contains($button,'aria-pressed="true"'))$pressed[]=$i;
         if(preg_match('/class="[^"]*\bactive\b/',$button))$active[]=$i;
     }
     $want=$expected===null?[]:[$expected];
     foreach(['pressed'=>$pressed,'active'=>$active] as $label=>$got){$checks++;if($got!==$want)$failed[]="$name $label";}
     $checks++;if($mainImage!==($primary ?: ($photos[0]??'/gallery/default.jpg')))$failed[]="$name hero";
     $wantPhotos=$photos;if($primary&&!in_array($primary,$photos))array_unshift($wantPhotos,$primary);
     $checks++;if($images!==$wantPhotos)$failed[]="$name order";
 }
 echo json_encode(['checks'=>$checks,'failures'=>$failed],JSON_PRETTY_PRINT)."\n";
 exit($failed?1:0);
}
