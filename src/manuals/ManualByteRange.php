<?php
declare(strict_types=1);
namespace FAS\Manuals;

/** Single-range response planning, with no headers, filesystem or authorization side effects. */
final class ManualByteRange
{
    public const CHUNK_BYTES = 65536;

    /**
     * HEAD ignores Range, as required by RFC 9110. No validators are advertised:
     * any If-Range therefore falls back to the complete representation.
     * Unsupported, malformed and multipart range requests receive an empty 416.
     */
    public static function plan(int $size, ?string $range, string $method = 'GET', ?string $ifRange = null): array
    {
        if ($size < 1) throw new \InvalidArgumentException('A nonempty PDF is required.');
        $full = ['status'=>200, 'offset'=>0, 'length'=>$size, 'content_range'=>null];
        if ($method !== 'GET' || $range === null || $ifRange !== null) return $full;
        $invalid = ['status'=>416, 'offset'=>0, 'length'=>0, 'content_range'=>'bytes */'.$size];
        if (strlen($range) > 256 || !preg_match('/\Abytes=([0-9]*)-([0-9]*)\z/iD', trim($range, " \t"), $m)) return $invalid;
        if ($m[1] === '' && $m[2] === '') return $invalid;

        // Compare decimal strings before casting. Huge range values cannot wrap,
        // round through floating point, or escape the verified file's bounds.
        $bounded = static function (string $digits) use ($size): int {
            $digits = ltrim($digits, '0');
            if ($digits === '') return 0;
            $ceiling = (string)$size;
            if (strlen($digits) > strlen($ceiling) || (strlen($digits) === strlen($ceiling) && strcmp($digits, $ceiling) >= 0)) return $size;
            return (int)$digits;
        };
        if ($m[1] === '') {
            $length = $bounded($m[2]);
            if ($length === 0) return $invalid;
            $start = $size - $length;
            $end = $size - 1;
        } else {
            $start = $bounded($m[1]);
            if ($start >= $size) return $invalid;
            $end = $m[2] === '' ? $size - 1 : min($bounded($m[2]), $size - 1);
            if ($end < $start) return $invalid;
            $length = $end - $start + 1;
        }
        return ['status'=>206, 'offset'=>$start, 'length'=>$length, 'content_range'=>'bytes '.$start.'-'.$end.'/'.$size];
    }

    /** Emit exactly length bytes from the current position, using at most 64 KiB per read. */
    public static function copy($input, int $length, callable $write): void
    {
        if (!is_resource($input) || get_resource_type($input) !== 'stream' || $length < 0) throw new \InvalidArgumentException('Invalid PDF stream bounds.');
        while ($length > 0) {
            // An I/O notice or warning must not become PDF bytes when PHP's
            // display_errors is enabled. Limit conversion to the read itself;
            // the caller's handler is restored before writing or propagating.
            set_error_handler(static function (int $severity, string $message): void {
                throw new \RuntimeException('The PDF stream could not be read.');
            }, E_WARNING | E_NOTICE);
            try {
                $chunk = fread($input, min(self::CHUNK_BYTES, $length));
            } finally {
                restore_error_handler();
            }
            if ($chunk === false || $chunk === '') throw new \RuntimeException('The PDF stream ended unexpectedly.');
            $write($chunk);
            $length -= strlen($chunk);
        }
    }
}
