<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/../front-end-vnext/ImageQuotaAdmission.php';
/** Pure trusted-input projection, NOT controller authentication or durable single-use. */
final class ControllerQuotaWitness {
 public function __construct(private string$package,private string$policy,private bool$enabled=false){foreach([$package,$policy]as$v)if(!preg_match('/^[a-f0-9]{64}$/D',$v))throw new \InvalidArgumentException('Fixed pins');}
 private static function keys(array$a,array$expected):void{$k=array_keys($a);sort($k);sort($expected);if($k!==$expected)throw new \InvalidArgumentException('Exact witness shape');}
 private static function id(mixed$v,int$n):bool{return is_string($v)&&preg_match('/^[a-f0-9]{'.$n.'}$/D',$v)===1;}
 private static function hold(string$why):array{return['state'=>'hold','reason'=>$why,'dispatch_authorized'=>false,'observation'=>null];}
 public function project(array$r,array$binding,array$fs,array$use,array$reservations,int$now):array{
  if(!$this->enabled)throw new \RuntimeException('Default off');if($now<0)throw new \InvalidArgumentException('Current trusted time');
  self::keys($binding,['job','handoff_sha256','package_sha256','policy_sha256']);
  if(!self::id($binding['job'],32)||!self::id($binding['handoff_sha256'],64)||$binding['package_sha256']!==$this->package||$binding['policy_sha256']!==$this->policy)throw new \InvalidArgumentException('Fixed actual pending job/package/policy');
  self::keys($r,['version','receipt_id','subscription','domain','active','refresh_action','completion','started_at','completed_at','read_at','measurement_id','completion_measurement_id','unit_contract','quota_limit','quota_used','limit_text','used_text','binding']);
  if($r['version']!==1||!self::id($r['receipt_id'],32)||$r['subscription']!==9001||$r['domain']!=='quota-fixture.invalid'||$r['active']!==true||$r['refresh_action']!=='synthetic-statistics-recalculate'||$r['completion']!=='positively_completed'||!self::id($r['measurement_id'],64)||$r['completion_measurement_id']!==$r['measurement_id']||$r['binding']!==$binding)return self::hold('identity_completion_or_binding');
  foreach(['started_at','completed_at','read_at','quota_limit','quota_used']as$k)if(!is_int($r[$k])||$r[$k]<0)throw new \InvalidArgumentException('Strict nonnegative integers');
  if($r['started_at']>$r['completed_at']||$r['completed_at']>$r['read_at']||$r['read_at']>$now||$now-$r['started_at']>300)return self::hold('refresh_not_fresh_or_ordered');
  // No decimal UI conversion. No supported rounding evidence currently exists.
  if($r['unit_contract']!=='exact-byte-integers-v1'||$r['quota_limit']<=0||$r['quota_used']>$r['quota_limit']||$r['limit_text']!==(string)$r['quota_limit'].' bytes'||$r['used_text']!==(string)$r['quota_used'].' bytes')return self::hold('unsupported_precision_or_units');
  self::keys($fs,['observed_at','private_free','public_free','tmp_free']);foreach($fs as$v)if(!is_int($v)||$v<0)throw new \InvalidArgumentException('Exact fresh filesystem byte integers');
  if($fs['observed_at']>$now||$now-$fs['observed_at']>300)return self::hold('filesystem_not_fresh');
  self::keys($use,['receipt_id','measurement_id','started_at','job','state']);if($use['receipt_id']!==$r['receipt_id']||$use['measurement_id']!==$r['measurement_id']||$use['started_at']!==$r['started_at']||$use['job']!==$binding['job']||!in_array($use['state'],['unused','claimed','consumed','settled','unknown'],true))throw new \InvalidArgumentException('Trusted usage state');
  if($use['state']!=='unused')return self::hold('receipt_retained_no_retry');
  $observation=['observed_at'=>min($r['started_at'],$fs['observed_at']),'quota_limit'=>$r['quota_limit'],'quota_used'=>$r['quota_used'],'private_free'=>$fs['private_free'],'public_free'=>$fs['public_free'],'tmp_free'=>$fs['tmp_free']];
  $budget=ImageQuotaAdmission::plan($observation,$now,$reservations,$binding['job']);
  if($budget['state']!=='reservation_planned')return self::hold($budget['reason']);
  return['state'=>'projection_ready','observation'=>$observation,'receipt_id'=>$r['receipt_id'],'binding'=>$binding,'receipt_sha256'=>hash('sha256',json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),'dispatch_authorized'=>false,'requires'=>['trusted_controller_authentication','same_snapshot_complete_retained_reservations','atomic_unused_to_claimed_with_existing_admission','retain_claim_and_capacity_on_unknown','recheck_start_based_freshness_at_admission']];
 }
}
