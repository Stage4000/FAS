<?php
require_once __DIR__.'/manuals-common.php';
$page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>33334]])?:1;
$query=is_string($_GET['q']??'')?trim($_GET['q']??''):'';
$manufacturer=is_string($_GET['manufacturer']??'')?trim($_GET['manufacturer']??''):'';
$searchError='';
$result=['items'=>[],'total'=>0,'manufacturers'=>[],'manufacturers_truncated'=>false];
try {
    if(!is_string($_GET['q']??'') || !is_string($_GET['manufacturer']??'')) throw new \InvalidArgumentException('Enter text in the search and manufacturer fields.');
    $result=$manualsRepo->listAdmin((int)$admin['id'],($page-1)*30,$query,$manufacturer);
    $lastPage=max(1,(int)ceil($result['total']/30));
    if($page>$lastPage){$page=$lastPage;if($result['total']>0)$result=$manualsRepo->listAdmin((int)$admin['id'],($page-1)*30,$query,$manufacturer);}
} catch(\InvalidArgumentException $e) {
    http_response_code(400);$searchError=$e->getMessage();
}
$pageUrl=static function(int $number)use($query,$manufacturer):string {
    $params=[];if($query!=='')$params['q']=$query;if($manufacturer!=='')$params['manufacturer']=$manufacturer;$params['page']=$number;
    return 'manuals.php?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
};
manualsAdminHeader('PDF manuals');
?>
<p>Upload private originals, organize by manufacturer, and review permissions before publishing.</p>
<?php if(!manualsEnabled()):?><p class="manual-notice">Pre-launch mode. All manuals are private even after a publication snapshot is prepared.</p><?php endif;?>
<p><a class="manual-button" href="manuals-edit.php">Upload a manual</a></p>
<form class="manual-form" method="get" action="manuals.php" role="search" aria-label="Search manual drafts">
    <div class="manual-form-grid">
        <label for="manual-query">Search manuals<input type="search" id="manual-query" name="q" maxlength="160" value="<?=manualsH($query)?>" placeholder="Title, manufacturer, model or year" aria-describedby="manual-search-help"></label>
        <label for="manual-manufacturer">Manufacturer<input type="text" id="manual-manufacturer" name="manufacturer" maxlength="80" list="manual-manufacturers" value="<?=manualsH($manufacturer)?>" placeholder="All manufacturers" aria-describedby="manual-manufacturer-help"></label>
    </div>
    <datalist id="manual-manufacturers"><?php foreach($result['manufacturers'] as $facet):?><option value="<?=manualsH($facet['name'])?>"></option><?php endforeach;?></datalist>
    <p id="manual-search-help">Search uses draft titles, manufacturers, models and years. It does not search the text inside PDFs.</p>
    <p id="manual-manufacturer-help">Choose a suggestion or enter the exact manufacturer name. Leave blank to include all manufacturers.<?php if($result['manufacturers_truncated']):?> Showing the first 100 suggestions; you can still type any exact manufacturer name.<?php endif;?></p>
    <div class="manual-actions"><button class="manual-button" type="submit">Search</button><?php if($query!=='' || $manufacturer!=='' || $searchError!==''):?><a href="manuals.php">Clear filters</a><?php endif;?></div>
</form>
<?php if($searchError!==''):?><p class="manual-error" role="alert"><?=manualsH($searchError)?></p><?php else:?>
<p role="status"><?=$result['total']?> <?=$result['total']===1?'manual':'manuals'?> found.</p>
<div class="manual-table-wrap"><table class="table"><thead><tr><th>Manual</th><th>Manufacturer / model</th><th>Rights</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach($result['items'] as $item):?><tr><td><?=manualsH($item['draft']['title'])?></td><td><?=manualsH($item['draft']['manufacturer'].' · '.$item['draft']['model'])?></td><td><?=manualsH($item['draft']['rights'])?></td><td><?=$item['published']?($item['published']===$item['draft']?'Reviewed snapshot':'Unpublished changes'):'Private draft'?></td><td><a href="manuals-edit.php?id=<?=$item['id']?>">Edit</a> · <a href="manuals-preview.php?id=<?=$item['id']?>">Preview</a></td></tr><?php endforeach;?>
<?php if(!$result['items']):?><tr><td colspan="5"><?php if($query!=='' || $manufacturer!==''):?>No manuals match these filters. Clear the filters or try another search.<?php else:?>No manuals yet. Upload a PDF to begin.<?php endif;?></td></tr><?php endif;?></tbody></table></div>
<nav aria-label="Manual pages"><?php if($page>1):?><a href="<?=manualsH($pageUrl($page-1))?>">Previous</a><?php endif;?> <span>Page <?=$page?> of <?=max(1,(int)ceil($result['total']/30))?></span> <?php if($page*30<$result['total']):?><a href="<?=manualsH($pageUrl($page+1))?>">Next</a><?php endif;?></nav>
<?php endif;?>
<?php manualsAdminFooter(); ?>
