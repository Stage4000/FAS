<?php
declare(strict_types=1);
namespace FAS\Showcase;
/** Dormant token pool. Caller supplies an exclusively locked, server-owned session bucket.
 * Never populate this bucket from request data. No session_start or global state here.
 */
final class ShowcaseSessionForms
{
    private $state;
    private $clock;
    private $ttl;
    private $capacity;
    public function __construct(array &$state, int $ttl, int $capacity, callable $clock)
    {
        if ($ttl < 1 || $ttl > 86400 || $capacity < 1 || $capacity > 20) {
            throw new \InvalidArgumentException('Explicit bounded TTL and capacity required.');
        }
        $this->state =& $state;
        $this->ttl=$ttl; $this->capacity=$capacity; $this->clock=$clock;
    }
    /** GET only, after feature/rate admission. Full pools fail rather than evict retries. */
    public function issue(): array
    {
        $now=$this->now(); $this->validate();
        $live=array_filter($this->state, static function ($entry) use ($now): bool { return $entry['expires_at']>$now; });
        if (count($live)>=$this->capacity) { throw new \OverflowException('Too many active forms. Retry an existing form or wait for expiry.'); }
        do { $id=bin2hex(random_bytes(32)); } while (isset($live[$id]));
        $entry=['csrf_token'=>bin2hex(random_bytes(32)), 'issued_at'=>$now, 'expires_at'=>$now+$this->ttl];
        $live[$id]=$entry; $this->state=$live;
        return ['form_id'=>$id]+$entry;
    }
    /** POST lookup only. Never issues, consumes, extends expiry or changes the session.
     * On successful submit retain identity for exact repository retries until expiry.
     */
    public function resolve($submittedId): array
    {
        $now=$this->now(); $this->validate();
        if (!is_string($submittedId) || !preg_match('/\A[a-f0-9]{64}\z/D',$submittedId)
            || !isset($this->state[$submittedId]) || $this->state[$submittedId]['expires_at']<=$now
            || $this->state[$submittedId]['issued_at']>$now) {
            throw new \InvalidArgumentException('This form expired. Reload it before trying again.');
        }
        return ['form_id'=>$submittedId]+$this->state[$submittedId];
    }
    private function now(): int
    {
        $now=($this->clock)();
        if (!is_int($now) || $now<0 || $now>PHP_INT_MAX-86400) { throw new \LogicException('A valid trusted clock is required.'); }
        return $now;
    }
    private function validate(): void
    {
        if (count($this->state)>20) { throw new \LogicException('Invalid session form pool.'); }
        foreach ($this->state as $id=>$entry) {
            if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/D',$id) || !is_array($entry)
                || array_keys($entry)!==['csrf_token','issued_at','expires_at']
                || !is_string($entry['csrf_token']) || !preg_match('/\A[a-f0-9]{64}\z/D',$entry['csrf_token'])
                || !is_int($entry['issued_at']) || !is_int($entry['expires_at']) || $entry['issued_at']<0
                || $entry['expires_at']<=$entry['issued_at'] || $entry['expires_at']-$entry['issued_at']>86400) {
                throw new \LogicException('Invalid session form pool.');
            }
        }
    }
}
