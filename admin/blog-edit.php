<?php
require_once __DIR__.'/blog-common.php';
use FAS\Blog\BlogContent;
use FAS\Utils\CSRF;
if (!blogReady()) blogUnavailable();
$id = null;
if (isset($_GET['id'])) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$id) { http_response_code(404); exit('Post not found.'); }
}
try { $post = $id ? $repository->getAdmin($id, (int)$admin['id']) : null; }
catch (Throwable $e) { error_log('Blog editor read failed: '.$e->getMessage()); blogUnavailable(); }
if ($id && !$post) { http_response_code(404); exit('Post not found.'); }
$draft = $post ? $post['draft'] : BlogContent::empty();
$revision = $post ? (int)$post['revision'] : 0;
$error = '';
$publicEnabled = blogPublicEnabled();
$limits = ['title'=>160,'slug'=>120,'excerpt'=>320,'body'=>400000,'author_name'=>80,'seo_title'=>110,'seo_description'=>160,'cover_image'=>500,'cover_alt'=>160];
$related = implode(', ', $draft['related_product_ids']);
$requestKey = '';
if (!$id) {
    // Keep independent editor tabs and successful retries idempotent. Keys expire after one day.
    $keys = $_SESSION['blog_create_keys'] ?? [];
    if (!is_array($keys)) $keys = [];
    $keys = array_filter($keys, function ($created) { return is_int($created) && $created > time()-86400; });
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestKey = is_string($_POST['request_key'] ?? null) ? $_POST['request_key'] : '';
    } else {
        $requestKey = bin2hex(random_bytes(24));
        $keys[$requestKey] = time();
    }
    $_SESSION['blog_create_keys'] = array_slice($keys, -50, null, true);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bound and preserve submitted values even when validation fails. Password is never retained.
    foreach ($limits as $field=>$limit) {
        if (is_string($_POST[$field] ?? null)) $draft[$field] = mb_substr($_POST[$field], 0, $limit+1, 'UTF-8');
    }
    $related = is_string($_POST['related_product_ids'] ?? null) ? substr($_POST['related_product_ids'], 0, 200) : '';
    if ($id) {
        $submittedRevision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        // Never refresh a stale revision after an unsuccessful write.
        $revision = $submittedRevision === false || $submittedRevision === null ? -1 : $submittedRevision;
    }
    try {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) { http_response_code(403); throw new InvalidArgumentException('Refresh the page before trying again. Copy your text first.'); }
        $action = $_POST['action'] ?? '';
        if (!is_string($action) || !in_array($action, ['draft','publish','withdraw'], true)) throw new InvalidArgumentException('Choose a valid action.');
        if (!$id && ($action !== 'draft' || !isset($_SESSION['blog_create_keys'][$requestKey]))) throw new InvalidArgumentException('Save a new draft from a fresh editor before publishing. Copy your text before reopening.');
        if ($id && $revision < 0) throw new InvalidArgumentException('Invalid revision. Copy your text and reload.');
        $input = $draft;
        $input['body_format'] = is_string($_POST['body_format'] ?? null) ? $_POST['body_format'] : 'markdown';
        $draft['body_format'] = $input['body_format'];
        if ($action !== 'withdraw') {
            foreach ($limits as $field=>$limit) {
                if (!is_string($_POST[$field] ?? null) || mb_strlen($_POST[$field], 'UTF-8') > $limit) throw new InvalidArgumentException('Check the '.str_replace('_',' ',$field).' field and its length.');
            }
            if (!is_string($_POST['related_product_ids'] ?? null) || strlen($_POST['related_product_ids'])>200) throw new InvalidArgumentException('Enter up to six product IDs separated by commas.');
            $ids = trim($related) === '' ? [] : array_map('trim', explode(',', $related));
            if (count($ids)>6) throw new InvalidArgumentException('Choose no more than six related products.');
            $input['related_product_ids'] = [];
            foreach ($ids as $value) {
                $productId = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if (!$productId) throw new InvalidArgumentException('Related product IDs must be positive whole numbers separated by commas.');
                $input['related_product_ids'][] = $productId;
            }
            $input['reviewed'] = ($_POST['reviewed'] ?? '') === '1';
            if ($action === 'publish' && !$input['reviewed']) throw new InvalidArgumentException('Confirm the technical facts, photos and linked products before publishing.');
            $validated = BlogContent::validate($input, $action === 'publish');
            $imagePaths = $validated['cover_image'] !== '' ? [$validated['cover_image']] : [];
            if ($action === 'publish' && ($validated['body_format'] ?? 'markdown') === 'rich_v1') {
                foreach (\FAS\Blog\BlogRichDocument::validate($validated['body'])['blocks'] as $block) {
                    if ($block['type'] === 'image') $imagePaths[] = $block['src'];
                }
            }
            foreach ($action === 'publish' ? $imagePaths : [] as $imagePath) {
                $galleryRoot = realpath(__DIR__.'/../gallery');
                $coverPath = realpath(__DIR__.'/..'.$imagePath);
                $rootPrefix = $galleryRoot === false ? '' : rtrim(str_replace('\\','/',$galleryRoot),'/').'/';
                $resolvedCover = $coverPath === false ? '' : str_replace('\\','/',$coverPath);
                if (DIRECTORY_SEPARATOR === '\\') { $rootPrefix = strtolower($rootPrefix); $resolvedCover = strtolower($resolvedCover); }
                if ($rootPrefix === '' || $resolvedCover === '' || strpos($resolvedCover,$rootPrefix)!==0 || !is_file($coverPath) || !is_readable($coverPath)) {
                    throw new InvalidArgumentException('Every article image must be an existing, readable file inside this site\'s gallery. Check the image path or remove the image before publishing.');
                }
            }
        }
        if ($action === 'withdraw' && ($_POST['confirm_withdraw'] ?? '') !== '1') throw new InvalidArgumentException($publicEnabled ? 'Confirm that this article should be removed from public view.' : 'Confirm that the reviewed snapshot should be removed.');
        if ($action !== 'draft' && (int)($_SESSION['security_reauth_at'] ?? 0)<time()-600) {
            if (!$auth->verifyCurrentPassword((int)$admin['id'], $_POST['password'] ?? null)) { http_response_code(403); throw new InvalidArgumentException($auth->lastError ?: 'Verify your current password to publish or withdraw.'); }
            $_SESSION['security_reauth_at'] = time();
        }
        $savedPost = $id ? $repository->save($id, $revision, $action, $input, (int)$admin['id']) : $repository->createDraft($input, (int)$admin['id'], $requestKey);
        header('Location: blog-edit.php?id='.(int)$savedPost['id'].'&saved='.$action, true, 303);
        exit;
    } catch (DomainException $e) {
        http_response_code(409); $error = $e->getMessage().' Your submitted text remains below. Copy it before reloading and comparing the latest saved draft.';
    } catch (InvalidArgumentException $e) {
        if (http_response_code()<400) http_response_code(422);
        $error = $e->getMessage();
    } catch (Throwable $e) {
        http_response_code(503); $error = 'The save could not be confirmed. Your text remains below. Check the saved draft before retrying.';
        error_log('Blog editor save failed: '.$e->getMessage());
    }
}
$notices = ['draft'=>$publicEnabled ? 'Draft saved. Published content is unchanged.' : 'Draft saved. The reviewed snapshot is unchanged.','publish'=>$publicEnabled ? 'Article published.' : 'Reviewed publication snapshot saved. The public blog remains unreleased.','withdraw'=>$publicEnabled ? 'Article withdrawn. Your draft remains saved.' : 'Reviewed snapshot removed. Your draft remains saved.'];
$saved = is_string($_GET['saved'] ?? null) ? $_GET['saved'] : '';
$visualHtml = null;
try {
    $visualDocument = ($draft['body_format'] ?? 'markdown') === 'rich_v1' ? \FAS\Blog\BlogRichDocument::validate($draft['body']) : \FAS\Blog\BlogRichDocument::fromMarkdown($draft['body']);
    $visualHtml = \FAS\Blog\BlogRichDocument::render($visualDocument);
} catch (Throwable $e) { /* Keep invalid submitted source visible for correction without replacing it. */ }
blogHeader($id ? 'Edit blog post' : 'New blog post');
?>
<div class="d-flex flex-wrap gap-2 mb-3"><a href="blog.php" class="btn btn-outline-secondary">All posts</a><?php if ($id): ?><a href="blog-preview.php?id=<?= $id ?>" target="_blank" rel="noopener" class="btn btn-outline-primary">Preview saved draft (new tab)</a><?php endif; ?></div>
<p>Save draft keeps work private and leaves <?= $publicEnabled ? 'any published article' : 'any reviewed snapshot' ?> unchanged. Preview shows the last saved draft only.</p>
<?php if (!$publicEnabled): ?><div class="alert alert-info" role="status">Pre-launch mode: the public blog is unreleased. You can prepare drafts and save reviewed publication snapshots here. These snapshots will become eligible for public display only after the owner releases the public blog.</div><?php endif; ?>
<?php if ($post): ?><p><strong>Status:</strong> <?= blogH(blogStateLabel($post['state'], $publicEnabled)) ?> · Saved revision <?= (int)$post['revision'] ?></p><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= blogH($error) ?></div><?php elseif (isset($notices[$saved])): ?><div class="alert alert-success" role="status"><?= blogH($notices[$saved]) ?></div><?php endif; ?>
<form method="post" class="card" data-unsaved="<?= $error !== '' ? 'true' : 'false' ?>"><div class="card-body p-4">
<?= CSRF::tokenField() ?>
<input type="hidden" name="revision" value="<?= $revision ?>">
<input type="hidden" name="body_format" value="<?= blogH($draft['body_format'] ?? 'markdown') ?>">
<?php if (!$id): ?><input type="hidden" name="request_key" value="<?= blogH($requestKey) ?>"><?php endif; ?>
<?php foreach (['title'=>'Article title','slug'=>'URL slug','author_name'=>'Author name','excerpt'=>'Short summary','body'=>'Article body'] as $field=>$label): ?>
<div class="mb-3"><label class="form-label fw-semibold" for="blog-<?= $field ?>"><?= $label ?></label>
<?php if (in_array($field,['excerpt','body'],true)): ?><textarea class="form-control" id="blog-<?= $field ?>" name="<?= $field ?>" maxlength="<?= $limits[$field] ?>" rows="<?= $field==='body'?18:3 ?>" aria-describedby="help-<?= $field ?>"><?= blogH($draft[$field]) ?></textarea>
<?php else: ?><input class="form-control" id="blog-<?= $field ?>" name="<?= $field ?>" maxlength="<?= $limits[$field] ?>" value="<?= blogH($draft[$field]) ?>" <?= $field==='slug' && !empty($post['slug'])?'readonly':'' ?>><?php endif; ?>
<?php if ($field==='body'): ?><p class="form-text" id="help-body">Write visually below. Pasted text is kept as plain text. Up to 60,000 visible characters; images use existing gallery files.</p>
<?php if ($visualHtml !== null): ?>
<div id="blog-visual-editor" hidden>
<div class="blog-editor-toolbar" role="toolbar" aria-label="Article formatting">
<?php foreach (['p'=>'Paragraph','h2'=>'Heading 2','h3'=>'Heading 3','bold'=>'Bold','italic'=>'Italic','underline'=>'Underline','list'=>'Bullets','ordered'=>'Numbered list','blockquote'=>'Quote','link'=>'Add link','unlink'=>'Remove link','image'=>'Image','undo'=>'Undo','redo'=>'Redo'] as $command=>$label): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-command="<?= $command ?>"><?= $label ?></button><?php endforeach; ?>
</div>
<div class="blog-editor-canvas border rounded-bottom p-3" data-editor-canvas contenteditable="true" role="textbox" aria-multiline="true" aria-label="Article body visual editor" aria-describedby="blog-editor-status" spellcheck="true"><?= $visualHtml ?></div>
<p id="blog-editor-status" data-editor-status class="form-text" aria-live="polite"></p>
<p class="form-text">Shortcuts: Ctrl/Cmd+B bold, I italic, U underline, K link, Z undo, Shift+Z redo. Select text before formatting it.</p>
<dialog data-link-dialog aria-labelledby="blog-link-heading"><h3 class="h5" id="blog-link-heading">Add a link</h3><label class="form-label" for="blog-link-text">Link text</label><input class="form-control mb-2" id="blog-link-text" data-link-text maxlength="60000"><label class="form-label" for="blog-link-url">Destination</label><input class="form-control" id="blog-link-url" data-link-url maxlength="2000" placeholder="https://example.com or /products.php"><p data-link-error role="alert" class="text-danger"></p><button type="button" class="btn btn-primary" data-link-apply>Insert link</button> <button type="button" class="btn btn-secondary" data-link-cancel>Cancel</button></dialog>
<dialog data-image-dialog aria-labelledby="blog-image-heading"><h3 class="h5" id="blog-image-heading">Choose an existing image</h3>
<label for="blog-gallery-search" class="form-label">Find a photo</label><div class="blog-gallery-search"><input class="form-control" id="blog-gallery-search" data-gallery-search type="search" maxlength="100" placeholder="Search by filename"><button type="button" class="btn" data-gallery-search-button>Search</button></div>
<p data-gallery-status role="status" class="form-text">Choose a photo from the gallery.</p><div class="blog-gallery-grid" data-gallery-grid aria-label="Gallery photos"></div><button type="button" class="btn" data-gallery-more hidden>Show more photos</button>
<p data-gallery-selected class="form-text">No image selected.</p>
<label for="blog-image-alt" class="form-label">Describe the image</label><input class="form-control mb-2" id="blog-image-alt" data-image-alt maxlength="160" placeholder="What should a reader know about this photo?">
<label for="blog-image-caption" class="form-label">Caption (optional)</label><input class="form-control" id="blog-image-caption" data-image-caption maxlength="320">
<details class="mt-3"><summary>Advanced: enter an existing image path</summary><label for="blog-image-src" class="form-label">Gallery path</label><input class="form-control mb-2" id="blog-image-src" data-image-src maxlength="500" placeholder="/gallery/photo.jpg"></details>
<p class="form-text">Choose an existing gallery photo. No files are uploaded.</p><p data-image-error role="alert" class="text-danger"></p><button type="button" class="btn btn-primary" data-image-apply>Insert image</button> <button type="button" class="btn btn-secondary" data-image-cancel>Cancel</button></dialog>
</div>
<?php else: ?><p class="text-warning" role="status">This submitted body cannot be opened in the visual editor. Its source is preserved above so you can copy or correct it.</p><?php endif; ?>
<noscript><p class="text-warning">JavaScript is needed for the visual editor. The source field above remains available.</p></noscript>
<?php elseif ($field==='excerpt'): ?><p class="form-text" id="help-excerpt">Up to 320 characters. Required for publication.</p><?php elseif ($field==='slug'): ?><p class="form-text">Lowercase letters, numbers and hyphens. The URL is locked after first publication, even if withdrawn.</p><?php endif; ?>
</div><?php endforeach; ?>
<details class="mb-4"><summary>Search details, existing cover image and related inventory</summary><div class="mt-3">
<?php foreach (['seo_title'=>'Search title (optional)','seo_description'=>'Search description (optional)','cover_image'=>'Existing image path (optional)','cover_alt'=>'Image description'] as $field=>$label): ?><div class="mb-3"><label for="blog-<?= $field ?>" class="form-label"><?= $label ?></label><input class="form-control" id="blog-<?= $field ?>" name="<?= $field ?>" maxlength="<?= $limits[$field] ?>" value="<?= blogH($draft[$field]) ?>"><?php if ($field==='cover_image'): ?><button type="button" class="btn btn-outline-primary mt-2" data-cover-picker>Choose cover from gallery</button><?php endif; ?></div><?php endforeach; ?>
<p class="form-text">Use an existing /gallery/ image path. An image description is required when a cover is set. This editor does not upload photos.</p>
<label for="blog-related" class="form-label">Related product IDs</label><input class="form-control" id="blog-related" name="related_product_ids" maxlength="200" value="<?= blogH($related) ?>"><p class="form-text">Up to six inventory IDs, separated by commas. Check that each product is relevant to the article.</p>
</div></details>
<button class="btn btn-primary" type="submit" name="action" value="draft">Save draft</button>
<?php if ($id): ?>
<hr><h2 class="h5">Publication</h2><p><?= $publicEnabled ? 'Publishing makes the submitted draft public.' : 'This saves the submitted draft as a reviewed publication snapshot. The public blog remains unreleased.' ?> Review all technical claims and photos first.</p>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="blog-reviewed" name="reviewed" value="1"><label class="form-check-label" for="blog-reviewed">I reviewed the technical facts, photos and related inventory for publication.</label></div>
<?php if ((int)($_SESSION['security_reauth_at'] ?? 0)<time()-600): ?><label for="blog-password" class="form-label">Current password to publish or withdraw</label><input class="form-control mb-3" id="blog-password" name="password" type="password" autocomplete="current-password"><?php endif; ?>
<button class="btn btn-danger" type="submit" name="action" value="publish"><?= $publicEnabled ? 'Publish reviewed article' : 'Save reviewed publication snapshot' ?></button>
<?php if ($post['published'] !== null): ?><details class="mt-4"><summary><?= $publicEnabled ? 'Withdraw article' : 'Remove reviewed snapshot' ?></summary><p class="mt-2"><?= $publicEnabled ? 'Remove this article from public view while keeping the draft and reserved URL.' : 'Remove the reviewed snapshot while keeping the draft and reserved URL. The public blog remains unreleased.' ?> This keeps the last saved draft; unsaved edits in this form are not saved by this action.</p><div class="form-check mb-3"><input type="checkbox" class="form-check-input" id="blog-withdraw" name="confirm_withdraw" value="1"><label class="form-check-label" for="blog-withdraw"><?= $publicEnabled ? 'Remove the published article from public view.' : 'Remove this reviewed snapshot.' ?></label></div><button class="btn btn-outline-danger" type="submit" name="action" value="withdraw"><?= $publicEnabled ? 'Withdraw article' : 'Remove reviewed snapshot' ?></button></details><?php endif; ?>
<?php endif; ?>
</div></form>
<?php if ($post && $post['history']): ?><section class="mt-4"><h2 class="h5">Recent activity</h2><ul><?php foreach ($post['history'] as $event): ?><li>Revision <?= (int)$event['revision'] ?> · <?= blogH($event['action']) ?> · Admin #<?= (int)$event['actor_id'] ?> · <?= blogH(\FAS\Utils\Timezone::toUserDateTime($event['created_at'])) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<dialog data-leave-dialog aria-labelledby="blog-leave-heading" aria-describedby="blog-leave-description"><h3 class="h5" id="blog-leave-heading">Leave without saving?</h3><p id="blog-leave-description">Your unsaved article and field changes will be lost.</p><button type="button" class="btn btn-primary" data-keep-editing autofocus>Keep editing</button> <button type="button" class="btn btn-secondary" data-confirm-leave>Leave without saving</button></dialog>
<script defer src="js/blog-editor.js"></script>
<?php blogFooter(); ?>
