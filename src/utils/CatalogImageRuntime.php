<?php
declare(strict_types=1);
namespace FAS\Utils;

require_once __DIR__.'/CatalogImageOutbox.php';

/** Enqueue-only admin integration. Never migrates, claims, fetches or activates. */
final class CatalogImageRuntime
{
    public static function outbox(\PDO $db): ?CatalogImageOutbox
    {
        // Read only process configuration, never request parameters/cookies.
        $enabled = getenv('FAS_ADMIN_IMAGE_OUTBOX');
        if ($enabled === false || $enabled === '' || $enabled === '0') return null;
        if ($enabled !== '1') {
            throw new \RuntimeException('FAS_ADMIN_IMAGE_OUTBOX must be unset, 0 or 1.');
        }
        // Strict existing v2 schema check happens before any Product initialization/write.
        return new CatalogImageOutbox($db);
    }
}
