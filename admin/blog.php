<?php
require_once __DIR__.'/blog-common.php';
if (!blogReady()) blogUnavailable();
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>33334]]);
if ($page === false) $page = 1;
try { $result = $repository->listAdmin((int)$admin['id'], 30, ($page-1)*30); }
catch (Throwable $e) { error_log('Blog list failed: '.$e->getMessage()); blogUnavailable(); }
$publicEnabled = blogPublicEnabled();
blogHeader('Blog posts');
?>
<?php if (!$publicEnabled): ?><div class="alert alert-info" role="status">Pre-launch mode: the public blog is unreleased. Reviewed snapshots are prepared content, not live articles.</div><?php endif; ?>
<p>Drafts and previews are private. Saving a draft does not change <?= $publicEnabled ? 'the published article' : 'a reviewed snapshot' ?>.</p>
<a href="blog-edit.php" class="btn btn-primary mb-4">New post</a>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table align-middle">
<thead><tr><th scope="col">Title</th><th scope="col">Status</th><th scope="col">Updated</th><th scope="col">Actions</th></tr></thead><tbody>
<?php foreach ($result['items'] as $post): ?>
<tr><td><?= blogH($post['draft']['title'] ?: 'Untitled draft') ?></td><td><?= blogH(blogStateLabel($post['state'], $publicEnabled)) ?></td><td><?= blogH(\FAS\Utils\Timezone::toUserDateTime($post['updated_at'])) ?></td><td><a class="btn btn-sm btn-outline-primary" href="blog-edit.php?id=<?= (int)$post['id'] ?>">Edit</a> <a class="btn btn-sm btn-outline-secondary" href="blog-preview.php?id=<?= (int)$post['id'] ?>" target="_blank" rel="noopener">Preview saved draft<span class="visually-hidden"> (new tab)</span></a></td></tr>
<?php endforeach; ?>
<?php if (!$result['items']): ?><tr><td colspan="4">No posts here yet. Create a draft to start writing.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<nav class="d-flex gap-3 mt-3" aria-label="Blog pages"><?php if ($page>1): ?><a href="blog.php?page=<?= $page-1 ?>">Previous</a><?php endif; ?><span>Page <?= $page ?></span><?php if ($page*30<(int)$result['total']): ?><a href="blog.php?page=<?= $page+1 ?>">Next</a><?php endif; ?></nav>
<?php blogFooter(); ?>
