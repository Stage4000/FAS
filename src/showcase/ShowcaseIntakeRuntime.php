<?php
declare(strict_types=1);
namespace FAS\Showcase;
/** Dormant trusted composition. Does not provision files, start sessions or enable intake. */
final class ShowcaseIntakeRuntime
{
 public static function compose(array $config,callable $enabled,callable $identity,callable $clock,callable $capacityGate):?ShowcaseIntakeApplication
 {
  if($enabled()!==true)return null;
  $keys=array_keys($config);sort($keys);
  $expected=['database','public_roots','max_submissions','max_photo_bytes','quota_database','quota_secret','actor_limit','window_seconds','actor_capacity','global_limit','slots','consent_version','consent_text','form_ttl','form_capacity'];sort($expected);
  if($keys!==$expected)throw new \InvalidArgumentException('Explicit intake configuration required.');
  foreach(['database','quota_database','quota_secret','consent_version','consent_text'] as $key)if(!is_string($config[$key]))throw new \InvalidArgumentException('Invalid intake configuration.');
  foreach(['max_submissions','max_photo_bytes','actor_limit','window_seconds','actor_capacity','global_limit','form_ttl','form_capacity'] as $key)if(!is_int($config[$key]))throw new \InvalidArgumentException('Explicit integer intake limits required.');
  if(!is_array($config['public_roots'])||!is_array($config['slots']))throw new \InvalidArgumentException('Explicit private paths required.');
  if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D',$config['consent_version'])||trim($config['consent_text'])===''||strlen($config['consent_text'])>10000||preg_match('//u',$config['consent_text'])!==1)throw new \InvalidArgumentException('Reviewed consent required.');
  if($config['form_ttl']<1||$config['form_ttl']>86400||$config['form_capacity']<1||$config['form_capacity']>20)throw new \InvalidArgumentException('Bounded form configuration required.');
  // Constructors use existing files only. No identity, clock, quota charge or slot lease until handle.
  $repository=ShowcasePrivateStorage::open($config['database'],$config['public_roots'],static function():bool{return false;},$config['max_submissions'],$config['max_photo_bytes']);
  $rate=new ShowcaseRateAdmission($config['quota_database'],$config['public_roots'],$config['quota_secret'],$config['actor_limit'],$config['window_seconds'],$config['actor_capacity'],$config['global_limit'],$clock);
  $slots=new ShowcaseConcurrency($config['slots'],$config['public_roots'],$capacityGate);
  if($enabled()!==true)return null;
  return new ShowcaseIntakeApplication($repository,$rate,$slots,$config['consent_version'],$config['consent_text'],'/showcase.php',$config['form_ttl'],$config['form_capacity'],$clock,$enabled,$identity);
 }
}
