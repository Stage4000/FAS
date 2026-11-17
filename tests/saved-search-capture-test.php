<?php

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require_once __DIR__ . '/../src/utils/SavedSearchCapture.php';

use FAS\Utils\SavedSearchCapture;

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$capture = new SavedSearchCapture($db);
$valid = ['email' => ' Parts@EXAMPLE.com '];
$checks = 0;

function verifySavedSearch(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function latestSavedSearch(PDO $db): array
{
    return $db->query('SELECT * FROM saved_search_leads ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
}

foreach ([
    ['email' => []], ['email' => 'invalid address'], ['label' => ['parts']],
    ['search_query' => "\xFF"], ['collection' => 42], ['context' => ['page_url' => "\xFF"]],
] as $bad) {
    $rejected = false;
    try {
        $capture->record($bad + $valid);
    } catch (InvalidArgumentException | JsonException) {
        $rejected = true;
    }
    verifySavedSearch($rejected, 'Malformed helper input was accepted');
    verifySavedSearch(
        $db->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'saved_search_leads'")->fetchColumn() == 0,
        'Malformed input created the lead table'
    );
}

$limits = ['label' => 255, 'search_query' => 255, 'manufacturer' => 255, 'model' => 255,
    'category' => 255, 'collection' => 80];
foreach ($limits as $field => $limit) {
    foreach (['é', '車', '🏍'] as $character) {
        $fits = str_repeat('x', $limit - strlen($character)) . $character;
        verifySavedSearch($capture->record($valid + [$field => $fits . 'Z']), "Could not record $field");
        verifySavedSearch(latestSavedSearch($db)[$field] === $fits, "$field lost a character that fits");

        $prefix = str_repeat('x', $limit - strlen($character) + 1);
        verifySavedSearch($capture->record($valid + [$field => $prefix . $character]), "Could not record $field");
        verifySavedSearch(latestSavedSearch($db)[$field] === $prefix, "$field split a UTF-8 character");
    }
}

$url = '/products?search=' . str_repeat('parts', 80);
$context = ['page_url' => '/products?search=車', 'no_results' => true];
verifySavedSearch($capture->record($valid + [
    'label' => '  ', 'search_query' => '0', 'search_url' => $url, 'source_page' => null, 'context' => $context,
]), 'Legacy and optional values could not be recorded');
$row = latestSavedSearch($db);
verifySavedSearch($row['email'] === 'parts@example.com', 'Email normalization changed');
verifySavedSearch($row['label'] === null && $row['source_page'] === null, 'Blank and null values should remain null');
verifySavedSearch($row['search_query'] === '0' && $row['search_url'] === $url, 'Zero search or full URL was lost');
verifySavedSearch(json_decode($row['context_json'], true, 512, JSON_THROW_ON_ERROR) === $context,
    'UTF-8 request context did not round trip');

echo "PASS $checks saved-search capture checks\n";
