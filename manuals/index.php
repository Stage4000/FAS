<?php
require_once __DIR__.'/../src/manuals/bootstrap.php';
header('Cache-Control: no-store');
try {
    if(!manualsEnabled()){http_response_code(404);exit('Page not found.');}
    $repo=new \FAS\Manuals\ManualLibrary(manualsDatabase(),static function(){return false;});
    $query=is_string($_GET['q']??null)?trim($_GET['q']):'';$mfg=is_string($_GET['manufacturer']??null)?trim($_GET['manufacturer']):'';
    $page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>41667]])?:1;
    $result=$repo->searchPublished($query,$mfg,($page-1)*24);
}catch(InvalidArgumentException $e){http_response_code(400);exit('Check your search and try again.');}
catch(Throwable $e){error_log('Manual catalog unavailable: '.$e->getMessage());http_response_code(503);exit('The manual library is temporarily unavailable. Please try again later.');}
$pageTitle='Service Manual Library';$pageDescription='Browse workshop and service PDF manuals by manufacturer and model.';
$extraHeadMeta='<link rel="stylesheet" href="/public/css/manuals.css?v=20261112a">';
$canonicalUrl='/manuals/';if($query!==''||$mfg!==''||$page>1)$robotsMeta='noindex, follow';
include __DIR__.'/../includes/header.php';
?>
<section class="manual-library"><header class="manual-library-hero"><p class="manual-eyebrow">THE WORKSHOP LIBRARY</p><h1>The right manual.<br><span>Back to the build.</span></h1><p>Find service information by manufacturer, model, or manual title. Open a book and get into the details.</p></header>
<form method="get" class="manual-catalog-search" role="search"><label for="manual-q">Find a manual<input id="manual-q" name="q" type="search" maxlength="160" placeholder="Manufacturer, model, year, or title" value="<?=manualsH($query)?>"></label><label for="manual-mfg">Manufacturer<input id="manual-mfg" name="manufacturer" type="text" maxlength="80" list="manual-manufacturers" placeholder="All manufacturers" aria-describedby="manual-mfg-help" value="<?=manualsH($mfg)?>"><datalist id="manual-manufacturers"><?php foreach($result['manufacturers'] as $facet):?><option value="<?=manualsH($facet['name'])?>"><?=manualsH($facet['name'])?> (<?=(int)$facet['total']?>)</option><?php endforeach;?></datalist></label><button type="submit">Find manuals</button><?php if($query!==''||$mfg!==''):?><a href="/manuals/">Clear</a><?php endif;?></form>
<p id="manual-mfg-help" class="manual-filter-help"><?=$result['manufacturers_truncated']?'Showing the first 100 manufacturer suggestions. ':''?>Type any exact manufacturer name, including one not suggested. Leave blank for all. For a partial name, use Find a manual.</p>
<div class="manual-catalog-heading"><h2><?=$mfg!==''?manualsH($mfg):'Browse the shelf'?></h2><span><?=$result['total']?> <?=$result['total']===1?'manual':'manuals'?></span></div>
<div class="manual-grid"><?php foreach($result['items'] as $item):$d=$item['metadata'];?><article class="manual-card"><a class="manual-card-cover" href="/manuals/view.php?id=<?=$item['id']?>" tabindex="-1" aria-hidden="true"><span class="manual-cover-brand">F/S</span><span><?=manualsH($d['manufacturer'])?></span><strong><?=manualsH($d['model']?:'Service manual')?></strong><small><?=manualsH($d['years'])?></small><span class="manual-cover-footer">WORKSHOP REFERENCE / PDF</span></a><div class="manual-card-body"><p class="manual-eyebrow"><?=manualsH($d['manufacturer'])?></p><h3><a href="/manuals/view.php?id=<?=$item['id']?>"><?=manualsH($d['title'])?></a></h3><p><?=manualsH(mb_strimwidth($d['description'],0,150,'…','UTF-8'))?></p><span class="manual-card-language"><?=manualsH($d['language'])?></span><a class="manual-read-link" href="/manuals/view.php?id=<?=$item['id']?>">Open manual <span aria-hidden="true">↗</span><span class="visually-hidden">: <?=manualsH($d['title'])?></span></a></div></article><?php endforeach;?></div>
<?php if(!$result['items']):?><div class="manual-empty"><h3><?=$result['total']?'No manuals on this page':($query!==''||$mfg!==''?'No matching manuals yet':'The shelves are being stocked')?></h3><p><?=$query!==''||$mfg!==''?'Try a broader model name, or clear the manufacturer filter.':'Approved manuals will appear here as the library grows.'?></p><a href="/manuals/">Browse all manuals</a></div><?php endif;?>
<nav class="manual-pagination" aria-label="Manual results pages"><?php $params=['q'=>$query,'manufacturer'=>$mfg];if($page>1):?><a href="?<?=manualsH(http_build_query($params+['page'=>$page-1]))?>">← Previous</a><?php endif;?><span>Page <?=$page?></span><?php if($page*24<$result['total']):?><a href="?<?=manualsH(http_build_query($params+['page'=>$page+1]))?>">Next →</a><?php endif;?></nav>
<p class="manual-disclaimer">Match the model, year, and edition to your equipment. Follow the manufacturer's safety procedures and consult a qualified technician when needed.</p></section>
<?php include __DIR__.'/../includes/footer.php'; ?>
