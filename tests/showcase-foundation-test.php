<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/showcase/ShowcasePhoto.php';
require_once __DIR__ . '/../src/showcase/ShowcaseSubmission.php';

use FAS\Showcase\ShowcasePhoto;
use FAS\Showcase\ShowcaseSubmission;

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks;
}
function rejects(callable $operation, string $message): void {
    try { $operation(); } catch (InvalidArgumentException $e) { check(true, $message); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function pngChunk(string $type, string $data): string {
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}
function jpegWithOrientation(string $jpeg, int $orientation): string {
    $exif = "Exif\0\0II" . pack('vV', 42, 8) . pack('v', 1)
        . pack('vvVv', 0x0112, 3, 1, $orientation) . "\0\0" . pack('V', 0);
    return substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
}
function encodedFixture(string $type): string {
    $im = imagecreatetruecolor(120, 80);
    imagefilledrectangle($im, 0, 0, 59, 39, 0xFF0000);
    imagefilledrectangle($im, 60, 0, 119, 39, 0x00FF00);
    imagefilledrectangle($im, 0, 40, 59, 79, 0x0000FF);
    imagefilledrectangle($im, 60, 40, 119, 79, 0xFFFF00);
    ob_start();
    $type === 'png' ? imagepng($im) : ($type === 'gif' ? imagegif($im) : imagejpeg($im, null, 95));
    return ob_get_clean();
}

$jpeg = encodedFixture('jpeg');
$png = encodedFixture('png');
foreach ([$jpeg, $png] as $input) {
    $photo = ShowcasePhoto::fromBytes($input);
    $meta = $photo->metadata();
    check($meta['mime'] === 'image/jpeg', 'Canonical JPEG MIME');
    check($meta['width'] === 120 && $meta['height'] === 80, 'Dimensions preserved');
    check($meta['sha256'] === hash('sha256', $photo->bytes()), 'Exact derivative hash');
    check($meta['bytes'] === strlen($photo->bytes()), 'Exact derivative size');
    check(getimagesizefromstring($photo->bytes())[2] === IMAGETYPE_JPEG, 'Output decodes as JPEG');
}

$corners = [
    1 => [0xFF0000, 0x00FF00, 0x0000FF, 0xFFFF00],
    2 => [0x00FF00, 0xFF0000, 0xFFFF00, 0x0000FF],
    3 => [0xFFFF00, 0x0000FF, 0x00FF00, 0xFF0000],
    4 => [0x0000FF, 0xFFFF00, 0xFF0000, 0x00FF00],
    5 => [0xFF0000, 0x0000FF, 0x00FF00, 0xFFFF00],
    6 => [0x0000FF, 0xFF0000, 0xFFFF00, 0x00FF00],
    7 => [0xFFFF00, 0x00FF00, 0x0000FF, 0xFF0000],
    8 => [0x00FF00, 0xFFFF00, 0xFF0000, 0x0000FF],
];
foreach ($corners as $orientation => $expected) {
    $photo = ShowcasePhoto::fromBytes(jpegWithOrientation($jpeg, $orientation));
    $meta = $photo->metadata();
    $swap = $orientation >= 5;
    check($meta['width'] === ($swap ? 80 : 120) && $meta['height'] === ($swap ? 120 : 80), 'Oriented dimensions ' . $orientation);
    $im = imagecreatefromstring($photo->bytes());
    $coords = [[10, 10], [$meta['width'] - 11, 10], [10, $meta['height'] - 11], [$meta['width'] - 11, $meta['height'] - 11]];
    foreach ($coords as $i => [$x, $y]) {
        $actual = imagecolorat($im, $x, $y);
        foreach ([16, 8, 0] as $shift) {
            check(abs((($actual >> $shift) & 255) - (($expected[$i] >> $shift) & 255)) <= 15, 'Orientation pixel ' . $orientation . ':' . $i . ':' . $shift);
        }
    }
    check(strpos($photo->bytes(), 'Exif') === false, 'EXIF is not retained ' . $orientation);
}
foreach ([0, 9, 65535] as $orientation) {
    rejects(static function () use ($jpeg, $orientation) { ShowcasePhoto::fromBytes(jpegWithOrientation($jpeg, $orientation)); }, 'Invalid orientation');
}

$secret = 'SYNTHETIC_PRIVATE_GPS_OR_CAMERA_COMMENT';
$comment = "\xff\xfe" . pack('n', strlen($secret) + 2) . $secret;
$payload = substr($jpeg, 0, 2) . $comment . substr($jpeg, 2) . '<?php synthetic_payload(); ?>';
$clean = ShowcasePhoto::fromBytes($payload)->bytes();
check(strpos($clean, $secret) === false && strpos($clean, '<?php') === false, 'JPEG comments and trailing payload are removed');
$textPng = substr($png, 0, -12) . pngChunk('tEXt', 'Comment' . "\0" . $secret) . substr($png, -12);
check(strpos(ShowcasePhoto::fromBytes($textPng)->bytes(), $secret) === false, 'PNG text metadata removed');
$transparent = imagecreatetruecolor(2, 2);
imagealphablending($transparent, false);
imagesavealpha($transparent, true);
imagefill($transparent, 0, 0, imagecolorallocatealpha($transparent, 0, 0, 0, 127));
ob_start(); imagepng($transparent); $alpha = ob_get_clean();
$white = imagecreatefromstring(ShowcasePhoto::fromBytes($alpha)->bytes());
check((imagecolorat($white, 0, 0) & 0xFFFFFF) === 0xFFFFFF, 'Transparency becomes a white background');

foreach (['', 'not an image', '<svg xmlns="http://www.w3.org/2000/svg"/>', encodedFixture('gif'), substr($png, 0, 35), substr($jpeg, 0, 60), str_repeat('x', ShowcasePhoto::MAX_INPUT_BYTES + 1)] as $bad) {
    rejects(static function () use ($bad) { ShowcasePhoto::fromBytes($bad); }, 'Malformed or unsupported photo');
}
$animated = substr($png, 0, 33) . pngChunk('acTL', pack('NN', 2, 0)) . substr($png, 33);
rejects(static function () use ($animated) { ShowcasePhoto::fromBytes($animated); }, 'APNG rejected before decode');
foreach ([[6001, 1], [1, 6001], [4000, 4000], [0, 80]] as [$width, $height]) {
    $ihdr = pack('NN', $width, $height) . substr($png, 24, 5);
    $bomb = substr($png, 0, 8) . pngChunk('IHDR', $ihdr) . substr($png, 33);
    rejects(static function () use ($bomb) { ShowcasePhoto::fromBytes($bomb); }, 'Dimension cap before pixel decode');
}

$photo = ShowcasePhoto::fromBytes($jpeg);
$fields = ['display_name' => ' Rider ', 'email' => 'rider@example.invalid', 'caption' => 'My project', 'consent' => true];
$consent = 'Synthetic test permission, not approved customer wording.';
$submission = ShowcaseSubmission::prepare($fields + ['status' => 'approved', 'consent_version' => 'ATTACK', 'coupon' => 'FREE', 'photo_path' => '/etc/passwd'], $photo, 'fixture-v1', $consent);
$record = $submission->privateRecord();
check($record['status'] === 'pending_review', 'Cannot self-approve');
check($record['display_name'] === 'Rider', 'Display name is trimmed');
check($record['consent_version'] === 'fixture-v1', 'Server consent version wins');
check($record['consent_text_sha256'] === hash('sha256', $consent), 'Exact server text bound by hash');
check($record['photo'] === $photo->metadata(), 'Consent record binds derivative identity');
check($submission->photo()->bytes() === $photo->bytes(), 'Private storage handoff retains derivative');
check(!isset($record['coupon'], $record['photo_path']), 'No unreviewed incentive or path accepted');
$record['status'] = 'approved';
check($submission->privateRecord()['status'] === 'pending_review', 'Returned record cannot mutate object');
foreach ([false, 'true', '1', 1, [], null] as $value) {
    $bad = array_replace($fields, ['consent' => $value]);
    rejects(static function () use ($bad, $photo, $consent) { ShowcaseSubmission::prepare($bad, $photo, 'fixture-v1', $consent); }, 'Explicit boolean consent required');
}
foreach (['display_name', 'email', 'caption'] as $key) {
    foreach ([[], 123, "bad\0text", "bad\ntext", "bad\x7ftext", "\xff"] as $value) {
        $bad = array_replace($fields, [$key => $value]);
        rejects(static function () use ($bad, $photo, $consent) { ShowcaseSubmission::prepare($bad, $photo, 'fixture-v1', $consent); }, 'Malformed text ' . $key);
    }
}
foreach ([['display_name', ''], ['display_name', str_repeat('a', 81)], ['caption', str_repeat('a', 2001)], ['email', 'not-an-email'], ['email', ''], ['email', str_repeat('a', 255)]] as [$key, $value]) {
    $bad = array_replace($fields, [$key => $value]);
    rejects(static function () use ($bad, $photo, $consent) { ShowcaseSubmission::prepare($bad, $photo, 'fixture-v1', $consent); }, 'Text length/format ' . $key);
}
foreach ([['', $consent], ['bad/version', $consent], ['fixture-v1', ''], ['fixture-v1', "\xff"]] as [$version, $text]) {
    try { ShowcaseSubmission::prepare($fields, $photo, $version, $text); throw new RuntimeException('Expected config rejection'); }
    catch (LogicException $e) { check(true, 'Missing/invalid server consent configuration fails closed'); }
}
$unicode = array_replace($fields, ['display_name' => str_repeat('é', 80), 'caption' => '<script>inert plain text</script>']);
$record = ShowcaseSubmission::prepare($unicode, $photo, 'fixture-v1', $consent)->privateRecord();
check($record['display_name'] === str_repeat('é', 80), 'UTF-8 character limit rather than byte count');
check($record['caption'] === '<script>inert plain text</script>', 'Caption is plain data; future renderer must escape');
check(!method_exists($submission, 'publish') && !method_exists($submission, 'approve'), 'No public publishing/moderation bypass exists');

// Existing output buffering and error handling survive both normal and rejected input.
$sentinel = static function (): bool { return true; };
set_error_handler($sentinel);
ob_start(); echo 'before';
rejects(static function () { ShowcasePhoto::fromBytes('invalid'); }, 'Reject inside caller buffer');
ShowcasePhoto::fromBytes($jpeg);
echo 'after';
check(ob_get_clean() === 'beforeafter', 'No leaked image bytes and caller buffer preserved');
$restored = set_error_handler(static function (): bool { return true; });
check($restored === $sentinel, 'Caller error handler restored');
restore_error_handler(); restore_error_handler();

echo "Customer showcase foundation: {$checks} checks passed.\n";
