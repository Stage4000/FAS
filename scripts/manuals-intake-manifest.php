<?php
declare(strict_types=1);
require_once __DIR__.'/../src/manuals/ManualLibrary.php';
require_once __DIR__.'/../src/manuals/ManualIntakeManifest.php';
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
try {
    $raw=stream_get_contents(STDIN,33554433);
    if ($raw===false || strlen($raw)>33554432) throw new RuntimeException('Invalid input.');
    $input=\FAS\Manuals\ManualIntakeManifest::decodeInput($raw);
    if (!is_array($input) || !is_array($input['inventory'] ?? null) || !is_string($input['metadata_csv'] ?? null)) throw new RuntimeException('Invalid input.');
    $result=\FAS\Manuals\ManualIntakeManifest::prepare($input['inventory'],$input['metadata_csv']);
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit($result['ready_for_review']?0:2);
} catch (Throwable $e) {
    echo "{\"version\":1,\"error\":\"invalid_inventory_or_metadata\",\"draft_only\":true}\n";exit(2);
}
