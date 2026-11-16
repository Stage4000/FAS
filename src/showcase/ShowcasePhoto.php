<?php
declare(strict_types=1);

namespace FAS\Showcase;

/**
 * Offline photo-normalization building block. No HTTP upload or storage side effects.
 * A future controller must enforce request/auth/CSRF/rate limits before calling this.
 */
final class ShowcasePhoto
{
    public const MAX_INPUT_BYTES = 6291456;
    public const MAX_OUTPUT_BYTES = 8388608;
    public const MAX_PIXELS = 12000000;
    public const MAX_EDGE = 6000;

    private $jpeg;
    private $width;
    private $height;

    private function __construct(string $jpeg, int $width, int $height)
    {
        $this->jpeg = $jpeg;
        $this->width = $width;
        $this->height = $height;
    }

    /** Decode a bounded static JPEG/PNG and regenerate only its pixels as JPEG. */
    public static function fromBytes(string $bytes): self
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw new \InvalidArgumentException('Choose a photo no larger than 6 MiB.');
        }
        if (!extension_loaded('gd') || !extension_loaded('exif')) {
            throw new \RuntimeException('Photo processing is unavailable.');
        }

        $source = null;
        $canvas = null;
        $bufferLevel = ob_get_level();
        set_error_handler(static function (int $severity, string $message): bool {
            // Do not reflect parser warnings, filenames or embedded metadata to a user.
            throw new \InvalidArgumentException('This photo could not be read.');
        });
        try {
            $info = getimagesizefromstring($bytes);
            if (!is_array($info) || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                throw new \InvalidArgumentException('Choose a JPEG or PNG photo.');
            }
            $width = (int) $info[0];
            $height = (int) $info[1];
            if ($width < 1 || $height < 1 || $width > self::MAX_EDGE || $height > self::MAX_EDGE
                || $width > intdiv(self::MAX_PIXELS, $height)) {
                throw new \InvalidArgumentException('Choose a photo up to 12 megapixels and 6000 pixels per side.');
            }
            if ($info[2] === IMAGETYPE_PNG) {
                self::rejectAnimatedPng($bytes);
            }
            $orientation = $info[2] === IMAGETYPE_JPEG ? self::jpegOrientation($bytes) : 1;
            $source = imagecreatefromstring($bytes);
            if ($source === false) {
                throw new \InvalidArgumentException('This photo could not be read.');
            }
            if (imagesx($source) !== $width || imagesy($source) !== $height) {
                throw new \InvalidArgumentException('This photo has inconsistent dimensions.');
            }
            // EXIF orientation is applied before removing all metadata.
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                if (!imageflip($source, IMG_FLIP_HORIZONTAL)) {
                    throw new \RuntimeException('Photo processing failed.');
                }
            }
            $angle = [3 => 180, 4 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle !== 0) {
                $rotated = imagerotate($source, $angle, 0);
                if ($rotated === false) {
                    throw new \RuntimeException('Photo processing failed.');
                }
                $source = $rotated;
            }
            $width = imagesx($source);
            $height = imagesy($source);
            $canvas = imagecreatetruecolor($width, $height);
            if ($canvas === false || !imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, 0xFFFFFF)
                || !imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height)) {
                throw new \RuntimeException('Photo processing failed.');
            }
            ob_start();
            if (!imagejpeg($canvas, null, 85)) {
                throw new \RuntimeException('Photo processing failed.');
            }
            $jpeg = ob_get_clean();
            if (!is_string($jpeg) || $jpeg === '' || strlen($jpeg) > self::MAX_OUTPUT_BYTES) {
                throw new \InvalidArgumentException('Choose a smaller photo.');
            }
            return new self($jpeg, $width, $height);
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            restore_error_handler();
            // GD objects/resources are released by reference lifetime on PHP 7.4+.
            $source = null;
            $canvas = null;
        }
    }

    private static function jpegOrientation(string $bytes): int
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Photo processing failed.');
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !rewind($stream)) {
                throw new \RuntimeException('Photo processing failed.');
            }
            $metadata = exif_read_data($stream, 'IFD0', true, false);
            $orientation = is_array($metadata) ? ($metadata['IFD0']['Orientation'] ?? 1) : 1;
            if (!is_int($orientation) || $orientation < 1 || $orientation > 8) {
                throw new \InvalidArgumentException('This photo has invalid orientation metadata.');
            }
            return $orientation;
        } finally {
            fclose($stream);
        }
    }

    private static function rejectAnimatedPng(string $bytes): void
    {
        $offset = 8;
        $length = strlen($bytes);
        while ($offset <= $length - 12) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            if ($size > $length - $offset - 12) {
                throw new \InvalidArgumentException('This photo is incomplete.');
            }
            $type = substr($bytes, $offset + 4, 4);
            if (in_array($type, ['acTL', 'fcTL', 'fdAT'], true)) {
                throw new \InvalidArgumentException('Choose a still photo rather than an animation.');
            }
            if ($type === 'IEND') {
                return;
            }
            $offset += $size + 12;
        }
        throw new \InvalidArgumentException('This photo is incomplete.');
    }

    public function bytes(): string { return $this->jpeg; }

    /** Safe derivative metadata, never a client-controlled path or original filename. */
    public function metadata(): array
    {
        return [
            'sha256' => hash('sha256', $this->jpeg),
            'bytes' => strlen($this->jpeg),
            'mime' => 'image/jpeg',
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
