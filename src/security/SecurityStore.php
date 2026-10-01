<?php
declare(strict_types=1);
namespace FAS\Security;
require_once __DIR__.'/ClientIp.php';

final class SecurityStore
{
    public \PDO $db;
    private $clock;
    private string $secret;

    public static function defaults(): array
    {
        return [
            'login_ip'=>['Admin login / IP',20,900],
            'login_pair'=>['Admin login / IP and username',5,900],
            'login_account'=>['Admin login / username',20,900],
            'reauth_account'=>['Password verification / account',5,900],
            'reauth_ip'=>['Password verification / IP',20,900],
            'settings_account'=>['Admin settings changes / account',10,300],
            'settings_ip'=>['Admin settings changes / IP',30,300],
            'admin_accounts'=>['Administrator account changes / account',10,300],
            'contact'=>['Contact submissions',5,600],
            'saved_search'=>['Saved search signups',10,3600],
            'shipping'=>['Shipping estimates and rates',60,300],
            'coupon'=>['Coupon validation',30,300],
            'address'=>['Address autofill',60,300],
            'product_check'=>['Product availability checks',240,60],
            'payment_create'=>['New payment attempts',10,600],
            'payment_recovery'=>['Payment completion and recovery / IP',120,60],
            'payment_attempt'=>['Apple Pay recovery / owned attempt',30,60],
            'analytics'=>['Analytics collection',120,60],
            'client_error'=>['Client error collection',30,60],
            'sync_auth'=>['Invalid sync authentication',20,900],
            'growth_session'=>['Signup and saved-cart session',60,300],
            'newsletter'=>['Newsletter signup / IP',5,600],
            'newsletter_email'=>['Newsletter confirmation / address',2,86400],
            'cart_save'=>['Saved-cart updates / IP',30,300],
            'cart_email'=>['Email my cart / address',3,86400],
            'growth_token'=>['Email confirmation and recovery links',60,300],
        ];
    }

    public static function path(): string
    {
        return getenv('FAS_SECURITY_DB_PATH') ?: dirname(__DIR__, 3).'/fas-private/security.sqlite';
    }

