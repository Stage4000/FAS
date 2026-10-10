<?php
declare(strict_types=1);
namespace FAS\Utils;
require_once __DIR__.'/ProductContent.php';
require_once __DIR__.'/ProductCondition.php';
require_once __DIR__.'/ProductIdentifierOutput.php';
require_once __DIR__.'/Seo.php';

/** Review warnings, not automatic publication rules or fitment claims. */
final class ProductContentQuality
{
    public static function definitions(): array
    {
        $labels = [
            'content_review'=>['Content not reviewed','No reviewed storefront description has been published.'],
            'source_changed'=>['Source changed','Identity, condition, description, category or photos changed after the last review.'],
            'content_draft'=>['Unpublished edits','A draft differs from the published description or search text.'],
            'placeholder_mpn'=>['Placeholder identifier','A value such as N/A or unknown is not a part number. It is omitted from feed/schema.'],
            'unsupported_mpn'=>['Source identifier omitted','The source value is omitted from feed/schema by the existing 1–70-character MPN guard. Verify the complete manufacturer-assigned identifier for the actual item. Do not truncate a list, select a component number, or guess.'],
            'identifier_output_hold'=>['Conflicting identifiers withheld','Brand and part number are withheld from storefront, feed and schema because the reviewed identity does not match. Imported source inventory is unchanged; verify the exact current item and SKU before changing the review.'],
            'identifier_reviewed_override'=>['Reviewed output identifiers','Storefront, feed and schema use owner-confirmed identifiers for this exact product ID and SKU. Imported manufacturer/model remain unchanged here for source provenance. See docs/identifier-output-holds.md.'],
            'marketplace_copy'=>['Marketplace copy','Review inherited marketplace instructions before using them on this website.'],
            'duplicate_opening'=>['Repeated opening','Another listing starts with the same description. Verify the actual item.'],
            'description_relevance'=>['Check description identity','The description opening shares few meaningful words with the product title. This is a review hint.'],
            'fitment_review'=>['Verify model / part number','The source model field may be a part number. Vehicle compatibility has not been verified separately.'],
        ];
        $result = [];
        foreach ($labels as $key=>[$label,$note]) {
            $result[$key] = ['label'=>$label,'note'=>$note,'icon'=>'fa-clipboard-check','class'=>'warning'];
        }
        return $result;
    }

    public static function description(array $product, ?array $review): string
    {
        return $review && $review['published_json'] !== null
            ? ProductContent::decode($review['published_json'])['description']
            : (string)($product['description'] ?? '');
    }

    private static function opening(string $text): string
    {
        return mb_strtolower(Seo::metaDescription(Seo::cleanProductSeoDescription($text)),'UTF-8');
    }

    public static function openings(array $products, array $reviews): array
    {
        $counts = [];
        foreach ($products as $product) {
            $opening = self::opening(self::description($product,$reviews[(int)$product['id']] ?? null));
            if (mb_strlen($opening) >= 50) $counts[$opening] = ($counts[$opening] ?? 0) + 1;
        }
        return $counts;
    }

    public static function issues(array $product, ?array $review, array $openings): array
    {
        $keys = [];
        $state = ProductContent::state($product,$review);
        if (!$review || $review['published_json'] === null) $keys[]='content_review';
        if ($state === 'Source changed') $keys[]='source_changed';
        if ($review && $review['published_json'] !== null && $review['draft_json'] !== $review['published_json']) $keys[]='content_draft';
        $model = trim((string)($product['model'] ?? ''));
        if ($model !== '' && ProductCondition::identifier($model) === '') $keys[]='placeholder_mpn';
        if ($model !== '' && ProductCondition::identifier($model) !== '') $keys[]='fitment_review';
        if (ProductCondition::identifier($model) !== '' && ProductCondition::merchantMpn($model) === '') $keys[]='unsupported_mpn';
        if (ProductIdentifierOutput::isHeld($product)) $keys[]='identifier_output_hold';
        if (ReviewedProductFacts::identifiers($product) !== null) $keys[]='identifier_reviewed_override';
        $description = self::description($product,$review);
        if (preg_match('/\bebay\b|IMPORTANT BUYER NOTICE|porch pirates?/iu',$description)) $keys[]='marketplace_copy';
        $opening = self::opening($description);
        if (($openings[$opening] ?? 0) > 1) $keys[]='duplicate_opening';
        $words = preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower((string)($product['name'] ?? ''),'UTF-8'),-1,PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_unique(array_filter($words,static fn($word)=>mb_strlen($word)>2 && !in_array($word,['the','and','for','with','from','new','used','low','miles'],true))));
        $matches = array_filter($words,static fn($word)=>mb_strpos($opening,$word)!==false);
        if (count($words)>=3 && count($matches)/count($words)<0.25) $keys[]='description_relevance';
        $definitions=self::definitions();
        return array_intersect_key($definitions,array_flip($keys));
    }
}

