<?php
declare(strict_types=1);

namespace FAS\Showcase;

/** Dormant, framework-free form fragment. No route, database or browser side effects. */
final class ShowcaseForm
{
    /**
     * action must be a trusted same-origin root-relative path. Consent and tokens
     * are server-owned. The caller supplies a bounded session form identity and
     * keeps it for safe retries. This fragment is not a complete launchable page.
     */
    public static function intake(string $action, string $csrf, string $formId, string $consentText, array $old = [], ?string $errorCode = null): string
    {
        if (!preg_match('~\A/[A-Za-z0-9][A-Za-z0-9_./-]{0,199}\z~D', $action) || strpos($action, '..') !== false
            || !preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $csrf) || !preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $formId)
            || trim($consentText) === '' || strlen($consentText) > 10000 || preg_match('//u', $consentText) !== 1) {
            throw new \LogicException('Trusted action, session tokens and reviewed permission wording are required.');
        }
        $errors = [
            'invalid_input' => 'Check the form fields and choose a JPEG or PNG photo again. No new submission has been confirmed.',
            'expired' => 'This form expired. Reload the page before trying again.',
            'conflict' => 'This form was already used with different details. Reload the page to begin a new submission.',
            'unavailable' => 'We could not confirm this submission. Keep this page and try again with the same details and photo.',
        ];
        if ($errorCode !== null && !isset($errors[$errorCode])) { throw new \InvalidArgumentException('Unknown form error.'); }
        $values = [];
        foreach (['display_name' => 320, 'email' => 1016, 'caption' => 8000] as $key => $limit) {
            $value = $old[$key] ?? '';
            $values[$key] = is_string($value) && strlen($value) <= $limit && preg_match('//u', $value) === 1 ? self::escape($value) : '';
        }
        $notice = $errorCode === null ? '' : '<p id="showcase-error" role="alert">' . self::escape($errors[$errorCode]) . '</p>';
        $describedBy = $errorCode === null ? '' : ' aria-describedby="showcase-error"';
        return '<section aria-labelledby="showcase-title"><h1 id="showcase-title">Share your ride or project</h1>'
            . '<p>Show us your powersports vehicle or project using Flip &amp; Strip parts. Your photo will be reviewed before it can appear on the site.</p>'
            . $notice . '<form method="post" enctype="multipart/form-data" action="' . self::escape($action) . '"' . $describedBy . '>'
            . '<input type="hidden" name="csrf_token" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="form_id" value="' . self::escape($formId) . '">'
            . '<p><label for="showcase-name">Display name (required)</label><input id="showcase-name" name="display_name" autocomplete="nickname" maxlength="80" required value="' . $values['display_name'] . '"></p>'
            . '<p><label for="showcase-email">Email (required, not displayed with your photo)</label><input id="showcase-email" name="email" type="email" autocomplete="email" maxlength="254" required value="' . $values['email'] . '"></p>'
            . '<p><label for="showcase-caption">Tell us about your ride or project</label><textarea id="showcase-caption" name="caption" maxlength="2000">' . $values['caption'] . '</textarea></p>'
            . '<p id="showcase-photo-help">One JPEG or PNG, up to 6 MiB, 12 megapixels and 6000 pixels per side. After an error, choose the same photo again to retry.</p>'
            . '<p><label for="showcase-photo">Photo (required)</label><input id="showcase-photo" name="photo" type="file" accept="image/jpeg,image/png" aria-describedby="showcase-photo-help" required></p>'
            . '<fieldset><legend>Photo permission</legend><p id="showcase-permission">' . nl2br(self::escape($consentText), false) . '</p>'
            . '<label for="showcase-consent"><input id="showcase-consent" name="consent" type="checkbox" value="1" aria-describedby="showcase-permission" required> I agree to the photo permission above (required).</label></fieldset>'
            . '<button type="submit">Submit photo for review</button></form></section>';
    }

    /** Only render after repository acceptance has returned a committed receipt. */
    public static function receipt(array $receipt): string
    {
        if (!is_string($receipt['id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $receipt['id'])
            || !in_array($receipt['status'] ?? null, ['pending_review','approved','rejected','withdrawn'], true)
            || !is_int($receipt['version'] ?? null) || $receipt['version'] < 1) {
            throw new \InvalidArgumentException('A committed submission receipt is required.');
        }
        // A retried request may return a later moderation state: never claim that
        // the record is still pending, approved for display or coupon-eligible.
        return '<section aria-labelledby="showcase-received"><h1 id="showcase-received">Your submission is saved</h1>'
            . '<p>Keep this reference: <code>' . self::escape($receipt['id']) . '</code>.</p>'
            . '<p>This receipt does not mean your photo is published.</p></section>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
