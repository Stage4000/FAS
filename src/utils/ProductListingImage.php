<?php
namespace FAS\Utils;

/** Display-only listing selection; never changes imported image references. */
final class ProductListingImage
{
    public static function forProduct(array $product): string
    {
        $primary = $product['image_url'] ?? null;
        if (is_string($primary) && trim($primary) !== '') {
            return self::normalize($primary);
        }

        $json = $product['images'] ?? null;
        $images = is_string($json) ? json_decode($json, true) : null;
        // Only accept the stored JSON list, not an object or scalar payload.
        if (is_array($images) && array_is_list($images) && str_starts_with(ltrim($json), '[')) {
            foreach ($images as $image) {
                if (is_string($image) && trim($image) !== '') {
                    return self::normalize($image);
                }
            }
        }

        return '/gallery/default.jpg';
    }

    private static function normalize(string $path): string
    {
        $path = trim($path);
        return preg_match('#^https?://#i', $path) ? $path : '/' . ltrim($path, '/');
    }
}
