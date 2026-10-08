<?php
declare(strict_types=1);
namespace FAS\Utils;

/** Translate explicit source condition labels; never infer condition from a title. */
final class ProductCondition
{
    private static function normalized($value): string
    {
        return trim(preg_replace('/\s+/u',' ',strtolower(strip_tags(is_string($value)?$value:''))));
    }

    public static function merchant($value): string
    {
        $value=self::normalized($value);
        if(in_array($value,['new','brand new','new with tags','new with box'],true))return 'new';
        if(in_array($value,['refurbished','certified refurbished','excellent - refurbished','very good - refurbished','good - refurbished','seller refurbished','manufacturer refurbished','remanufactured'],true))return 'refurbished';
        // Unknown labels retain the existing conservative used classification.
        return 'used';
    }

    public static function schema($value): string
    {
        $label=self::normalized($value);
        if(in_array($label,['for parts','for parts or not working','damaged'],true))return 'https://schema.org/DamagedCondition';
        return 'https://schema.org/'.['new'=>'NewCondition','refurbished'=>'RefurbishedCondition','used'=>'UsedCondition'][self::merchant($value)];
    }

    public static function identifier($value): string
    {
        $value=trim(strip_tags(is_scalar($value)?(string)$value:''));
        $key=strtolower(preg_replace('/[\s._\/-]+/u','',$value));
        return in_array($key,['','na','none','null','unknown','notavailable','notapplicable','doesnotapply','unspecified'],true)?'':$value;
    }

    public static function merchantMpn($value): string
    {
        $value = self::identifier($value);
        // Merchant Center accepts complete MPNs of at most 70 characters.
        // Never truncate a part number or choose one from an overlong list.
        // Keep the source value intact for review; omit it from feed/schema.
        if (preg_match('/\A.{1,70}\z/us', $value) !== 1) return '';
        return $value;
    }
}
