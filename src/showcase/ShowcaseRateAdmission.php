<?php
declare(strict_types=1);
namespace FAS\Showcase;
/** Mandatory fixed-window quota. Existing private SQLite only; no observe mode. */
final class ShowcaseRateAdmission
{
 public const SCHEMA='CREATE TABLE showcase_rate (actor TEXT PRIMARY KEY NOT NULL CHECK(length(actor)=64), started INTEGER NOT NULL CHECK(started>=0), used INTEGER NOT NULL CHECK(used>0))';
 public const STATE_SCHEMA='CREATE TABLE showcase_rate_state (id INTEGER PRIMARY KEY CHECK(id=1), config TEXT NOT NULL, clock INTEGER NOT NULL, started INTEGER NOT NULL, used INTEGER NOT NULL)';
 private $global; private $binding; private $db; private $secret; private $limit; private $window; private $capacity; private $clock;
 public function __construct(string $path,array $publicRoots,string $secret,int $limit,int $window,int $capacity,int $globalLimit,callable $clock)
 {
  if($globalLimit<1 || $globalLimit>1000000 || strlen($secret)<32 || strlen($secret)>1024 || $limit<1 || $limit>10000 || $window<1 || $window>86400 || $capacity<1 || $capacity>100000 || !$publicRoots) throw new \InvalidArgumentException('Explicit bounded rate configuration required.');
  // POSIX deployment only. Protect parents against hostile replacement/hardlinks.
  if($path==='' || $path[0]!=='/' || strpos($path,chr(0))!==false || realpath($path)!==$path || !is_file($path) || is_link($path)) throw new \InvalidArgumentException('Canonical existing private quota database required.');
  for($part=$path;dirname($part)!==$part;$part=dirname($part))if(is_link($part))throw new \InvalidArgumentException('Linked path forbidden.');
  foreach($publicRoots as $root){if(!is_string($root)||$root===''||$root[0]!=='/'||($r=realpath($root))===false||!is_dir($r))throw new \InvalidArgumentException('Existing public roots required.');if(strpos($path,rtrim($r,'/').'/')===0)throw new \InvalidArgumentException('Public quota database forbidden.');}
  if(!defined('PDO::SQLITE_ATTR_OPEN_FLAGS')||!defined('PDO::SQLITE_OPEN_READWRITE'))throw new \RuntimeException('No-create SQLite flags required.');
  $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_STRINGIFY_FETCHES=>false,constant('PDO::SQLITE_ATTR_OPEN_FLAGS')=>constant('PDO::SQLITE_OPEN_READWRITE')]);
  $this->db->exec('PRAGMA synchronous=FULL');$this->db->exec('PRAGMA busy_timeout=3000');
  $this->global=$globalLimit;$this->binding=self::binding($secret,$limit,$window,$capacity,$globalLimit);$this->secret=$secret;$this->limit=$limit;$this->window=$window;$this->capacity=$capacity;$this->clock=$clock;$this->validate();
 }
 private function validate():void
 {
  $tables=$this->db->query("SELECT name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_KEY_PAIR);
  if($tables!==['showcase_rate'=>self::SCHEMA,'showcase_rate_state'=>self::STATE_SCHEMA])throw new \LogicException('Exact isolated quota schema required.');
  $state=$this->db->query('SELECT * FROM showcase_rate_state')->fetchAll(\PDO::FETCH_ASSOC);
  if(count($state)!==1 || $state[0]['id']!==1 || !hash_equals($this->binding,$state[0]['config']))throw new \LogicException('Provisioned quota configuration mismatch.');
  if(!in_array($this->db->query('PRAGMA journal_mode')->fetchColumn(),['delete','truncate','persist','wal'],true))throw new \LogicException('Durable journal required.');
 }
 public static function binding(string $secret,int $limit,int $window,int $capacity,int $globalLimit):string
 {return hash_hmac('sha256',json_encode([$limit,$window,$capacity,$globalLimit]),$secret);}
 /** True means one attempt has been atomically charged. Denials throw, never allow. */
 public function admit(string $actor):bool
 {
  if($actor===''||strlen($actor)>1024)throw new \InvalidArgumentException('Bounded trusted actor identity required.');
  $now=($this->clock)();if(!is_int($now)||$now<0||$now>PHP_INT_MAX-86400)throw new \LogicException('Trusted clock required.');
  $key=hash_hmac('sha256',$actor,$this->secret);$owned=false;
  try {
   $this->db->exec('BEGIN IMMEDIATE');$owned=true;$this->validate();
   $state=$this->db->query('SELECT * FROM showcase_rate_state WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
   foreach(['clock','started','used'] as $field)if(!is_int($state[$field])||$state[$field]<0)throw new \LogicException('Corrupt global quota.');
   if($state['started']>$state['clock']||$state['used']>$this->global)throw new \LogicException('Corrupt global quota.');
   $rows=$this->db->query('SELECT actor,started,used FROM showcase_rate LIMIT '.($this->capacity+1))->fetchAll(\PDO::FETCH_ASSOC);
   if(count($rows)>$this->capacity)throw new \LogicException('Corrupt quota cardinality.');
   foreach($rows as $row)if(!is_string($row['actor'])||!preg_match('/\A[a-f0-9]{64}\z/D',$row['actor'])||!is_int($row['started'])||$row['started']<0||$row['started']>$state['clock']||!is_int($row['used'])||$row['used']<1||$row['used']>$this->limit)throw new \LogicException('Corrupt actor quota.');
   if($now<$state['clock'] || $now<$state['started'] || $state['clock']<0 || $state['started']<0 || $state['used']<0)throw new \RuntimeException('Invalid quota clock/state.');
   $globalUsed=$state['started']<=$now-$this->window ? 0 : $state['used'];
   $globalStarted=$globalUsed===0 ? $now : $state['started'];
   if($globalUsed>=$this->global)throw new \OverflowException('Global quota exhausted.');
   $future=$this->db->prepare('SELECT COUNT(*) FROM showcase_rate WHERE started>?');$future->execute([$now]);if((int)$future->fetchColumn()>0)throw new \RuntimeException('Quota clock rollback.');
   $prune=$this->db->prepare('DELETE FROM showcase_rate WHERE started<=?');$prune->execute([$now-$this->window]);
   $q=$this->db->prepare('SELECT used FROM showcase_rate WHERE actor=?');$q->execute([$key]);$used=$q->fetchColumn();
   if($used!==false){if((int)$used >= $this->limit)throw new \OverflowException('Rate quota exhausted.');$this->db->prepare('UPDATE showcase_rate SET used=used+1 WHERE actor=?')->execute([$key]);}
   else {if((int)$this->db->query('SELECT COUNT(*) FROM showcase_rate')->fetchColumn()>=$this->capacity)throw new \OverflowException('Quota identity capacity exhausted.');$this->db->prepare('INSERT INTO showcase_rate(actor,started,used) VALUES(?,?,1)')->execute([$key,$now]);}
   $this->db->prepare('UPDATE showcase_rate_state SET clock=?,started=?,used=? WHERE id=1')->execute([$now,$globalStarted,$globalUsed+1]);
   $this->db->exec('COMMIT');$owned=false;return true;
  }finally{if($owned)$this->db->exec('ROLLBACK');}
 }
}
