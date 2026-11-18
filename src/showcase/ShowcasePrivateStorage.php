<?php
declare(strict_types=1);
namespace FAS\Showcase;
/** Dormant existing-file factory. Deployment must protect file AND parent directories
 * against untrusted rename/link creation; this is not an OS sandbox or ACL verifier.
 */
final class ShowcasePrivateStorage
{
 public static function open(string $path, array $publicRoots, callable $authorize, int $maxSubmissions, int $maxPhotoBytes): ShowcaseRepository
 {
  return self::connect($path,$publicRoots,$authorize,$maxSubmissions,$maxPhotoBytes,false);
 }
 public static function openReadOnly(string $path, array $publicRoots): ShowcaseRepository
 {
  return self::connect($path,$publicRoots,static function(): bool { return false; },1,1,true);
 }
 private static function connect(string $path, array $publicRoots, callable $authorize, int $maxSubmissions, int $maxPhotoBytes, bool $readOnly): ShowcaseRepository
 {
  if (!$publicRoots) throw new \InvalidArgumentException('Explicit public roots required.');
  self::absolute($path);
  clearstatcache(true);
  $real=realpath($path);
  if ($real===false || !is_file($real) || is_link($path) || !is_readable($real) || (!$readOnly && !is_writable($real))) {
   throw new \InvalidArgumentException('An existing private database with required file access is required.');
  }
  // Reject aliases, traversal and linked ancestors; only the canonical spelling is accepted.
  if (self::normalized($real)!==self::normalized($path)) throw new \InvalidArgumentException('Canonical non-linked database path required.');
  for ($part=$real; dirname($part)!==$part; $part=dirname($part)) {
   if (is_link($part)) throw new \InvalidArgumentException('Linked database paths are not allowed.');
  }
  foreach ($publicRoots as $root) {
   if (!is_string($root)) throw new \InvalidArgumentException('Invalid public root.');
   self::absolute($root); $resolved=realpath($root);
   if ($resolved===false || !is_dir($resolved)) throw new \InvalidArgumentException('Existing public roots required.');
   $prefix=rtrim(self::normalized($resolved),'/').'/';
   if (strpos(self::normalized($real),$prefix)===0) throw new \InvalidArgumentException('Database must be outside public roots.');
  }
  if (!defined('PDO::SQLITE_ATTR_OPEN_FLAGS') || !defined('PDO::SQLITE_OPEN_READWRITE') || !defined('PDO::SQLITE_OPEN_READONLY')) throw new \RuntimeException('SQLite existing-file open flags required.');
  // Deliberately omit CREATE in both modes. A disappearance cannot create an empty DB.
  $db=new \PDO('sqlite:'.$real,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
   \PDO::ATTR_STRINGIFY_FETCHES=>false,\PDO::ATTR_PERSISTENT=>false,
   constant('PDO::SQLITE_ATTR_OPEN_FLAGS')=>constant($readOnly ? 'PDO::SQLITE_OPEN_READONLY' : 'PDO::SQLITE_OPEN_READWRITE')]);
  if ($readOnly) $db->exec('PRAGMA query_only=ON');
  $db->exec('PRAGMA foreign_keys=ON');
  $db->exec('PRAGMA synchronous=FULL');
  $db->exec('PRAGMA busy_timeout=3000');
  return new ShowcaseRepository($db,$authorize,$maxSubmissions,$maxPhotoBytes);
 }
 private static function absolute(string $path): void
 {
  if ($path==='' || strpos($path,chr(0))!==false || strpos($path,'?')!==false || strpos($path,'#')!==false
   || (DIRECTORY_SEPARATOR==='\\' ? !preg_match('~\A[A-Za-z]:[\\\\/]~',$path) : $path[0]!=='/')
   || strpos(substr($path,DIRECTORY_SEPARATOR==='\\' ? 2 : 0),':')!==false) {
   throw new \InvalidArgumentException('An absolute non-URI local path is required.');
  }
 }
 private static function normalized(string $path): string
 {
  $path=str_replace('\\','/',$path);
  return DIRECTORY_SEPARATOR==='\\' ? strtolower($path) : $path;
 }
}
