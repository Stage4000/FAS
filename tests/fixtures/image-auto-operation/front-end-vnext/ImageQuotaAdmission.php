<?php
declare(strict_types=1);
namespace FAS\Utils;
/** Pure conservative quota policy; observations are not independently authenticated here. */
final class ImageQuotaAdmission
{
    public const RESERVE=67108864;
    public const HEADROOM=67108864;
    private static function keys(array$v,array$keys):void{$a=array_keys($v);sort($a);sort($keys);if($a!==$keys)throw new \InvalidArgumentException('Exact quota schema required');}
    private static function id(mixed$v):bool{return is_string($v)&&preg_match('/^[a-f0-9]{32}$/D',$v)===1;}
    public static function plan(array$observation,int$now,array$reservations,string$job):array
    {
        if($now<0||!self::id($job))throw new \InvalidArgumentException('Explicit time/job required');
        self::keys($observation,['observed_at','quota_limit','quota_used','private_free','public_free','tmp_free']);foreach($observation as$v)if(!is_int($v)||$v<0)throw new \InvalidArgumentException('Strict nonnegative quota integers required');
        if($observation['quota_limit']===0||$observation['quota_used']>$observation['quota_limit'])throw new \InvalidArgumentException('Finite consistent subscription quota required');
        if($observation['observed_at']>$now||$now-$observation['observed_at']>300)return['state'=>'pause','reason'=>'quota_observation_not_fresh','dispatch_authorized'=>false];
        if(!array_is_list($reservations)||count($reservations)>1000)throw new \InvalidArgumentException('Bounded complete reservation inventory required');$seen=[];
        foreach($reservations as$r){if(!is_array($r))throw new \InvalidArgumentException('Reservation row required');self::keys($r,['job','bytes','state']);if(!self::id($r['job'])||isset($seen[$r['job']])||!is_int($r['bytes'])||$r['bytes']<self::RESERVE||!in_array($r['state'],['reserved','running','unknown'],true))throw new \InvalidArgumentException('Unique retained reservation required');$seen[$r['job']]=true;}
        if(isset($seen[$job]))return['state'=>'reconcile','reason'=>'existing_reservation_never_reissued','dispatch_authorized'=>false];
        if($reservations)return['state'=>'pause','reason'=>'single_job_capacity_or_unknown_outcome','dispatch_authorized'=>false];
        if($observation['quota_limit']-$observation['quota_used']<self::RESERVE+self::HEADROOM)return['state'=>'pause','reason'=>'subscription_headroom','dispatch_authorized'=>false];
        if(min($observation['private_free'],$observation['public_free'],$observation['tmp_free'])<self::RESERVE+self::HEADROOM)return['state'=>'pause','reason'=>'filesystem_headroom','dispatch_authorized'=>false];
        return['state'=>'reservation_planned','job'=>$job,'reserve_bytes'=>self::RESERVE,'headroom_bytes'=>self::HEADROOM,'observation_time'=>$observation['observed_at'],'dispatch_authorized'=>false,'requires'=>['trusted_complete_observations','atomic_shared_reservation','effect_reconciliation_before_release']];
    }
}