    public static function open(): self
    {
        $path = self::path();
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) throw new \RuntimeException('Security database path must be absolute.');
        $parent = dirname($path);
        // Resolve existing ancestors before creating anything (including symlink targets).
        $ancestor = $parent;
        while (!is_dir($ancestor) && dirname($ancestor) !== $ancestor) $ancestor = dirname($ancestor);
        foreach ([dirname(__DIR__, 2), $_SERVER['DOCUMENT_ROOT'] ?? ''] as $root) {
            $root = $root !== '' ? realpath($root) : false;
            $resolved = realpath($ancestor);
            if ($root && $resolved && self::within($resolved, $root)) {
                throw new \RuntimeException('Security database must be outside the public web root.');
            }
        }
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) throw new \RuntimeException('Cannot create private security directory.');
        $resolved = realpath($parent);
        foreach ([dirname(__DIR__, 2), $_SERVER['DOCUMENT_ROOT'] ?? ''] as $root) {
            if ($root !== '' && realpath($root) && self::within($resolved, realpath($root))) throw new \RuntimeException('Unsafe security directory.');
        }
        if (is_link($path)) throw new \RuntimeException('Security database must not be a symlink.');
        $previous = umask(0077);
        try {
            $db = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
            @chmod($path, 0600);
            return new self($db);
        } finally { umask($previous); }
    }

    private static function within(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\','/',$path),'/');
        $root = rtrim(str_replace('\\','/',$root),'/');
        if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || strpos($path, $root.'/') === 0;
    }

    public function __construct(\PDO $db, ?callable $clock = null)
    {
        $this->db = $db;
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $db->exec('PRAGMA busy_timeout=750');
        $this->clock = $clock ?: static function () { return time(); };
        $db->exec("CREATE TABLE IF NOT EXISTS security_meta (name TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS security_rules (rule TEXT PRIMARY KEY, capacity INTEGER NOT NULL, seconds INTEGER NOT NULL, mode TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS security_buckets (id TEXT PRIMARY KEY, rule TEXT NOT NULL, ip TEXT NOT NULL, tokens REAL NOT NULL, updated INTEGER NOT NULL, expires INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS security_bucket_expiry ON security_buckets(expires);
            CREATE TABLE IF NOT EXISTS security_blocks (ip TEXT PRIMARY KEY, expires INTEGER NOT NULL, reason TEXT NOT NULL, actor INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS security_events (id INTEGER PRIMARY KEY AUTOINCREMENT, time INTEGER NOT NULL, ip TEXT NOT NULL, rule TEXT NOT NULL, outcome TEXT NOT NULL, path TEXT NOT NULL, actor INTEGER NOT NULL, detail TEXT NOT NULL, count INTEGER NOT NULL DEFAULT 1, aggregate_key TEXT UNIQUE);
            CREATE INDEX IF NOT EXISTS security_event_time ON security_events(time);
            CREATE INDEX IF NOT EXISTS security_event_filter ON security_events(outcome,time);");
        $this->run("INSERT OR IGNORE INTO security_meta VALUES ('secret', ?)", [bin2hex(random_bytes(32))]);
        $this->run("INSERT OR IGNORE INTO security_meta VALUES ('active','0')");
        $this->secret = $this->run("SELECT value FROM security_meta WHERE name='secret'")->fetchColumn();
    }

    public function run(string $sql, array $args = []): \PDOStatement
    {
        $s = $this->db->prepare($sql); $s->execute($args); return $s;
    }

    public function now(): int { return (int)($this->clock)(); }
    public function active(): bool { return $this->run("SELECT value FROM security_meta WHERE name='active'")->fetchColumn() === '1'; }
    private function transaction(callable $work)
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try { $result = $work(); $this->db->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    public function rules(): array
    {
        $rules = [];
        foreach (self::defaults() as $id=>$d) $rules[$id] = ['label'=>$d[0],'capacity'=>$d[1],'seconds'=>$d[2],'mode'=>'enforce'];
        foreach ($this->run('SELECT * FROM security_rules')->fetchAll() as $r) {
            if (isset($rules[$r['rule']])) $rules[$r['rule']] = array_merge($rules[$r['rule']], $r);
        }
        return $rules;
    }

    /** All requested budgets are reserved together, never partially consumed on a denial. */
    public function check(array $subjects, string $ip, bool $recovery = false): array
    {
        return $this->transaction(function () use ($subjects, $ip, $recovery) {
            $now = $this->now(); $active = $this->active(); $rules = $this->rules();
            if (!$recovery) {
                $block = $this->run('SELECT expires FROM security_blocks WHERE ip=? AND expires>?', [$ip,$now])->fetchColumn();
                if ($block) {
                    $this->event($ip, 'manual', $active ? 'blocked':'observed', '', 0);
                    if ($active) return ['allowed'=>false,'retry_after'=>(int)$block-$now,'rule'=>'manual'];
                }
            }
            $updates = []; $wait = 0; $deniedRule = '';
            foreach ($subjects as $rule=>$subject) {
                if (!isset($rules[$rule])) throw new \InvalidArgumentException('Unknown security rule.');
                $r = $rules[$rule];
                $key = hash_hmac('sha256', $rule.'|'.$subject, $this->secret);
                $b = $this->run('SELECT * FROM security_buckets WHERE id=?', [$key])->fetch();
                $tokens = $b ? min($r['capacity'], (float)$b['tokens'] + max(0,$now-$b['updated'])*$r['capacity']/$r['seconds']) : (float)$r['capacity'];
                if ($tokens < 1) {
                    $enforce = $active && $r['mode'] === 'enforce';
                    $this->event($ip, $rule, $enforce ? 'throttled':'observed', '', 0);
                    if ($enforce) {
                        $delay = (int)ceil((1-$tokens)*$r['seconds']/$r['capacity']);
                        if ($delay > $wait) { $wait = $delay; $deniedRule = $rule; }
                    }
                }
                $updates[] = [$key,$rule,$ip,max(0,$tokens-1),$now,$now+(int)$r['seconds']];
            }
            if ($wait) return ['allowed'=>false,'retry_after'=>$wait,'rule'=>$deniedRule];
            foreach ($updates as $u) $this->run('INSERT OR REPLACE INTO security_buckets VALUES (?,?,?,?,?,?)', $u);
            // Bounded housekeeping; never scan request bodies or create an event for successful browsing.
            $this->run('DELETE FROM security_buckets WHERE id IN (SELECT id FROM security_buckets WHERE expires<=? LIMIT 100)', [$now]);
            return ['allowed'=>true,'retry_after'=>0,'rule'=>''];
        });
    }

    public function event(string $ip, string $rule, string $outcome, string $detail = '', int $actor = 0): void
    {
        $now = $this->now();
        // Route comes from the executed script, never REQUEST_URI or a query string.
        $path = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
        $aggregate = in_array($outcome, ['blocked','throttled','observed','login_failed'], true)
            ? hash('sha256', $ip.'|'.$rule.'|'.$outcome.'|'.intdiv($now,60)) : null;
        if ($aggregate && $this->run('UPDATE security_events SET count=count+1 WHERE aggregate_key=?', [$aggregate])->rowCount()) return;
        // Cap storage even if the maintenance command has not run.
        if ((int)$this->run('SELECT COUNT(*) FROM security_events')->fetchColumn() >= 100000) {
            $this->db->exec('DELETE FROM security_events WHERE id IN (SELECT id FROM security_events ORDER BY id LIMIT 500)');
        }
        $this->run('DELETE FROM security_events WHERE id IN (SELECT id FROM security_events WHERE time<? LIMIT 100)', [$now-2592000]);
        $this->run('INSERT INTO security_events(time,ip,rule,outcome,path,actor,detail,aggregate_key) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(aggregate_key) DO UPDATE SET count=count+1',
            [$now,ClientIp::normalize($ip) ?: 'unknown',substr($rule,0,50),$outcome,$path,$actor,substr(preg_replace('/[\x00-\x1f\x7f]/','',$detail),0,200),$aggregate]);
    }

    public function activate(bool $active): void
    {
        $this->transaction(function () use ($active) {
            $this->run("UPDATE security_meta SET value=? WHERE name='active'", [$active?'1':'0']);
            $this->db->exec('DELETE FROM security_buckets');
            $this->event('','system',$active?'activated':'deactivated','CLI');
        });
    }

    public function saveRule(string $rule, int $capacity, int $seconds, string $mode, string $ip, int $actor): void
    {
        if (!isset(self::defaults()[$rule]) || $capacity<1 || $capacity>10000 || $seconds<10 || $seconds>86400 || !in_array($mode,['enforce','observe'],true)) {
            throw new \InvalidArgumentException('Choose a valid rule, capacity (1–10,000), interval (10–86,400 seconds), and mode.');
        }
        $this->transaction(function () use ($rule,$capacity,$seconds,$mode,$ip,$actor) {
            $this->run('INSERT OR REPLACE INTO security_rules VALUES(?,?,?,?)',[$rule,$capacity,$seconds,$mode]);
            $this->run('DELETE FROM security_buckets WHERE rule=?',[$rule]);
            $this->event($ip,$rule,'rule_changed',"$capacity / $seconds seconds / $mode",$actor);
        });
    }

    public function resetRules(string $ip, int $actor): void
    {
        $this->transaction(function () use ($ip,$actor) {
            $this->db->exec('DELETE FROM security_rules; DELETE FROM security_buckets');
            $this->event($ip,'system','rules_reset','Defaults restored',$actor);
        });
    }

    public function block(string $target, int $seconds, string $reason, string $ip, int $actor): void
    {
        $target = ClientIp::normalize($target);
        if ($target === '' || $target === $ip || ClientIp::inRanges($target,array_merge(ClientIp::CLOUDFLARE,ClientIp::proxies()))
            || !in_array($seconds,[900,3600,86400],true) || trim($reason)==='' || strlen($reason)>200) {
            throw new \InvalidArgumentException('Enter an exact visitor IP, a reason up to 200 characters, and a supported duration. Your IP and trusted proxies cannot be blocked.');
        }
        $this->transaction(function () use ($target,$seconds,$reason,$ip,$actor) {
            $this->run('INSERT OR REPLACE INTO security_blocks VALUES(?,?,?,?)',[$target,$this->now()+$seconds,trim($reason),$actor]);
            $this->event($ip,'manual','block_added',$target.' / '.trim($reason),$actor);
        });
    }

    public function unblock(string $target, string $ip, int $actor): void
    {
        $target = ClientIp::normalize($target);
        if (!$target) throw new \InvalidArgumentException('Invalid IP.');
        $this->transaction(function () use ($target,$ip,$actor) {
            $this->run('DELETE FROM security_blocks WHERE ip=?',[$target]);
            $this->run('DELETE FROM security_buckets WHERE ip=?',[$target]);
            $this->event($ip,'manual','unblocked',$target,$actor);
        });
    }

    public function clearBucket(string $id, string $ip, int $actor): void
    {
        $this->transaction(function () use ($id,$ip,$actor) {
            $r = $this->run('SELECT rule,ip FROM security_buckets WHERE id=?',[$id])->fetch();
            if (!$r) throw new \InvalidArgumentException('That restriction has already expired.');
            $this->run('DELETE FROM security_buckets WHERE id=?',[$id]);
            $this->event($ip,$r['rule'],'counter_cleared',$r['ip'],$actor);
        });
    }

    public function summary(): array
    {
        $counts = ['login_failed'=>0,'throttled'=>0,'blocked'=>0,'observed'=>0];
        foreach ($this->run('SELECT outcome,SUM(count) AS total FROM security_events WHERE time>=? GROUP BY outcome',[$this->now()-86400])->fetchAll() as $r) {
            if (isset($counts[$r['outcome']])) $counts[$r['outcome']] = (int)$r['total'];
        }
        $counts['active_blocks'] = (int)$this->run('SELECT COUNT(*) FROM security_blocks WHERE expires>?',[$this->now()])->fetchColumn();
        return $counts;
    }

    public function restrictions(): array
    {
        $values = []; $args = [];
        foreach ($this->rules() as $id=>$r) {
            $values[] = '(?,?,?)'; array_push($args,$id,(int)$r['capacity'],(int)$r['seconds']);
        }
        $args[] = $this->now();
        return $this->run('WITH policy(rule,capacity,seconds) AS (VALUES '.implode(',',$values).')
            SELECT b.id,b.rule,b.ip,b.updated+(1-b.tokens)*p.seconds/p.capacity AS available
            FROM security_buckets b JOIN policy p ON p.rule=b.rule
            WHERE b.updated+(1-b.tokens)*p.seconds/p.capacity>CAST(? AS INTEGER) ORDER BY available DESC LIMIT 100',$args)->fetchAll();
    }

    public function prune(): void
    {
        $now = $this->now();
        $this->run('DELETE FROM security_buckets WHERE id IN (SELECT id FROM security_buckets WHERE expires<=? LIMIT 1000)',[$now]);
        $this->run('DELETE FROM security_blocks WHERE ip IN (SELECT ip FROM security_blocks WHERE expires<=? LIMIT 1000)',[$now]);
        $this->run('DELETE FROM security_events WHERE id IN (SELECT id FROM security_events WHERE time<? LIMIT 1000)',[$now-2592000]);
    }
}
