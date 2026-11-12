<?php
require_once __DIR__.'/blog-common.php';
if (!blogReady()) blogUnavailable();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(404); exit('Post not found.'); }
try { $post = $repository->getAdmin($id, (int)$admin['id']); }
catch (Throwable $e) { error_log('Blog preview failed: '.$e->getMessage()); blogUnavailable(); }
if (!$post) { http_response_code(404); exit('Post not found.'); }
$draft = $post['draft'];
blogHeader('Private draft preview');
?>
<div class="alert alert-info" role="status">This is saved draft revision <?= (int)$post['revision'] ?>, not a public article. Unsaved editor changes are not shown.</div>
<a href="blog-edit.php?id=<?= $id ?>" class="btn btn-outline-secondary mb-4">Edit this post</a>
<article class="card"><div class="card-body p-4 blog-body"><h2><?= blogH($draft['title'] ?: 'Untitled draft') ?></h2><p class="text-muted">By <?= blogH($draft['author_name'] ?: 'Author not set') ?></p><p class="lead"><?= blogH($draft['excerpt']) ?></p>
<?php if ($draft['cover_image'] !== '' && preg_match('~\A/gallery/[A-Za-z0-9_./-]+\z~D', $draft['cover_image']) && strpos($draft['cover_image'], '..') === false): ?><img class="img-fluid mb-4" src="<?= blogH($draft['cover_image']) ?>" alt="<?= blogH($draft['cover_alt']) ?>"><?php endif; ?>
<?= \FAS\Blog\BlogContent::render($draft['body'], $draft['body_format'] ?? 'markdown') ?>
</div></article>
<?php blogFooter(); ?>
