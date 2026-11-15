<?php
declare(strict_types=1);
$root=$argv[1]??dirname(__DIR__);$checks=0;
function check($condition,$message){global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function render($template,$query){
 $search=$query;$manufacturer='';$fitmentModel='';$currentCategoryName='All Products';$homepageCategory='';$ebayCat1='';$ebayCat2='';$ebayCat3='';$includeHiddenProducts=false;
 ob_start();try{eval('?>'.$template);return ob_get_contents();}finally{ob_end_clean();}
}
foreach(['products.php','api/products.php'] as $path){
 $source=file_get_contents($root.'/'.$path);preg_match('~<h1\b[^>]*>[\s\S]*?</h1>~',$source,$heading);preg_match('~<form\b[^>]*\bid="search-form"[\s\S]*?</form>~',$source,$form);check(isset($heading[0],$form[0]),'actual source fragments');
 foreach([null,'','0','rotor','0 & " <tag>'] as $query){
  $active=$query!==null&&$query!=='';$h=render($heading[0],$query);$f=render($form[0],$query);
  check(str_contains($h,'Search Results for')===$active,$path.' heading for '.var_export($query,true));
  check(str_contains($f,'title="Clear search"')===$active,$path.' Clear for '.var_export($query,true));
  check(str_contains($f,'value="'.htmlspecialchars($query??'').'"'),$path.' exact escaped input');
  if($active)check(str_contains($h,htmlspecialchars($query)),$path.' escaped heading query');
  else check(str_contains($h,'All Products'),$path.' default heading');
  check(!str_contains($h,'<tag>')&&!str_contains($f,'<tag>'),$path.' query cannot create markup');
 }
}
echo json_encode(['passed'=>true,'checks'=>$checks,'scope'=>'Actual template fragments only; no inventory query behavior changed'])."\n";
