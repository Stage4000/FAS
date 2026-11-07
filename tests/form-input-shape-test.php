<?php
/**
 * Source-only regression. Run: php -n tests/form-input-shape-test.php
 * Optional negative control: --source-root=/path/to/unpatched/repository
 * Never require/include an endpoint: only hash-allowlisted validation slices run.
 * Any validation change requires review and a deliberate fingerprint update.
 */
if (PHP_SAPI !== 'cli') { exit(2); }
error_reporting(E_ALL);
$root = dirname(__DIR__);
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--source-root=') !== 0) {
        fwrite(STDERR, "Unknown argument\n"); exit(2);
    }
    $root = substr($arg, strlen('--source-root='));
}

function extractValidation($root, $endpoint) {
    $specs = [
        'contact-form' => ['// Get POST data', '// Verify Turnstile if enabled', ['7732e909026c4435c15a760cf81f383ea973a8288436b95ee1f2207bcb101360', '9b25f4fd2e3f67aa5983e02c6533981062ae9302e43dab26a7bef3cf71f017d3']],
        'saved-search' => ["\n\n\$email = ", "try {\n    \$capture = new SavedSearchCapture", ['22ef3b36fbbbb1d569f64a3e6a13b1dd326bb97d71d1dba9c8e46c8a14418dd7', 'abfad7c6a6f6c2690dd05d69b09cf1c8cab47a2859fc287f57d26ab847d80aad']],
    ];
    [$start, $end, $allowed] = $specs[$endpoint];
    $source = file_get_contents($root . '/api/' . $endpoint . '.php');
    if ($source === false || substr_count($source, $start) !== 1 || substr_count($source, $end) !== 1) {
        throw new RuntimeException('Extraction markers changed: ' . $endpoint);
    }
    $a = strpos($source, $start); $b = strpos($source, $end);
    if ($b <= $a) { throw new RuntimeException('Invalid extraction bounds'); }
    $slice = substr($source, $a, $b - $a);
    if (!in_array(hash('sha256', $slice), $allowed, true)) {
        throw new RuntimeException('Unreviewed validation slice: ' . $endpoint);
    }
    // Only these reviewed slices are executable. Replace their exits, not validation.
    $expectedExits = 2;
    if (substr_count($slice, 'exit;') !== $expectedExits) {
        throw new RuntimeException('Exit guard changed');
    }
    return str_replace('exit;', 'throw new ValidationStopped();', $slice);
}
class ValidationStopped extends RuntimeException {}
function validateSlice($slice, $endpoint, $data) {
    $warnings = [];
    $hadPost = array_key_exists('_POST', $GLOBALS);
    $previousPost = $_POST ?? null;
    set_error_handler(function ($severity, $message) use (&$warnings) {
        $warnings[] = $message; return true;
    });
    http_response_code(200);
    ob_start();
    $values = null; $exception = null;
    try {
        // Synthetic process-local POST superglobal; restore it after each case.
        // No request is made and no complete endpoint is executed.
        $_POST = $data; $input = $data;
        eval($slice);
        $values = $endpoint === 'contact-form'
            ? compact('name', 'email', 'subject', 'message') : compact('email', 'consent');
    } catch (ValidationStopped $e) {
        // Preserve the production status and JSON, without ending the test process.
    } catch (Throwable $e) {
        $exception = get_class($e) . ': ' . $e->getMessage();
    } finally {
        $output = ob_get_clean(); restore_error_handler();
        if ($hadPost) { $_POST = $previousPost; } else { unset($GLOBALS['_POST']); }
    }
    return ['status' => http_response_code(), 'body' => $output === '' ? null : json_decode($output, true),
        'values' => $values, 'warnings' => $warnings, 'exception' => $exception];
}
function rejected($status, $key, $message) {
    return ['status' => $status, 'body' => ['success' => false, $key => $message],
        'values' => null, 'warnings' => [], 'exception' => null];
}
function accepted($values) {
    return ['status' => 200, 'body' => null, 'values' => $values, 'warnings' => [], 'exception' => null];
}
try {
    $slices = [];
    foreach (['contact-form', 'saved-search'] as $endpoint) {
        $slices[$endpoint] = extractValidation($root, $endpoint);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'HARNESS GUARD: ' . $e->getMessage() . "\n"); exit(2);
}
$cases = [];
$contact = ['name' => 'Jane Doe', 'email' => 'Jane@example.com', 'subject' => 'Inventory', 'message' => 'Please reply.'];
$required = rejected(400, 'message', 'All fields are required');
$invalidContact = rejected(400, 'message', 'Invalid email address');
$invalidSaved = rejected(422, 'error', 'Enter a valid email address.');
$noConsent = rejected(422, 'error', 'Consent is required for inventory notifications.');
$malformed = ['empty-array' => [], 'array' => ['x'], 'nested-array' => [['x']], 'null' => null,
    'false' => false, 'true' => true, 'zero' => 0, 'integer' => 123, 'float' => 1.5,
    'object' => (object)['x' => 'y']];
