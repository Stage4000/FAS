<?php
require_once __DIR__.'/manuals-common.php';
use FAS\Manuals\ManualLibrary;
use FAS\Utils\CSRF;
$id=isset($_GET['id'])?manualsId():0;
$item=$id?$manualsRepo->getAdmin($id,(int)$admin['id']):null;
if($id&&!$item){http_response_code(404);exit('Manual not found.');}
$data=$item?$item['draft']:ManualLibrary::blank();$revision=$item?$item['revision']:0;$error='';
$keys=$_SESSION['manual_upload_keys']??[];if(!is_array($keys))$keys=[];
$keys=array_filter($keys,static function($t){return is_int($t)&&$t>time()-86400;});
$key='';
if(!$id){$key=($_SERVER['REQUEST_METHOD']??'GET')==='POST'?(is_string($_POST['request_key']??null)?$_POST['request_key']:''):bin2hex(random_bytes(24));if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')$keys[$key]=time();$_SESSION['manual_upload_keys']=array_slice($keys,-30,null,true);}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    foreach(ManualLibrary::blank() as $field=>$unused)if(is_string($_POST[$field]??null))$data[$field]=mb_substr($_POST[$field],0,2100,'UTF-8');
    if($id)$revision=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    try{
        if(!CSRF::validateToken($_POST['csrf_token']??null)){http_response_code(403);throw new InvalidArgumentException('Copy your text, reload this page and try again.');}
        $action=is_string($_POST['action']??null)?$_POST['action']:'';
        if(!in_array($action,['draft','publish','withdraw'],true))throw new InvalidArgumentException('Choose a valid action.');
        $input=[];foreach(ManualLibrary::blank() as $field=>$unused){if(!is_string($_POST[$field]??null))throw new InvalidArgumentException('Invalid manual field.');$input[$field]=$_POST[$field];}
        $input['reviewed']=($_POST['reviewed']??'')==='1';
        if($action!=='withdraw')ManualLibrary::validate($input,$action==='publish');
        if($action==='withdraw'&&($_POST['confirm_withdraw']??'')!=='1')throw new InvalidArgumentException('Confirm removal from public view.');
        if($action!=='draft'){
            if(!$id)throw new InvalidArgumentException('Save and preview a private upload before publishing.');
            if((int)($_SESSION['security_reauth_at']??0)<time()-600){if(!$auth->verifyCurrentPassword((int)$admin['id'],$_POST['password']??null)){http_response_code(403);throw new InvalidArgumentException($auth->lastError?:'Verify your current password.');}$_SESSION['security_reauth_at']=time();}
            if($action==='publish')manualsStorage()->verifiedPath($item['file']);
        }
        if(!$id){
            if(!isset($keys[$key]))throw new InvalidArgumentException('Open a fresh upload form.');
            if(!$auth->isLoggedIn())throw new RuntimeException('Administrator session expired.');
            $storage=manualsStorage();
            try {
                $file=$storage->acceptUpload(is_array($_FILES['pdf']??null)?$_FILES['pdf']:[]);
                $saved=$manualsRepo->create($input,$file,(int)$admin['id'],$key);
            } finally {
                // Keep the committed/referenced original, including uncertain
                // commits. Remove only an unused file created by this request.
                try {$storage->settleNewFiles($manualsDb);}
                catch(Throwable $cleanupError){error_log('Manual upload cleanup deferred: '.$cleanupError->getMessage());}
            }
        }else{$saved=$manualsRepo->save($id,$revision,$input,$action,(int)$admin['id']);}
        header('Location: manuals-edit.php?id='.$saved['id'].'&saved=1',true,303);exit;
    }catch(DomainException $e){http_response_code(409);$error=$e->getMessage();}
    catch(InvalidArgumentException $e){if(http_response_code()<400)http_response_code(422);$error=$e->getMessage();}
    catch(Throwable $e){http_response_code(503);error_log('Manual write failed: '.$e->getMessage());$error='The manual could not be saved. Your text is below. Check storage or the PDF and try again.';}
}
manualsAdminHeader($id?'Edit manual':'Upload PDF manual');
?>
<?php if($error):?><p role="alert" class="manual-error"><?=manualsH($error)?> No submitted password is retained.</p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p role="status" class="manual-notice">Manual saved.</p><?php endif;?>
<?php if(!manualsEnabled()):?><p class="manual-notice">Pre-launch mode: publication only prepares a reviewed snapshot. The public library remains unavailable.</p><?php endif;?>
<?php if($id):?><p><a href="manuals-preview.php?id=<?=$id?>" target="_blank" rel="noopener">Preview saved PDF in the book reader (new tab)</a> · Revision <?=$revision?> · <?=number_format($item['file']['bytes']/1048576,1)?> MiB</p><?php endif;?>
<form method="post" enctype="multipart/form-data" class="manual-form" data-manual-editor data-unsaved="<?=$error!==''?'true':'false'?>">
<?=CSRF::tokenField()?>
<input type="hidden" name="revision" value="<?=$revision?>"><input type="hidden" name="request_key" value="<?=manualsH($key)?>">
<?php if(!$id):?><label>PDF original (current maximum <?=number_format(\FAS\Manuals\ManualStorage::uploadLimit()/1048576,2)?> MiB)<input type="hidden" name="MAX_FILE_SIZE" value="<?=\FAS\Manuals\ManualStorage::uploadLimit()?>"><input type="file" name="pdf" accept="application/pdf,.pdf" required><small>Uploads stay private. Use a separate new entry for a replacement edition. Scan originals for malware before uploading.</small></label><?php endif;?>
<div class="manual-form-grid">
<?php foreach(['title'=>'Manual title','manufacturer'=>'Manufacturer','model'=>'Model / model numbers','years'=>'Years / edition','language'=>'Language'] as $field=>$label):?><label><?=manualsH($label)?><input name="<?=$field?>" value="<?=manualsH($data[$field])?>" maxlength="<?=['title'=>180,'manufacturer'=>80,'model'=>140,'years'=>60,'language'=>40][$field]?>" <?=in_array($field,['title','manufacturer'])?'required':''?>></label><?php endforeach;?>
</div><label>Description<textarea name="description" rows="4" maxlength="1600"><?=manualsH($data['description'])?></textarea></label>
<fieldset><legend>Rights review (private)</legend><p>Owning a copy or agreeing to remove it after a complaint does not establish redistribution permission. Record permission or a suitable license before publication.</p>
<label>Redistribution status<select name="rights"><?php foreach(['unverified'=>'Unverified: private only','cleared'=>'Cleared: evidence recorded','restricted'=>'Restricted: do not publish'] as $value=>$label):?><option value="<?=$value?>" <?=$data['rights']===$value?'selected':''?>><?=manualsH($label)?></option><?php endforeach;?></select></label>
<label>Permission or license evidence<textarea name="rights_note" rows="3" maxlength="2000"><?=manualsH($data['rights_note'])?></textarea></label><label>Source reference (HTTPS, private)<input type="url" name="source_url" maxlength="500" value="<?=manualsH($data['source_url'])?>"></label>
<small>Changing rights to unverified or restricted immediately withdraws any published snapshot when saved.</small></fieldset>
<div class="manual-actions"><button type="submit" name="action" value="draft">Save private draft</button><a href="manuals.php">Back to library</a></div>
<?php if($id):?><fieldset><legend>Publish or withdraw</legend>
<label class="manual-check"><input type="checkbox" name="reviewed" value="1"> I previewed the PDF, checked its metadata and technical relevance, and verified permission to distribute this exact document.</label>
<label class="manual-check"><input type="checkbox" name="confirm_withdraw" value="1"> Remove the current snapshot from public view.</label>
<label>Current administrator password (required if not recently verified)<input type="password" name="password" autocomplete="current-password"></label>
<div class="manual-actions"><button type="submit" name="action" value="publish"><?=manualsEnabled()?'Publish reviewed manual':'Prepare reviewed snapshot'?></button><button type="submit" name="action" value="withdraw" formnovalidate>Withdraw snapshot</button></div></fieldset><?php endif;?>
</form><script src="/public/js/manual-editor.js?v=20261112a" defer></script>
<?php manualsAdminFooter(); ?>
