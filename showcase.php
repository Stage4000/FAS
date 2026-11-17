<?php
declare(strict_types=1);
// FAS root controller. No shipped runtime config; absent config is a closed route.
$baseLevel=ob_get_level();ob_start();
$response=['status'=>404,'headers'=>[],'body'=>'Not found.'];
$started=false;
try {
    $bootstrap=getenv('FAS_SHOWCASE_BOOTSTRAP');
    if(is_string($bootstrap) && $bootstrap!=='') {
        if(!in_array($_SERVER['HTTPS'] ?? '',['on','1'],true)) {
            $response=['status'=>403,'headers'=>[],'body'=>'A secure connection is required.'];
        } else {
            if(session_status()!==PHP_SESSION_NONE || headers_sent())throw new RuntimeException('Session already initialized.');
            // Deployment supplies trusted PHP configuration outside ALL public roots.
            $root=realpath(__DIR__);
            $path=realpath($bootstrap);
            if($path===false || $path!==$bootstrap || !is_file($path) || is_link($path)
              || substr($path,-4)!=='.php' || strpos($path,$root.DIRECTORY_SEPARATOR)===0) {
                throw new RuntimeException('Invalid private bootstrap.');
            }
            for($part=$path;dirname($part)!==$part;$part=dirname($part)) {
                if(is_link($part))throw new RuntimeException('Linked bootstrap.');
            }
            foreach(['Photo','Submission','Repository','Request','Form','Views','SessionForms','IntakeController','PrivateStorage','Concurrency','RateAdmission','IntakeApplication','IntakeRuntime'] as $module) {
                require_once __DIR__.'/src/showcase/Showcase'.$module.'.php';
            }
            $app=require $path;
            // Trusted bootstrap headers are not part of the application response.
            header_remove();
            if($app!==null) {
                if(!$app instanceof FAS\Showcase\ShowcaseIntakeApplication)throw new RuntimeException('Invalid runtime.');
                if(session_status()!==PHP_SESSION_NONE || headers_sent())throw new RuntimeException('Session already initialized.');
                session_name('FAS_SHOWCASE');
                if(!session_start(['use_strict_mode'=>1,'use_only_cookies'=>1,'cookie_secure'=>1,
                    'cookie_httponly'=>1,'cookie_samesite'=>'Lax','cookie_path'=>'/showcase.php','cache_limiter'=>'nocache'])) {
                    throw new RuntimeException('Session unavailable.');
                }
                $started=true;
                $response=$app->handle($_SERVER,$_POST,$_FILES,$_SESSION);
                if(!session_write_close())throw new RuntimeException('Session persistence failed.');
                $started=false;
            }
        }
    }
} catch(Throwable $error) {
    header_remove();
    $response=['status'=>503,'headers'=>[],'body'=>'Showcase is temporarily unavailable. Please try again later.'];
} finally {
    if($started && session_status()===PHP_SESSION_ACTIVE)session_abort();
    while(ob_get_level()>$baseLevel)ob_end_clean();
}
http_response_code($response['status']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
foreach($response['headers'] as $name=>$value)header($name.': '.$value);
// The controller returns trusted escaped fragments; supply document semantics here.
// No theme, scripts, third-party resources or publication decision is introduced.
echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>Share your ride or project | Flip &amp; Strip</title></head><body><main>'
    . $response['body'] . '</main></body></html>';
