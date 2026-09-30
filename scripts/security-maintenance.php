<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/security/SecurityStore.php';
use FAS\Security\SecurityStore;
try {
    $command = $argv[1] ?? 'check';
    $store = SecurityStore::open();
    switch ($command) {
        case 'init':
        case 'check':
            echo json_encode(['storage'=>'healthy','path'=>SecurityStore::path(),'active'=>$store->active(),
                'proxy_cidrs'=>\FAS\Security\ClientIp::proxies(),'summary'=>$store->summary()],JSON_PRETTY_PRINT).PHP_EOL;
            break;
        case 'activate':
            if (!in_array('--verified',$argv,true)) throw new RuntimeException('First verify private storage, HTTP client-IP diagnostics, checkout recovery, and a single shared origin. Then run activate --verified.');
            $store->activate(true);
            echo "Enforcement activated; observation counters cleared.\n";
            break;
        case 'observe':
            $store->activate(false); echo "Global observation enabled.\n"; break;
        case 'prune':
            $store->prune(); echo "One bounded cleanup batch completed.\n"; break;
        case 'reset-rules':
            $store->resetRules('',0); echo "Default rules restored.\n"; break;
        case 'unblock':
            $store->unblock($argv[2] ?? '', '', 0); echo "IP block and associated counters cleared.\n"; break;
        default:
            throw new RuntimeException('Commands: init, check, activate --verified, observe, prune, reset-rules, unblock IP');
    }
} catch (Throwable $e) {
    fwrite(STDERR,"Security maintenance: ".$e->getMessage().PHP_EOL); exit(1);
}
