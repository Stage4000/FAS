<?php
declare(strict_types=1);
/** Offline pure-function cohort. Requires only the target ClientIp class, never application bootstrap. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = $argv[1] ?? __DIR__.'/../../src/security/ClientIp.php';
$corpusRaw = stream_get_contents(STDIN);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require $target;
use FAS\Security\ClientIp;
$corpus = json_decode($corpusRaw, true, 512, JSON_THROW_ON_ERROR);
$checks = 0; $failures = []; $groups = [];
$check = static function (bool $ok, string $label, $expected, $actual) use (&$checks, &$failures): void {
    ++$checks;
    if (!$ok) $failures[] = ['label'=>$label,'expected'=>$expected,'actual'=>$actual];
};
// Refuse inherited proxy configuration rather than changing it or granting accidental trust.
if (ClientIp::proxies() !== []) {
    fwrite(STDERR, "Refusing to run: this pure fixed-allowlist cohort requires FAS_TRUSTED_PROXY_CIDRS absent or empty.\n");
    exit(2);
}
$check(ClientIp::CLOUDFLARE === $corpus['allowlist'], 'fixed pinned source allowlist', $corpus['allowlist'], ClientIp::CLOUDFLARE);
foreach ($corpus['cases'] as $case) {
    $group = $case['group'];
    $groups[$group] = ($groups[$group] ?? 0) + 1;
    try {
        switch ($case['op']) {
            case 'normalize': $actual = ClientIp::normalize(...$case['args']); break;
            case 'inRanges': $actual = ClientIp::inRanges(...$case['args']); break;
            case 'resolve': $actual = ClientIp::resolve(...$case['args']); break;
            default: throw new RuntimeException('Unknown corpus operation');
        }
    } catch (Throwable $e) {
        $actual = ['exception'=>get_class($e),'message'=>$e->getMessage()];
    }
    $check($actual === $case['expected'], $group.': '.$case['label'], $case['expected'], $actual);
}
// JSON arrays cannot represent PHP objects. Object values must never become IPs.
foreach ([new stdClass(), new class { public function __toString(): string { return '173.245.48.1'; } }] as $value) {
    $check(ClientIp::normalize($value) === '', 'object normalization rejected', '', ClientIp::normalize($value));
    $actual = ClientIp::resolve(['REMOTE_ADDR'=>$value, 'HTTP_CF_CONNECTING_IP'=>'203.0.113.77']);
    $expected = ['ip'=>'unknown','peer'=>'','source'=>'REMOTE_ADDR'];
    $check($actual === $expected, 'object peer never trusted', $expected, $actual);
}
// Invalid UTF-8 input never reaches output as an address.
foreach (["192.0.2.1\xff", "\xc0\xaf173.245.48.1", "2001:db8::1\xed\xa0\x80"] as $value) {
    $check(ClientIp::normalize($value) === '', 'invalid UTF-8 rejected', '', ClientIp::normalize($value));
    $actual = ClientIp::resolve(['REMOTE_ADDR'=>'173.245.48.1','HTTP_CF_CONNECTING_IP'=>$value]);
    $expected = ['ip'=>'173.245.48.1','peer'=>'173.245.48.1','source'=>'REMOTE_ADDR'];
    $check($actual === $expected, 'invalid UTF-8 header falls back', $expected, $actual);
}
$report = ['target'=>realpath($target), 'target_sha256'=>hash_file('sha256',$target),
    'corpus_sha256'=>hash('sha256',$corpusRaw), 'pinned_main'=>$corpus['pin'], 'php'=>PHP_VERSION,
    'checks'=>$checks,'passed'=>$checks-count($failures),'failed'=>count($failures),
    'groups'=>$groups,'failure_samples'=>array_slice($failures,0,30)];
echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
exit($failures ? 1 : 0);
