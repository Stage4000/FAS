<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$lines = file(__DIR__ . '/../.htaccess', FILE_IGNORE_NEW_LINES);
$sets = []; $conditions = [];
foreach ($lines as $line) {
    $line = trim($line);
    if (preg_match('/^RewriteCond %\{(REQUEST_METHOD|THE_REQUEST)\} (\S+)(.*)$/', $line, $m)) {
        $conditions[] = [$m[1], '~'.$m[2].'~'.(str_contains($m[3], 'NC') ? 'i' : '')];
    } elseif (str_starts_with($line, 'RewriteRule ')) {
        if (count($conditions) && preg_match('/^RewriteRule (\S+) (\S+) \[R=301,L\]$/', $line, $m)) $sets[] = [$conditions,'~'.$m[1].'~',$m[2]];
        $conditions = [];
    }
}
function destination($method,$uri,$internalPath=null) {
    global $sets;
    $path=$internalPath ?? ltrim(parse_url($uri,PHP_URL_PATH),'/');
    $vars=['REQUEST_METHOD'=>$method,'THE_REQUEST'=>$method.' '.$uri.' HTTP/1.1'];
    foreach ($sets as [$conditions,$pattern,$target]) {
        $matches=true;
        foreach ($conditions as [$name,$regex]) if (!preg_match($regex,$vars[$name])) $matches=false;
        if ($matches && preg_match($pattern,$path)) {
            $query=parse_url($uri,PHP_URL_QUERY);
            return preg_replace($pattern,$target,$path).($query!==null?'?'.$query:'');
        }
    }
    return null;
}
$checks=0;
foreach (['GET','HEAD'] as $method) {
    foreach (['/index.php'=>'/','/products.php?search=rotor&page=2'=>'/products?search=rotor&page=2','/about.php'=>'/about','/contact.php?topic=fitment'=>'/contact?topic=fitment','/cart.php'=>'/cart','/checkout.php'=>'/checkout','/products/'=>'/products','/products/motorcycle/make/honda/?page=2'=>'/products/motorcycle/make/honda?page=2'] as $url=>$target) {
        if (destination($method,$url)!==$target) throw new RuntimeException($url); $checks++;
    }
}
foreach (['/api/products.php','/api/paypal-capture-order.php','/admin/products.php','/google-merchant-feed.php','/product.php?id=1','/public/js/main.js','/sitemap.php'] as $url) {
    if (destination('GET',$url)!==null) throw new RuntimeException($url); $checks++;
}
foreach (['/index.php','/products.php?search=rotor','/contact.php','/checkout.php','/products/'] as $url) {
    if (destination('POST',$url)!==null) throw new RuntimeException($url); $checks++;
}
if (destination('GET','/products/motorcycle','products.php')!==null) throw new RuntimeException('Internal rewrite loop'); $checks++;
echo "PASS $checks redirect PCRE/guard checks (not an Apache integration test)\n";