foreach (['name', 'email', 'subject', 'message'] as $field) {
    foreach ($malformed as $label => $value) {
        $data = $contact; $data[$field] = $value;
        $cases[] = ['contact-form', "$field/$label", $data, $required];
    }
    $data = $contact; unset($data[$field]);
    $cases[] = ['contact-form', "$field/missing", $data, $required];
    foreach (['', '0'] as $value) {
        $data = $contact; $data[$field] = $value;
        $cases[] = ['contact-form', "$field/empty-string-" . strlen($value), $data, $required];
    }
}
$cases[] = ['contact-form', 'valid-string-parity', $contact, accepted($contact)];
$data = ['name' => " José\r\n李 ", 'email' => 'Jane+tag@EXAMPLE.com', 'subject' => "Parts\r\nBcc: test", 'message' => "Héllo\r\n\0\x01\x08\x0B\x1F\x7F\t世界"];
$expected = ['name' => ' José李 ', 'email' => 'Jane+tag@EXAMPLE.com', 'subject' => 'PartsBcc: test', 'message' => "Héllo        \t世界"];
$cases[] = ['contact-form', 'cr-lf-controls-unicode', $data, accepted($expected)];
$data = $contact; $data['name'] = "A\t\x01B"; $data['subject'] = "C\t\x7FD";
$cases[] = ['contact-form', 'non-crlf-header-control-parity', $data, accepted($data)];
foreach (['invalid', "Jane@example.com\r\nBcc: x@y.com", ' Jane@example.com ', 'josé@example.com', "a\0b@example.com"] as $email) {
    $data = $contact; $data['email'] = $email;
    $cases[] = ['contact-form', 'invalid-email/' . bin2hex($email), $data, $invalidContact];
}
foreach ($malformed as $label => $value) {
    $cases[] = ['saved-search', "email/$label", ['email' => $value, 'consent' => true], $invalidSaved];
}
$cases[] = ['saved-search', 'email/missing', ['consent' => true], $invalidSaved];
foreach (['', 'invalid', 'josé@example.com', "a\0b@example.com", "a@b.com\r\nBcc:x@y.com"] as $email) {
    $cases[] = ['saved-search', 'invalid-email/' . bin2hex($email), ['email' => $email, 'consent' => true], $invalidSaved];
}
foreach (['Jane@EXAMPLE.com', " \t\r\nJane@EXAMPLE.com\0\x0B "] as $email) {
    $cases[] = ['saved-search', 'normalization/' . bin2hex($email), ['email' => $email, 'consent' => true], accepted(['email' => 'jane@example.com', 'consent' => true])];
}
foreach ([true, 1, '1', 'true', 'TRUE', 'on', 'yes', ' YES '] as $i => $consent) {
    $cases[] = ['saved-search', "consent-accepted/$i", ['email' => 'a@example.com', 'consent' => $consent], accepted(['email' => 'a@example.com', 'consent' => true])];
}
foreach ([false, null, 0, '0', '', 'false', 'off', 'no', 'invalid', 2, [], ['yes'], [['yes']]] as $i => $consent) {
    $cases[] = ['saved-search', "consent-rejected/$i", ['email' => 'a@example.com', 'consent' => $consent], $noConsent];
}
$cases[] = ['saved-search', 'consent-missing', ['email' => 'a@example.com'], $noConsent];
$cases[] = ['saved-search', 'email-rejection-precedes-consent', ['email' => 'bad', 'consent' => false], $invalidSaved];
$passed = 0; $failed = []; $details = [];
foreach ($cases as [$endpoint, $label, $data, $expected]) {
    $actual = validateSlice($slices[$endpoint], $endpoint, $data);
    if ($actual === $expected) { $passed++; }
    else {
        $failed[] = "$endpoint/$label";
        $details[] = 'FAIL ' . end($failed) . "\n" . json_encode(['expected' => $expected, 'actual' => $actual], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    }
}
echo implode('', $details);
echo json_encode(['source_root' => $root, 'php' => PHP_VERSION, 'passed' => $passed, 'total' => count($cases), 'failed' => $failed], JSON_PRETTY_PRINT) . "\n";
exit(count($failed) ? 1 : 0);
