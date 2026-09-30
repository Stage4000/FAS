<?php
declare(strict_types=1);
require_once __DIR__.'/../src/security/SecurityStore.php';

function fas_security_store(): \FAS\Security\SecurityStore
{
    static $store;
    return $store ?? ($store = \FAS\Security\SecurityStore::open());
}
function fas_security_ip(): array { return \FAS\Security\ClientIp::resolve($_SERVER); }

function fas_security_check(array $subjects, bool $recovery = false): array
{
    try {
        $result = fas_security_store()->check($subjects, fas_security_ip()['ip'], $recovery);
        return $result + ['status'=>$result['allowed'] ? 200:429];
    } catch (Throwable $e) {
        // No database path, credentials or request data in public responses or logs.
        return ['allowed'=>$recovery,'status'=>$recovery?200:503,'retry_after'=>30,'rule'=>'storage'];
    }
}
function fas_security_event(string $rule, string $outcome, int $actor = 0): void
{
    try { fas_security_store()->event(fas_security_ip()['ip'],$rule,$outcome,'',$actor); } catch (Throwable $e) {}
}
function fas_security_message(array $result): string
{
    return $result['status'] === 503 ? 'This action is temporarily unavailable. Try again in 30 seconds.'
        : 'Too many attempts. Try again in '.$result['retry_after'].' seconds.';
}
function fas_security_headers(array $result): void
{
    http_response_code($result['status']);
    header('Retry-After: '.$result['retry_after']);
    header('Cache-Control: private, no-store');
}
function fas_security_deny(array $result, bool $telemetry = false): void
{
    if ($telemetry && $result['status'] === 503) { http_response_code(204); exit; }
    fas_security_headers($result);
    header('Content-Type: application/json; charset=utf-8');
    $message = fas_security_message($result);
    echo json_encode(['success'=>false,'ok'=>false,'valid'=>false,'error'=>$message,'message'=>$message,
        'code'=>$result['status']===429?'rate_limited':'temporarily_unavailable','retry_after'=>$result['retry_after']]);
    exit;
}
function fas_security_guard(string $rule, bool $recovery = false, bool $telemetry = false): void
{
    $result = fas_security_check([$rule=>fas_security_ip()['ip']],$recovery);
    if (!$result['allowed']) fas_security_deny($result,$telemetry);
}
function fas_security_body(int $limit = 65536): string
{
    static $raw = null;
    if ($raw === null) $raw = file_get_contents('php://input',false,null,0,$limit+1);
    // Multipart bodies may already have been consumed by PHP.
    if ($raw === false || strlen($raw)>$limit || (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>$limit
        || strlen(http_build_query($_POST))>$limit || !empty($_FILES)) {
        http_response_code(413); header('Content-Type: application/json');
        echo json_encode(['success'=>false,'ok'=>false,'error'=>'Request is too large.','message'=>'Request is too large.']);
        exit;
    }
    return $raw;
}
