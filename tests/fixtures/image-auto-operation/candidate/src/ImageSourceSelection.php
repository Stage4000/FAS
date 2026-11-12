<?php
declare(strict_types=1);
namespace FAS\Utils;
/** Exact catalog slot selection only; no fetching, normalization or authority grant. */
final class ImageSourceSelection
{
    public static function selector(array $envelope): array
    {
        if (!array_key_exists('source_selector',$envelope)) return ['kind'=>'primary'];
        $s=$envelope['source_selector'];
        if ($s===['kind'=>'primary']) return $s;
        if (is_array($s) && array_keys($s)===['kind','index'] && $s['kind']==='gallery'
            && is_int($s['index']) && $s['index']>=0 && $s['index']<128) return $s;
        throw new \RuntimeException('Explicit canonical primary/gallery selector required');
    }
    public static function selected(array $catalog,array $envelope): string
    {
        $s=self::selector($envelope);
        if ($s['kind']==='primary') $url=$catalog['image_url']??null;
        else {
            $raw=$catalog['images']??null;
            if (!is_string($raw) || strlen($raw)>131072) throw new \RuntimeException('Bounded gallery snapshot required');
            try {$gallery=json_decode($raw,false,16,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \RuntimeException('Invalid gallery snapshot',0,$e);}
            if (!is_array($gallery) || count($gallery)>128) throw new \RuntimeException('Bounded gallery list required');
            foreach($gallery as $value) if (!is_string($value) || $value==='' || strlen($value)>2048) throw new \RuntimeException('Exact bounded gallery strings required');
            $url=$gallery[$s['index']]??null;
        }
        if (!is_string($url) || $url==='' || strlen($url)>2048) throw new \RuntimeException('Selected catalog source absent/unbounded');
        return $url;
    }
}
