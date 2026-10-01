<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/growth.php';
try {
    $growth=fas_growth();$command=$argv[1]??'check';
    if($command==='check')echo json_encode(['sending_ready'=>$growth->ready(),'summary'=>$growth->summary()],JSON_PRETTY_PRINT).PHP_EOL;
    elseif($command==='prepare') {$growth->reconcile();echo "Cart status and retention checked. No emails sent.\n";}
    elseif($command==='send') {
        if(!in_array('--deliver',$argv,true))throw new RuntimeException('Sending requires --deliver and enabled email settings. Use check or prepare for a dry run.');
        $growth->reconcile();
        $result=$growth->sendBatch(static function(array $mail): bool {
            $headers=['From: Flip and Strip <'.$mail['from'].'>','Reply-To: '.$mail['reply_to'],
                'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: 8bit'];
            return mail($mail['to'],$mail['subject'],$mail['body'],implode("\r\n",$headers));
        });
        echo json_encode($result).PHP_EOL;
    } else throw new RuntimeException('Commands: check, prepare, send --deliver');
} catch(Throwable $e) {fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
