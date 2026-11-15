<?php
require_once __DIR__.'/ManualLibrary.php';
require_once __DIR__.'/ManualByteRange.php';
function manualsH($value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function manualsConfig(): array {
    $config=require __DIR__.'/../config/config.php';
    if (!is_array($config)) throw new RuntimeException('Site configuration unavailable.');
    return is_array($config['manuals']??null)?$config['manuals']:[];
}
function manualsEnabled(): bool { return (manualsConfig()['public_enabled']??false)===true; }
function manualsStorage(): \FAS\Manuals\ManualStorage {
    $config=manualsConfig();
    return new \FAS\Manuals\ManualStorage(is_string($config['storage_path']??null)?$config['storage_path']:'',dirname(__DIR__,2));
}
function manualsDatabase(): PDO {
    $config=require __DIR__.'/../config/config.php';
    $path=$config['database']['path']??dirname(__DIR__,2).'/database/flipandstrip.db';
    if(!is_string($path)||!is_file($path))throw new RuntimeException('Existing application database is required.');
    require_once __DIR__.'/../config/Database.php';
    return \FAS\Config\Database::getInstance()->getConnection();
}
function manualsId(): int {
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id){http_response_code(404);exit('Manual not found.');}return $id;
}
function manualsStream(array $file,string $title): void {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','HEAD'],true)){header('Allow: GET, HEAD');http_response_code(405);exit;}
    // Callers have already applied the current release, rights and active-admin
    // gates. No session mutation follows; release its lock before the full hash.
    if(session_status()===PHP_SESSION_ACTIVE && !session_write_close())throw new RuntimeException('The administrator session could not be closed.');
    $fp=manualsStorage()->openVerified($file);
    try {
        $plan=\FAS\Manuals\ManualByteRange::plan($file['bytes'],$_SERVER['HTTP_RANGE']??null,$method,$_SERVER['HTTP_IF_RANGE']??null);
        if($plan['length']>0 && fseek($fp,$plan['offset'],SEEK_SET)!==0)throw new RuntimeException('The PDF could not be positioned.');
        // Content-Length and byte offsets describe the identity representation.
        // Discard PHP buffers and disable PHP compression before writing headers.
        if(headers_sent())throw new RuntimeException('The PDF response has already started.');
        ini_set('zlib.output_compression','0');
        if(!in_array(strtolower((string)ini_get('zlib.output_compression')),['','0','off'],true))throw new RuntimeException('PDF output compression must be disabled.');
        while(ob_get_level()>0)if(!ob_end_clean())throw new RuntimeException('The PDF output buffer could not be closed.');
        header_remove('Content-Encoding');header_remove('Content-Range');
        http_response_code($plan['status']);
        // Never hand an uploaded PDF to a browser's inline executable document context.
        header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="manual.pdf"');
        header('Accept-Ranges: bytes');header('Content-Length: '.$plan['length']);
        if($plan['content_range']!==null)header('Content-Range: '.$plan['content_range']);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'");header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
        if($method==='GET' && $plan['length']>0){
            try { \FAS\Manuals\ManualByteRange::copy($fp,$plan['length'],static function(string $chunk):void {echo $chunk;}); }
            // A failure after headers must never append an HTML/text error to PDF bytes.
            catch(Throwable $e){error_log('Manual PDF streaming interrupted: '.$e->getMessage());}
        }
    } finally { fclose($fp); }
    exit;
}
