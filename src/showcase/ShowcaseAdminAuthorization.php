<?php
declare(strict_types=1);
namespace FAS\Showcase;
/** Bridge to the existing app AdminAuth. Include only after trusted app bootstrap.
 * Does not start sessions, initialize account storage, redirect or render output.
 */
final class ShowcaseAdminAuthorization
{
    private $auth;
    public function __construct(\AdminAuth $auth) { $this->auth = $auth; }
    /** Resolve from the live locked server session, never a submitted actor ID. */
    public function actor(): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE || $this->auth->isLoggedIn() !== true) {
            throw new \RuntimeException('Current administrator authorization is required.');
        }
        $id = $_SESSION['admin_id'] ?? null;
        if (is_int($id) && $id > 0) return $id;
        if (is_string($id) && preg_match('/\A[1-9][0-9]*\z/D', $id)) {
            $valid = filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($valid !== false) return $valid;
        }
        throw new \RuntimeException('A current administrator identity is required.');
    }
    /** Repository calls again inside its write transaction. Never cache result. */
    public function __invoke(int $actorId, string $action, array $snapshot): bool
    {
        if ($actorId < 1 || !in_array($action, ['list','review','moderate'], true)) return false;
        try { return $this->actor() === $actorId; }
        catch (\Throwable $error) { return false; }
    }
}
