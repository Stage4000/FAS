<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/models/Product.php';
use FAS\Utils\ProductSyncOwnership as Ownership;
use FAS\Utils\ProductSyncConflict;
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE products(id INTEGER PRIMARY KEY, name TEXT, images TEXT, image_url TEXT, is_active INTEGER); INSERT INTO products VALUES(1,'Before','[\"https://example.invalid/a.jpg\",\"https://example.invalid/b.jpg\"]','',1)");
Ownership::install($db);
$model = new FAS\Models\Product($db);
function verifyRemovalSnapshot(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$before = Ownership::snapshot($db, $model->getById(1, true));
verifyRemovalSnapshot($model->removeAdditionalImage(1, 'https://example.invalid/a.jpg', __DIR__, $before, $after), 'Removal succeeds');
verifyRemovalSnapshot($after !== $before && $after === Ownership::snapshot($db, $model->getById(1, true)), 'Returned token matches removal state');
Ownership::save($db, 1, ['name'=>'Edited'], $after, 1, static function ($id, $data) use ($db) { return $db->prepare('UPDATE products SET name=? WHERE id=?')->execute([$data['name'],$id]); });
verifyRemovalSnapshot($model->getById(1, true)['name'] === 'Edited', 'Same form can save after removal');
try {
    $model->removeAdditionalImage(1, 'https://example.invalid/b.jpg', __DIR__, $after, $rejected);
    throw new RuntimeException('Stale removal accepted');
} catch (ProductSyncConflict $expected) {}
verifyRemovalSnapshot($rejected === null && str_contains($model->getById(1, true)['images'], 'b.jpg'), 'Concurrent edit rejects removal without replacement token or image loss');
try {
    Ownership::save($db, 1, ['name'=>'Stale'], $after, 1, static function () { throw new RuntimeException('Must not write'); });
    throw new RuntimeException('Stale save accepted');
} catch (ProductSyncConflict $expected) {}
echo "PASS removal token refresh, subsequent save, stale removal and stale save protection\n";
