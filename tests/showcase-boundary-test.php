<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/showcase-foundation-test.php';

// Independent indexed transparency, EXIF endian, cleanup and actual dimension cases.
$indexed = imagecreate(16, 16);
$transparent = imagecolorallocate($indexed, 0, 0, 0);
imagecolortransparent($indexed, $transparent);
ob_start(); imagepng($indexed); $indexedBytes = ob_get_clean();
$decoded = imagecreatefromstring(FAS\Showcase\ShowcasePhoto::fromBytes($indexedBytes)->bytes());
check((imagecolorat($decoded, 3, 3) & 0xFFFFFF) === 0xFFFFFF, 'Indexed transparency');

$exif = "Exif\0\0MM" . pack('nN', 42, 8) . pack('n', 1)
    . pack('nnNn', 0x0112, 3, 1, 6) . "\0\0" . pack('N', 0);
$bigEndian = substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
check(FAS\Showcase\ShowcasePhoto::fromBytes($bigEndian)->metadata()['width'] === 80, 'Big endian orientation');

$compressedText = substr($png, 0, -12)
    . pngChunk('zTXt', "Comment\0\0" . gzcompress(str_repeat('X', 16 * 1024 * 1024)))
    . substr($png, -12);
try {
    $normalized = FAS\Showcase\ShowcasePhoto::fromBytes($compressedText);
    check(strpos($normalized->bytes(), str_repeat('X', 64)) === false, 'Ancillary text is absent from derivative');
    echo "Compressed ancillary text: normalized without retained text.\n";
} catch (InvalidArgumentException $e) {
    // Supported parsers may reject oversized ancillary chunks instead of discarding.
    check(true, 'Oversized ancillary text rejected');
    echo "Compressed ancillary text: rejected by this image runtime.\n";
}

$streamCount = count(get_resources('stream'));
for ($i = 0; $i < 25; ++$i) {
    // Valid image header reaches the EXIF stream before invalid orientation fails.
    try { FAS\Showcase\ShowcasePhoto::fromBytes(jpegWithOrientation($jpeg, 0)); }
    catch (InvalidArgumentException $e) { /* Expected parser rejection. */ }
}
check($streamCount === count(get_resources('stream')), 'No stream leak after repeated parse failures');

$large = imagecreatetruecolor(4000, 3000);
ob_start(); imagejpeg($large, null, 85); $largeJpeg = ob_get_clean();
$large = null;
$rotated = FAS\Showcase\ShowcasePhoto::fromBytes(jpegWithOrientation($largeJpeg, 6));
check($rotated->metadata()['width'] === 3000 && $rotated->metadata()['height'] === 4000, 'Actual 12 megapixel rotation');
echo "Customer showcase extended boundary checks: {$checks} total assertions passed.\n";
