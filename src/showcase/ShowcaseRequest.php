<?php
declare(strict_types=1);

namespace FAS\Showcase;

/**
 * Dormant HTTP boundary; installing this class creates no route or session.
 * All server, post and file arrays must be the real PHP request values.
 * Expected tokens, actor, policy and consent come from trusted server code only.
 */
final class ShowcaseRequest
{
    public const MAX_BODY_BYTES = ShowcasePhoto::MAX_INPUT_BYTES + 65536;
    private $repository;
    private $consentVersion;
    private $consentText;
    private $gate;

    /**
     * gate(action): bool must enforce enabled state, current authorization where
     * applicable, and rate/resource admission BEFORE photo decoding or storage.
     * No default allow policy. It must return literal true, not a truthy value.
     */
    public function __construct(ShowcaseRepository $repository, string $consentVersion, string $consentText, callable $gate)
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $consentVersion)
            || trim($consentText) === '' || strlen($consentText) > 10000 || preg_match('//u', $consentText) !== 1) {
            throw new \LogicException('Reviewed server-side consent is required.');
        }
        $this->repository = $repository;
        $this->consentVersion = $consentVersion;
        $this->consentText = $consentText;
        $this->gate = $gate;
    }

    /**
     * expectedForm is an opaque server-issued, session-bound form/retry identity.
     * Preserve it after success for lost-response retries; never use the submitted
     * form_id itself as this argument. The controller owns its bounded expiry and
     * lifecycle. Exact retries return the existing committed receipt.
     */
    public function consentText(): string { return $this->consentText; }
    public function consentVersion(): string { return $this->consentVersion; }

    public function submit(array $server, array $post, array $files, string $expectedCsrf, string $expectedForm): array
    {
        $this->guard($server, $post, $expectedCsrf, 'submit', self::MAX_BODY_BYTES, 'multipart/form-data');
        self::token($expectedForm);
        if (!is_string($post['form_id'] ?? null) || !hash_equals($expectedForm, $post['form_id'])) {
            throw new \InvalidArgumentException('The submission form expired. Reload it before trying again.');
        }
        self::fields($post, ['csrf_token','form_id','display_name','email','caption','consent']);
        if (($post['consent'] ?? null) !== '1') {
            throw new \InvalidArgumentException('Please confirm permission to use your photo.');
        }
        // Validate bounded text before allocating any decoded image.
        foreach (['display_name' => 320, 'email' => 1016, 'caption' => 8000] as $key => $limit) {
            if (!is_string($post[$key] ?? '') || strlen($post[$key] ?? '') > $limit) {
                throw new \InvalidArgumentException('Check the submission fields.');
            }
        }
        if (array_keys($files) !== ['photo'] || !is_array($files['photo'])) {
            throw new \InvalidArgumentException('Choose one JPEG or PNG photo.');
        }
        $upload = $files['photo'];
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_int($upload['size'] ?? null)
            || $upload['size'] < 1 || $upload['size'] > ShowcasePhoto::MAX_INPUT_BYTES
            || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            throw new \InvalidArgumentException('The photo upload did not complete. Choose a photo up to 6 MiB.');
        }
        // Client filename, extension, full_path and MIME never determine storage or decoding.
        $handle = @fopen($upload['tmp_name'], 'rb');
        if ($handle === false) { throw new \RuntimeException('The uploaded photo could not be read.'); }
        try {
            $stat = fstat($handle);
            if ($stat === false || $stat['size'] !== $upload['size']) {
                throw new \InvalidArgumentException('The uploaded photo length changed.');
            }
            $bytes = stream_get_contents($handle, ShowcasePhoto::MAX_INPUT_BYTES + 1);
            if (!is_string($bytes) || strlen($bytes) !== $upload['size']) {
                throw new \InvalidArgumentException('The uploaded photo is incomplete.');
            }
        } finally { fclose($handle); }
        $photo = ShowcasePhoto::fromBytes($bytes);
        $input = ['display_name' => $post['display_name'] ?? '', 'email' => $post['email'] ?? '',
            'caption' => $post['caption'] ?? '', 'consent' => true];
        $submission = ShowcaseSubmission::prepare($input, $photo, $this->consentVersion, $this->consentText);
        return $this->repository->accept($submission, $this->consentText, $expectedForm);
    }

    /** actorId is resolved from the current authenticated session, NEVER the POST. */
    public function moderate(array $server, array $post, string $expectedCsrf, int $actorId): array
    {
        $this->guard($server, $post, $expectedCsrf, 'moderate', 16384, 'application/x-www-form-urlencoded');
        self::fields($post, ['csrf_token','id','version','photo_sha256','consent_text_sha256','decision','reason']);
        foreach (['id','version','photo_sha256','consent_text_sha256','decision','reason'] as $key) {
            if (!is_string($post[$key] ?? null)) { throw new \InvalidArgumentException('A complete review snapshot is required.'); }
        }
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/D', $post['version'])) {
            throw new \InvalidArgumentException('A valid review version is required.');
        }
        $version = filter_var($post['version'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - 1]]);
        if ($version === false) { throw new \InvalidArgumentException('A valid review version is required.'); }
        return $this->repository->moderate($post['id'], $actorId, $version, $post['photo_sha256'],
            $post['consent_text_sha256'], $post['decision'], $post['reason']);
    }

    private function guard(array $server, array $post, string $expectedCsrf, string $action, int $limit, string $contentType): void
    {
        if (($server['REQUEST_METHOD'] ?? null) !== 'POST') {
            throw new \InvalidArgumentException('Use the submission form to send this request.');
        }
        $length = $server['CONTENT_LENGTH'] ?? null;
        if (!is_string($length) || !preg_match('/\A[1-9][0-9]{0,7}\z/D', $length) || (int)$length > $limit) {
            throw new \InvalidArgumentException('The request size is missing or too large.');
        }
        $type = $server['CONTENT_TYPE'] ?? null;
        if (!is_string($type) || strlen($type) > 1024 || strtolower(trim(explode(';', $type, 2)[0])) !== $contentType) {
            throw new \InvalidArgumentException('The request format is not supported.');
        }
        self::token($expectedCsrf);
        if (!is_string($post['csrf_token'] ?? null) || !hash_equals($expectedCsrf, $post['csrf_token'])) {
            throw new \InvalidArgumentException('The form expired. Reload it before trying again.');
        }
        if (($this->gate)($action) !== true) {
            throw new \RuntimeException('Showcase requests are not available right now.');
        }
    }

    private static function token(string $token): void
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $token)) {
            throw new \LogicException('A valid server-issued session token is required.');
        }
    }

    private static function fields(array $post, array $allowed): void
    {
        if (array_diff(array_keys($post), $allowed)) {
            throw new \InvalidArgumentException('The form contains unsupported fields.');
        }
    }
}
