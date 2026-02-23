<?php
/**
 * Timezone Utility
 * Applies the site-configured timezone from config.php.
 */

namespace FAS\Utils;

class Timezone
{
    /**
     * Apply the site timezone from config.
     * Falls back to America/Chicago if not configured or config is missing.
     * A static flag prevents redundant re-application within the same request.
     */
    public static function apply(): void
    {
        static $applied = false;
        if ($applied) {
            return;
        }
        $applied = true;

        $tz = 'America/Chicago';

        $configPath = __DIR__ . '/../config/config.php';
        // Normalize to an absolute path so symlinks or re-locations resolve correctly
        $resolvedPath = realpath($configPath) ?: $configPath;
        if (file_exists($resolvedPath)) {
            try {
                $config = require $resolvedPath;
                $configured = $config['site']['timezone'] ?? '';
                if (!empty($configured)) {
                    $tz = $configured;
                }
            } catch (\Throwable $e) {
                // Silently fall back to default timezone
            }
        }

        // Validate the timezone identifier before applying it
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $tz = 'America/Chicago';
        }

        date_default_timezone_set($tz);
    }
}
