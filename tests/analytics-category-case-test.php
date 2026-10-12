<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/utils/Analytics.php';
$checks=0;
function checkCategoryRevenue($ok,$message) { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$analytics=new FAS\Utils\Analytics($db); $analytics->ensureTables();
$now=gmdate('Y-m-d H:i:s');
$db->exec('CREATE TABLE products(id INTEGER PRIMARY KEY, ebay_store_cat1_name TEXT, category TEXT)');
$db->exec('CREATE TABLE orders(id INTEGER PRIMARY KEY, created_at TEXT, payment_status TEXT)');
$db->exec('CREATE TABLE order_items(order_id INTEGER,product_id INTEGER,quantity INTEGER,total_price REAL)');
$db->exec("INSERT INTO products VALUES(1,'Other','other'),(2,'OTHER','other'),(3,'Other Parts','other')");
$insert=$db->prepare('INSERT INTO orders VALUES(?,?,?)');
foreach ([[1,$now,'completed'],[2,$now,'completed'],[3,$now,'pending'],[4,'2000-01-01','completed']] as $row) $insert->execute($row);
$db->exec('INSERT INTO order_items VALUES(1,1,1,10),(1,2,2,20),(2,3,1,25),(3,1,1,1000),(4,1,1,1000)');
$before=$db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$rows=$analytics->getRevenueByCategory(7,1);
checkCategoryRevenue(count($rows)===1 && (float)$rows[0]['revenue']===30.0,'Revenue groups equivalent category labels before limiting');
checkCategoryRevenue((int)$rows[0]['orders']===1 && (int)$rows[0]['units_sold']===3,'Mixed-case products in one order count one order and all units');
checkCategoryRevenue(count($analytics->getRevenueByCategory(7,10))===2,'Different category labels remain separate');
checkCategoryRevenue($db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'Reports leave catalog source labels unchanged');
$db->exec('DROP TABLE orders; DROP TABLE order_items');
$event=$db->prepare('INSERT INTO analytics_events(session_id,visitor_id,event_type,category,order_id,revenue,created_at,product_id,product_name,product_sku,manufacturer,quantity) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([['public','purchase_completed','Other','X1',10],['public','purchase_completed','OTHER','X1',20],['public','purchase_completed','Other Parts','X2',25],['admin','purchase_completed','OTHER','X3',1000]] as $row) {
    $event->execute([$row[0],'visitor',$row[1],$row[2],$row[3],$row[4],$now,null,null,null,null,1]);
}
$session=$db->prepare("INSERT INTO analytics_sessions(session_id,visitor_id,is_admin_session,started_at,last_seen_at) VALUES('admin','visitor',1,?,?)");
$session->execute([$now,$now]);
$rows=$analytics->getRevenueByCategory(7,1);
checkCategoryRevenue(count($rows)===1 && (float)$rows[0]['revenue']===30.0 && (int)$rows[0]['orders']===1,'Event fallback groups equivalent labels and retains admin exclusion');
foreach ([['Other','Honda','SKU-a',1],['OTHER','HONDA','SKU-a',2],['Other','Honda','SKU-A',1]] as $row) {
    $event->execute(['cart','visitor','add_to_cart',$row[0],null,0,$now,'product-1','Fixture part',$row[2],$row[1],$row[3]]);
}
$method=(new ReflectionClass($analytics))->getMethod('getAbandonedCartProducts'); $method->setAccessible(true);
$cart=$method->invoke($analytics,'cart');
checkCategoryRevenue(count($cart)===2,'Cart report groups case-only category/brand labels, preserving exact SKU distinctions');
checkCategoryRevenue((int)array_values(array_filter($cart,static fn($row)=>$row['product_sku']==='SKU-a'))[0]['quantity']===2,'Cart report keeps the existing maximum quantity rule');
echo "PASS $checks analytics category case assertions\n";
