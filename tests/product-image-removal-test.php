<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/models/Product.php';

$root = sys_get_temp_dir().'/fas-image-removal-'.bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root.'/gallery'); mkdir($root.'/gallery/uploads');
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY, images TEXT, image_url TEXT, is_active INTEGER)');
$model = new FAS\Models\Product($db);
$checks = 0;
function checkRemoval(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function setRemovalImages(PDO $db, array $images, string $primary = ''): void {
    $db->prepare('INSERT OR REPLACE INTO products(id,images,image_url,is_active) VALUES(1,?,?,1)')->execute([json_encode($images),$primary]);
}
function removalImages(PDO $db): string { return $db->query('SELECT images FROM products WHERE id=1')->fetchColumn(); }
try {
    file_put_contents($root.'/outside.txt', 'outside fixture');
    file_put_contents($root.'/gallery/control.txt', 'gallery fixture');
    file_put_contents($root.'/gallery/uploads/member.jpg', 'member fixture');
    file_put_contents($root.'/gallery/uploads/nonmember.jpg', 'nonmember fixture');
    $member = '/gallery/uploads/member.jpg';
    setRemovalImages($db, [$member]);
    checkRemoval(method_exists($model, 'removeAdditionalImage'), 'Guarded image removal must be available');
    $before = removalImages($db);
    checkRemoval(!$model->removeAdditionalImage(1, '/gallery/uploads/nonmember.jpg', $root), 'Nonmember rejected');
    checkRemoval(removalImages($db) === $before && is_file($root.'/gallery/uploads/nonmember.jpg'), 'Nonmember rejection preserves DB and file');
    foreach (['/gallery/uploads/../../outside.txt', 'gallery/uploads/../control.txt',
        '/gallery/uploads/./member.jpg', '/gallery/uploads//member.jpg', '/gallery/uploads/..\\outside.txt'] as $path) {
        setRemovalImages($db, [$path, $member]); $before = removalImages($db);
        checkRemoval(!$model->removeAdditionalImage(1, $path, $root), 'Invalid local path rejected even when stored');
        checkRemoval(removalImages($db) === $before && is_file($root.'/outside.txt') && is_file($root.'/gallery/control.txt'), 'Invalid path leaves all state unchanged');
    }
    symlink($root.'/outside.txt', $root.'/gallery/uploads/linked.jpg');
    symlink($root, $root.'/gallery/uploads/linked-dir');
    symlink('member.jpg', $root.'/gallery/uploads/inside-linked.jpg');
    symlink($root.'/gallery/uploads', $root.'/gallery/uploads/inside-dir');
    foreach (['/gallery/uploads/linked.jpg', '/gallery/uploads/linked-dir/outside.txt',
            '/gallery/uploads/inside-linked.jpg', '/gallery/uploads/inside-dir/member.jpg',
            '/gallery/uploads', '/gallery/uploads/missing.jpg'] as $path) {
        setRemovalImages($db, [$path]); $before = removalImages($db);
        checkRemoval(!$model->removeAdditionalImage(1, $path, $root), 'Symlink, directory or missing local file rejected');
        checkRemoval(removalImages($db) === $before && is_file($root.'/outside.txt'), 'Rejected file leaves DB and target unchanged');
    }
    setRemovalImages($db, [$member]);
    checkRemoval(!$model->updateImages(999, '[]'), 'Missing row update is not success');
    checkRemoval(!$model->updateImages(1, removalImages($db)), 'Unchanged image update is not success');
    checkRemoval(!$model->updateImages(1, '[]', '["outdated"]'), 'Stale compare-and-swap is rejected');
    checkRemoval(is_file($root.'/gallery/uploads/member.jpg'), 'Rejected updates cannot delete member file');
    $db->exec("CREATE TRIGGER reject_images BEFORE UPDATE OF images ON products BEGIN SELECT RAISE(ABORT, 'Synthetic failure'); END");
    try { $model->removeAdditionalImage(1, $member, $root); } catch (PDOException $e) {}
    checkRemoval(is_file($root.'/gallery/uploads/member.jpg') && json_decode(removalImages($db), true) === [$member], 'Database failure preserves physical image and reference');
    $db->exec('DROP TRIGGER reject_images');
    rename($root.'/gallery/uploads', $root.'/real-uploads');
    symlink($root.'/real-uploads', $root.'/gallery/uploads');
    checkRemoval(!$model->removeAdditionalImage(1, $member, $root) && is_file($root.'/real-uploads/member.jpg'), 'Symlink upload root rejected');
    unlink($root.'/gallery/uploads'); rename($root.'/real-uploads', $root.'/gallery/uploads');
    checkRemoval($model->removeAdditionalImage(1, $member, $root), 'Legitimate member removal succeeds');
    checkRemoval(removalImages($db) === '[]' && !file_exists($root.'/gallery/uploads/member.jpg'), 'Legitimate removal updates DB and deletes upload');
    checkRemoval(!$model->removeAdditionalImage(1, $member, $root), 'Repeated removal is rejected');
    mkdir($root.'/gallery/uploads/nested');
    file_put_contents($root.'/gallery/uploads/nested/relative.jpg', 'relative fixture');
    setRemovalImages($db, ['gallery/uploads/nested/relative.jpg']);
    checkRemoval($model->removeAdditionalImage(1, 'gallery/uploads/nested/relative.jpg', $root), 'Legitimate relative upload member remains removable');
    checkRemoval(!file_exists($root.'/gallery/uploads/nested/relative.jpg'), 'Contained nested regular file deleted');
    rmdir($root.'/gallery/uploads/nested');
    file_put_contents($root.'/gallery/uploads/shared.jpg', 'shared fixture');
    $shared='/gallery/uploads/shared.jpg';
    setRemovalImages($db, [$shared], $shared);
    checkRemoval($model->removeAdditionalImage(1, $shared, $root) && is_file($root.'/gallery/uploads/shared.jpg'), 'Primary image is preserved when duplicate additional reference is removed');
    setRemovalImages($db, [$shared]);
    $db->prepare('INSERT INTO products(id,images,image_url,is_active) VALUES(2,?,?,1)')->execute([json_encode([$shared]),'']);
    checkRemoval($model->removeAdditionalImage(1, $shared, $root) && is_file($root.'/gallery/uploads/shared.jpg'), 'Upload referenced by another product is preserved');
    $remote='https://example.invalid/synthetic.jpg';
    setRemovalImages($db, [$remote]);
    checkRemoval($model->removeAdditionalImage(1, $remote, $root) && removalImages($db)==='[]', 'Remote member removal changes only its stored reference');
    $db->exec("UPDATE products SET images='broken json' WHERE id=1");
    checkRemoval(!$model->removeAdditionalImage(1, $remote, $root) && removalImages($db)==='broken json', 'Malformed stored image list remains intact');
    echo "PASS $checks image removal checks; disposable files and in-memory DB only.\n";
} finally {
    unlink($root.'/gallery/uploads/linked.jpg'); unlink($root.'/gallery/uploads/linked-dir');
    unlink($root.'/gallery/uploads/inside-linked.jpg'); unlink($root.'/gallery/uploads/inside-dir');
    foreach (glob($root.'/gallery/uploads/*') as $path) unlink($path);
    rmdir($root.'/gallery/uploads'); unlink($root.'/gallery/control.txt'); rmdir($root.'/gallery');
    unlink($root.'/outside.txt'); rmdir($root);
}
