<?php
/**
 * Timezone utility helpers.
 *
 * The database stores most operational timestamps as UTC-ish SQL strings.
 * Display code should pass stored values through this helper so the viewer sees
 * times in their browser timezone when available, falling back to site timezone.
 */

namespace FAS\Utils;

class Timezone
{
    private const COOKIE_NAME = 'fas_user_timezone';
    private const DEFAULT_TIMEZONE = 'America/Chicago';

    public static function apply(): void
    {
        date_default_timezone_set(self::siteTimezone());
    }

    public static function siteTimezone(): string
    {
        static $timezone = null;

        if ($timezone !== null) {
            return $timezone;
        }

        $timezone = self::DEFAULT_TIMEZONE;
        $configPath = __DIR__ . '/../config/config.php';
        $resolvedPath = realpath($configPath) ?: $configPath;

        if (file_exists($resolvedPath)) {
            try {
                $config = require $resolvedPath;
                $configured = $config['site']['timezone'] ?? '';
                if (self::isValidTimezone($configured)) {
                    $timezone = $configured;
                }
            } catch (\Throwable $e) {
                $timezone = self::DEFAULT_TIMEZONE;
            }
        }

        return $timezone;
    }

    public static function userTimezone(): string
    {
        $cookieTimezone = $_COOKIE[self::COOKIE_NAME] ?? '';

        return self::isValidTimezone($cookieTimezone)
            ? $cookieTimezone
            : self::siteTimezone();
    }

    public static function cookieName(): string
    {
        return self::COOKIE_NAME;
    }

    public static function isValidTimezone($timezone): bool
    {
        return is_string($timezone)
            && $timezone !== ''
            && in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }

    public static function toUserDateTime($value, string $format = 'M j, Y g:i A', ?string $fallback = null): string
    {
        $dateTime = self::parse($value);

        if (!$dateTime) {
            return $fallback ?? (string)$value;
        }

        $dateTime = $dateTime->setTimezone(new \DateTimeZone(self::userTimezone()));

        return $dateTime->format($format);
    }

    public static function toUserDate($value, string $format = 'M j, Y', ?string $fallback = null): string
    {
        return self::toUserDateTime($value, $format, $fallback);
    }

    public static function toUserIso($value): ?string
    {
        $dateTime = self::parse($value);

        if (!$dateTime) {
            return null;
        }

        $dateTime = $dateTime->setTimezone(new \DateTimeZone(self::userTimezone()));

        return $dateTime->format(\DateTimeInterface::ATOM);
    }

    public static function toUtcTimestamp($value): ?int
    {
        $dateTime = self::parse($value);

        return $dateTime ? $dateTime->getTimestamp() : null;
    }

    public static function toUserInput($value): string
    {
        $dateTime = self::parse($value);

        if (!$dateTime) {
            return '';
        }

        $dateTime = $dateTime->setTimezone(new \DateTimeZone(self::userTimezone()));

        return $dateTime->format('Y-m-d\TH:i');
    }

    public static function fromUserInput($value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        try {
            $dateTime = new \DateTimeImmutable($raw, new \DateTimeZone(self::userTimezone()));
            return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function timestampElement($value, string $format = 'datetime', array $attributes = []): string
    {
        $dateTime = self::parse($value);

        if (!$dateTime) {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }

        $displayFormat = match ($format) {
            'date' => 'M j, Y',
            'time' => 'g:i A',
            default => 'M j, Y g:i A',
        };
        $attributes = array_merge([
            'class' => 'fas-local-time',
            'datetime' => $dateTime->format(\DateTimeInterface::ATOM),
            'data-format' => $format,
        ], $attributes);

        $attributeHtml = '';
        foreach ($attributes as $name => $attributeValue) {
            if ($attributeValue === null || $attributeValue === '') {
                continue;
            }
            $attributeHtml .= ' ' . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8')
                . '="' . htmlspecialchars((string)$attributeValue, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<time' . $attributeHtml . '>'
            . htmlspecialchars(self::toUserDateTime($value, $displayFormat), ENT_QUOTES, 'UTF-8')
            . '</time>';
    }

    private static function parse($value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        try {
            if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $raw)) {
                return new \DateTimeImmutable($raw);
            }

            return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            $timestamp = strtotime($raw);
            if ($timestamp === false) {
                return null;
            }

            return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
        }
    }
}
