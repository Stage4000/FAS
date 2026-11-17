<?php
declare(strict_types=1);
// Shared dormant entry for four fixed admin wrappers. No shipped runtime enables it.
$baseLevel=ob_get_level();ob_start();
$response=['status'=>404,'headers'=>[],'body'=>'Not found.'];
try {
    if(!isset($showcaseAdminEndpoint) || !in_array($showcaseAdminEndpoint,['queue','detail','photo','moderate'],true))throw new RuntimeException('Invalid endpoint.');
    $bootstrap=getenv('FAS_SHOWCASE_ADMIN_BOOTSTRAP');
    if(is_string($bootstrap) && $bootstrap!=='') {
        // Reject insecure transport BEFORE evaluating runtime or opening a session.
        if(!in_array($_SERVER['HTTPS']??'',['on','1'],true)) {
            $response=['status'=>403,'headers'=>[],'body'=>'A secure connection is required.'];
        } else {
            if(session_status()!==PHP_SESSION_NONE || headers_sent())throw new RuntimeException('Unexpected session state.');
            $root=realpath(dirname(__DIR__,2));$path=realpath($bootstrap);
            if($path===false || $path!==$bootstrap || !is_file($path) || substr($path,-4)!=='.php'
              || strpos($path,$root.DIRECTORY_SEPARATOR)===0)throw new RuntimeException('Invalid private bootstrap.');
            for($part=$path;dirname($part)!==$part;$part=dirname($part))if(is_link($part))throw new RuntimeException('Linked bootstrap.');
            foreach(['Photo','Submission','Repository','Request','Views','AdminAuthorization','AdminController','PrivateStorage','AdminRuntime'] as $module)require_once __DIR__.'/Showcase'.$module.'.php';
            // Trusted private bootstrap initializes existing current admin authentication,
            // locked native session, repository with identical authorization, and server CSRF.
            $runtime=require $path;
            if($runtime!==null) {
                if(!is_array($runtime) || array_diff(array_keys($runtime),['controller','csrf'])
                  || !($runtime['controller']??null) instanceof FAS\Showcase\ShowcaseAdminController
                  || !is_string($runtime['csrf']??null) || !preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D',$runtime['csrf'])
                  || session_status()!==PHP_SESSION_ACTIVE)throw new RuntimeException('Invalid runtime.');
                foreach(['session.use_strict_mode','session.use_only_cookies','session.cookie_secure','session.cookie_httponly'] as $setting)if(ini_get($setting)!=='1')throw new RuntimeException('Unsafe session configuration.');
                if(!in_array(strtolower((string)ini_get('session.cookie_samesite')),['lax','strict'],true))throw new RuntimeException('Unsafe cookie configuration.');
                $response=$runtime['controller']->handle($showcaseAdminEndpoint,$_SERVER,$_GET,$_POST,$runtime['csrf']);
                if(!session_write_close())throw new RuntimeException('Session persistence failed.');
            }
        }
    }
} catch(Throwable $error) {
    $response=['status'=>503,'headers'=>[],'body'=>'Photo review is temporarily unavailable.'];
} finally {
    if(session_status()===PHP_SESSION_ACTIVE)session_abort();
    while(ob_get_level()>$baseLevel)ob_end_clean();
}
// Runtime bootstrap must not emit output or redirects; do not leak incidental Location.
header_remove('Location');
http_response_code($response['status']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
foreach($response['headers'] as $name=>$value)header($name.': '.$value);
// HTML review views get document semantics; binary photos and redirect bodies
// remain exact. This does not introduce theme resources or change authorization.
if (in_array($showcaseAdminEndpoint ?? null, ['queue','detail','moderate'], true)
    && $response['status'] !== 303) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Review customer photos | Flip &amp; Strip</title></head><body><main>'
        . $response['body'] . '</main></body></html>';
} else {
    echo $response['body'];
}
