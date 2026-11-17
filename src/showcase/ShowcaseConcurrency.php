<?php
declare(strict_types=1);
namespace FAS\Showcase;
/** Host-local nonexpiring operation lease. All workers must use the same slots.
 * Preprovision immutable slot files in an OS-protected private directory.
 */
final class ShowcaseConcurrency
{
 private $paths; private $roots; private $rateGate;
 public const MARKER="FAS-SHOWCASE-SLOT-v1\n";
 public function __construct(array $paths, array $publicRoots, callable $rateGate)
 {
  if (!$paths || count($paths)>32 || !$publicRoots) throw new \InvalidArgumentException('Explicit bounded slots and public roots required.');
  $this->paths=$paths; $this->roots=$publicRoots; $this->rateGate=$rateGate;
 }
 public function execute(callable $operation)
 {
  $handles=[]; $lease=null;
  try {
   $roots=[];
   foreach ($this->roots as $root) {
    self::absolute($root); $real=realpath($root);
    if ($real===false || !is_dir($real)) throw new \LogicException('Invalid public root.');
    $roots[]=rtrim(self::norm($real),'/').'/';
   }
   $seen=[];
   foreach ($this->paths as $path) {
    self::absolute($path); clearstatcache(true,$path); $real=realpath($path);
    if ($real===false || self::norm($real)!==self::norm($path) || !is_file($path) || is_link($path)) throw new \LogicException('Existing canonical regular slots required.');
    for ($part=$path; dirname($part)!==$part; $part=dirname($part)) if(is_link($part)) throw new \LogicException('Linked slot forbidden.');
    foreach ($roots as $root) if(strpos(self::norm($real),$root)===0) throw new \LogicException('Public slot forbidden.');
    $h=@fopen($real,'r+b'); if($h===false) throw new \RuntimeException('Slot unavailable.');
    $handles[]=$h; $stat=fstat($h); $current=stat($real);
    if (!$stat || !$current || ($stat['mode'] & 0170000)!==0100000 || $stat['ino']!==$current['ino'] || $stat['dev']!==$current['dev'] || $stat['size']!==strlen(self::MARKER)
      || fread($h,strlen(self::MARKER)+1)!==self::MARKER) throw new \LogicException('Invalid slot identity or marker.');
    $identity=$stat['dev'].':'.$stat['ino'];
    if(isset($seen[$identity])) throw new \LogicException('Duplicate slot identity.');
    $seen[$identity]=true;
   }
   foreach ($handles as $h) {
    $busy=0;
    if(flock($h,LOCK_EX|LOCK_NB,$busy)) {$lease=$h;break;}
    if(!$busy) throw new \RuntimeException('Slot locking unavailable.');
   }
   if($lease===null) throw new \OverflowException('Upload capacity busy.');
   if(($this->rateGate)()!==true) throw new \RuntimeException('Rate admission denied.');
   return $operation(); // Lease spans decoding AND storage, regardless of elapsed time.
  } finally {
   if($lease!==null) flock($lease,LOCK_UN);
   foreach ($handles as $h) fclose($h);
  }
 }
 private static function absolute($path): void
 {
  if(!is_string($path) || $path==='' || strpos($path,chr(0))!==false || strpos($path,'://')!==false
   || (DIRECTORY_SEPARATOR==='\\' ? !preg_match('~\A[A-Za-z]:[\\\\/]~',$path) : $path[0]!=='/')) throw new \InvalidArgumentException('Absolute local path required.');
 }
 private static function norm(string $p): string { $p=str_replace('\\','/',$p); return DIRECTORY_SEPARATOR==='\\' ? strtolower($p) : $p; }
}
