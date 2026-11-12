<?php
require_once __DIR__.'/manuals-common.php';
$page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>33334]])?:1;
$result=$manualsRepo->listAdmin((int)$admin['id'],($page-1)*30);
manualsAdminHeader('PDF manuals');
?>
<p>Upload private originals, organize by manufacturer, and review permissions before publishing.</p>
<?php if(!manualsEnabled()):?><p class="manual-notice">Pre-launch mode. All manuals are private even after a publication snapshot is prepared.</p><?php endif;?>
<p><a class="manual-button" href="manuals-edit.php">Upload a manual</a></p>
<div class="manual-table-wrap"><table class="table"><thead><tr><th>Manual</th><th>Manufacturer / model</th><th>Rights</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach($result['items'] as $item):?><tr><td><?=manualsH($item['draft']['title'])?></td><td><?=manualsH($item['draft']['manufacturer'].' · '.$item['draft']['model'])?></td><td><?=manualsH($item['draft']['rights'])?></td><td><?=$item['published']?($item['published']===$item['draft']?'Reviewed snapshot':'Unpublished changes'):'Private draft'?></td><td><a href="manuals-edit.php?id=<?=$item['id']?>">Edit</a> · <a href="manuals-preview.php?id=<?=$item['id']?>">Preview</a></td></tr><?php endforeach;?>
<?php if(!$result['items']):?><tr><td colspan="5">No manuals yet. Upload a PDF to begin.</td></tr><?php endif;?></tbody></table></div>
<nav aria-label="Manual pages"><?php if($page>1):?><a href="?page=<?=$page-1?>">Previous</a><?php endif;?> <span>Page <?=$page?></span> <?php if($page*30<$result['total']):?><a href="?page=<?=$page+1?>">Next</a><?php endif;?></nav>
<?php manualsAdminFooter(); ?>
