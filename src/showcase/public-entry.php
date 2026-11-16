<?php
declare(strict_types=1);
// Dormant, sessionless public transport. No bootstrap ships or route is enabled.
$baseLevel=ob_get_level(); ob_start();
$response=['status'=>404,'headers'=>[],'body'=>'Not found.'];
try {
    if (!isset($showcasePublicEndpoint) || !in_array($showcasePublicEndpoint,['gallery','photo'],true)) throw new RuntimeException('Invalid endpoint.');
    $bootstrap=getenv('FAS_SHOWCASE_PUBLIC_BOOTSTRAP');
    if (is_string($bootstrap) && $bootstrap!=='') {
        if (!in_array($_SERVER['HTTPS']??'',['on','1'],true)) {
            $response=['status'=>403,'headers'=>[],'body'=>'A secure connection is required.'];
        } else {
            if (session_status()!==PHP_SESSION_NONE || headers_sent()) throw new RuntimeException('Unexpected session state.');
            $root=realpath(dirname(__DIR__,2)); $path=realpath($bootstrap);
            if ($path===false || $path!==$bootstrap || !is_file($path) || substr($path,-4)!=='.php'
                || strpos($path,$root.DIRECTORY_SEPARATOR)===0) throw new RuntimeException('Invalid private bootstrap.');
            for ($part=$path;dirname($part)!==$part;$part=dirname($part)) if (is_link($part)) throw new RuntimeException('Linked bootstrap.');
            foreach (['Photo','Submission','Repository','Views','PrivateStorage','PublicController','PublicRuntime'] as $module) require_once __DIR__.'/Showcase'.$module.'.php';
            // Trusted private bootstrap returns null (closed) or an explicitly gated controller.
            // It must not start sessions, write storage, emit/flush output or change response state.
            $controller=require $path;
            if (session_status()!==PHP_SESSION_NONE) throw new RuntimeException('Public runtime must be sessionless.');
            if ($controller!==null) {
                if (!$controller instanceof FAS\Showcase\ShowcasePublicController) throw new RuntimeException('Invalid public controller.');
                $response=$controller->handle($showcasePublicEndpoint,$_SERVER,$_GET,$_POST);
            }
        }
    }
} catch (Throwable $error) {
    $response=['status'=>503,'headers'=>[],'body'=>'Customer photos are temporarily unavailable.'];
} finally {
    if (session_status()===PHP_SESSION_ACTIVE) session_abort();
    while (ob_get_level()>$baseLevel) ob_end_clean();
}
// No bootstrap cookies, redirects, caching or incidental headers may leak.
header_remove();
http_response_code($response['status']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store'); header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; frame-ancestors 'none'; base-uri 'none'");
foreach ($response['headers'] as $name=>$value) header($name.': '.$value);
// Only gallery HTML receives document semantics. Photo bytes and their length
// must remain untouched, including the controller's image response headers.
if (($showcasePublicEndpoint ?? null) === 'gallery') {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Customer rides and projects | Flip &amp; Strip</title></head><body><main>'
        . $response['body'] . '</main></body></html>';
} else {
    echo $response['body'];
}
