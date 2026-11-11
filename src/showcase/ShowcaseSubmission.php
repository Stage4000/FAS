<?php
declare(strict_types=1);

namespace FAS\Showcase;

/**
 * Immutable, non-persistent submission value. No endpoint, coupon, draw or publishing.
 * Caller supplies the server's reviewed consent text/version, never request fields.
 */
final class ShowcaseSubmission
{
    private $privateRecord;
    private $photo;

    private function __construct(array $record, ShowcasePhoto $photo)
    {
        $this->privateRecord = $record;
        $this->photo = $photo;
    }

    public static function prepare(array $input, ShowcasePhoto $photo, string $consentVersion, string $consentText): self
    {
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}\z/D', $consentVersion)
            || trim($consentText) === '' || strlen($consentText) > 10000
            || preg_match('//u', $consentText) !== 1) {
            throw new \LogicException('Reviewed server-side consent text and version are required.');
        }
        if (($input['consent'] ?? null) !== true) {
            throw new \InvalidArgumentException('Please confirm permission to use your photo.');
        }
        $name = self::text($input, 'display_name', 80, true);
        $caption = self::text($input, 'caption', 2000, false);
        $email = self::text($input, 'email', 254, true);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Enter a valid email address.');
        }
        // Status, consent text, terms, paths and incentives from the request are ignored.
        return new self([
            'display_name' => $name,
            'caption' => $caption,
            'contact_email' => $email,
            'status' => 'pending_review',
            'consent_version' => $consentVersion,
            'consent_text_sha256' => hash('sha256', $consentText),
            'photo' => $photo->metadata(),
        ], $photo);
    }

    private static function text(array $input, string $key, int $limit, bool $required): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value) || strlen($value) > $limit * 4 || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new \InvalidArgumentException('Check the ' . str_replace('_', ' ', $key) . ' field.');
        }
        $value = trim($value);
        $count = preg_match_all('/./us', $value);
        if ($count === false || $count > $limit || ($required && $value === '')) {
            throw new \InvalidArgumentException('Check the ' . str_replace('_', ' ', $key) . ' field.');
        }
        return $value;
    }

    /** Private storage handoff only; does not mean a submission has been saved. */
    public function privateRecord(): array { return $this->privateRecord; }
    public function photo(): ShowcasePhoto { return $this->photo; }
}
