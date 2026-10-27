<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/utils/ProductEditor.php';
require_once __DIR__.'/../src/models/Product.php';
use FAS\Utils\ProductEditor;

$checks = 0;
function editorCheck($ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, condition_name TEXT, images TEXT, image_url TEXT, sku TEXT, price TEXT, quantity INTEGER, description TEXT, is_active INTEGER, updated_at TEXT)');
$model = new FAS\Models\Product($db);
$images = ['https://images.example.invalid/imported.jpg?x=1&y=2', '/gallery/uploads/local.jpg', 'https://images.example.invalid/imported.jpg?x=1&y=2', 'gallery/legacy.png'];
$json = json_encode($images, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$insert = $db->prepare('INSERT OR REPLACE INTO products VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach (['New other (see details)', 'Legacy "grade" <script>alert(1)</script> & \'special\'', ''] as $condition) {
    // Include all columns, including the model's migration-added free_shipping.
    $insert->execute(['Original title', $condition, $json, $images[0], 'SYNTHETIC', '149.95', 1, 'Unchanged fitment', 1, 'unchanged', 0]);
    $before = $db->query('SELECT * FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $html = '<select>'.ProductEditor::renderConditionOptions($before).'</select>';
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $selected = (new DOMXPath($dom))->query('//option[@selected]');
    editorCheck($selected->length === 1 && $selected->item(0)->getAttribute('value') === $condition, 'Exact imported value selected');
    editorCheck($dom->getElementsByTagName('script')->length === 0, 'Legacy condition escaped');
    $post = ['condition_name' => $selected->item(0)->getAttribute('value')];
    $fields = ProductEditor::fields($post, $before, []);
    editorCheck(!array_key_exists('images', $fields), 'Ordinary edit omits gallery write');
    $model->update(1, array_merge(['name' => 'Corrected title'], $fields));
    $after = $db->query('SELECT * FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $expected = $before; $expected['name'] = 'Corrected title';
    editorCheck($after === $expected, 'Title-only edit preserves every other stored field and exact gallery JSON');
    editorCheck(ProductEditor::fields([], $before, [])['condition_name'] === $condition, 'Omitted condition preserves imported value');
    $model->update(1, ProductEditor::fields(['condition_name' => 'Used'], $after, []));
    $used = $db->query('SELECT * FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $expected['condition_name'] = 'Used';
    editorCheck($used === $expected, 'Explicit valid condition change touches only condition');
    $append = ProductEditor::fields(['condition_name' => $condition], $before, ['/gallery/uploads/new.jpg']);
    editorCheck($append['images'] === array_merge($images, ['/gallery/uploads/new.jpg']), 'Upload appends without deleting, deduplicating, or reordering imported references');
}
editorCheck(strpos(ProductEditor::renderConditionOptions(null), 'value="New" selected') !== false, 'Create defaults to New');
editorCheck(in_array('New other (see details)', ProductEditor::conditionOptions(null), true), 'Missing condition available for new products');
foreach (ProductEditor::conditionOptions(null, ['Imported catalog grade']) as $condition) {
    $row = ['condition_name'=>$condition, 'images'=>$json];
    editorCheck(ProductEditor::fields(['condition_name'=>$condition], $row, [], ['Imported catalog grade'])['condition_name'] === $condition, 'All importer and catalog labels roundtrip');
}
editorCheck(ProductEditor::fields(['condition_name'=>''], ['condition_name'=>null], [])['condition_name'] === null, 'NULL condition remains NULL');
editorCheck(ProductEditor::fields([], ['condition_name'=>null], [])['condition_name'] === null, 'Omitted NULL condition remains NULL');
$stale = $db->query('SELECT * FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$reordered = array_reverse($images);
editorCheck($model->updateImages(1, json_encode($reordered)), 'Explicit gallery reorder succeeds');
$model->update(1, ProductEditor::fields(['condition_name'=>$stale['condition_name']], $stale, []));
editorCheck(json_decode($db->query('SELECT images FROM products WHERE id=1')->fetchColumn(), true) === $reordered, 'Stale ordinary edit does not undo explicit reorder');
$remaining = array_slice($reordered, 1);
editorCheck($model->updateImages(1, json_encode($remaining)), 'Explicit association removal succeeds');
for ($i=0; $i<2; $i++) $model->update(1, ProductEditor::fields([], $stale, []));
editorCheck(json_decode($db->query('SELECT images FROM products WHERE id=1')->fetchColumn(), true) === $remaining, 'Repeated stale submit cannot resurrect removed images');
$beforeInvalid = $db->query('SELECT * FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC);
foreach (['Unknown new condition', ['New']] as $invalid) {
    try { ProductEditor::fields(['condition_name'=>$invalid], ['condition_name'=>'Used'], []); throw new RuntimeException('Invalid condition accepted'); }
    catch (InvalidArgumentException $e) { $checks++; }
}
try { ProductEditor::fields([], ['condition_name'=>'Used', 'images'=>'{bad json'], ['/gallery/uploads/new.jpg']); throw new RuntimeException('Malformed gallery replaced'); }
catch (InvalidArgumentException $e) { $checks++; }
editorCheck($db->query('SELECT * FROM products WHERE id=1')->fetch(PDO::FETCH_ASSOC) === $beforeInvalid, 'Validation failures leave record untouched');
$db->exec("INSERT INTO products(id,condition_name) VALUES (2, ''), (3, NULL)");
editorCheck(in_array('', ProductEditor::catalogConditions($db), true), 'Catalog discovery preserves empty labels');
foreach ([PDO::ERRMODE_EXCEPTION, PDO::ERRMODE_SILENT] as $mode) {
    $broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>$mode]);
    try { ProductEditor::catalogConditions($broken); throw new LogicException('Catalog failure accepted'); }
    catch (RuntimeException $e) { editorCheck(strpos($e->getMessage(), 'no product changes saved') !== false, 'Catalog query failure blocks processing in both PDO modes'); }
}
$source = file_get_contents(__DIR__.'/../admin/products.php');
editorCheck(strpos($source, '$currentProduct = $editId ? $productModel->getById') < strpos($source, '// Handle image upload'), 'POST target loaded before upload processing');
editorCheck(strpos($source, 'ProductEditor::renderConditionOptions($product ?: null, $catalogConditionNames)') !== false, 'Actual form uses escaped condition rendering');
editorCheck(strpos($source, '$productData = array_merge($productData, $preservedFields)') !== false, 'Actual handler uses preserved fields');
editorCheck(strpos($source, "'images' => \$additionalImages") === false, 'Handler no longer unconditionally replaces gallery');
echo "Product editor preservation: $checks checks passed\n";
