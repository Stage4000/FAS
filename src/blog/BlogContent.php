<?php
declare(strict_types=1);
namespace FAS\Blog;
require_once __DIR__ . '/BlogRichDocument.php';

/** Bounded editorial data and safe legacy/structured renderers. No raw HTML. */
final class BlogContent
{
    private const LIMITS = ['title'=>160,'slug'=>120,'excerpt'=>320,'body'=>60000,'author_name'=>80,
        'seo_title'=>110,'seo_description'=>160,'cover_image'=>500,'cover_alt'=>160];

    public static function empty(): array
    {
        return array_fill_keys(array_keys(self::LIMITS), '') + ['body_format'=>'markdown','related_product_ids'=>[]];
    }

    public static function validate(array $input, bool $publishing = false): array
    {
        $format=$input['body_format']??'markdown';
        if(!in_array($format,['markdown','rich_v1'],true))throw new \InvalidArgumentException('Use a supported article format.');
        $data = [];
        foreach (self::LIMITS as $field=>$limit) {
            $value = $input[$field] ?? '';
            $byteLimit=$field==='body'&&$format==='rich_v1'?400000:$limit*4;
            if (!is_string($value) || strlen($value) > $byteLimit || preg_match('//u',$value) !== 1
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)) {
                throw new \InvalidArgumentException('Enter valid text in ' . str_replace('_',' ',$field) . '.');
            }
            $value = trim(str_replace(["\r\n","\r"],"\n",$value));
            if ($field !== 'body') { $value = preg_replace('/\s+/u',' ',$value); }
            if (!($field==='body'&&$format==='rich_v1') && preg_match_all('/./us',$value) > $limit) {
                throw new \InvalidArgumentException(ucfirst(str_replace('_',' ',$field)) . ' exceeds ' . $limit . ' characters.');
            }
            $data[$field] = $value;
        }
        foreach (['seo_title','seo_description'] as $optionalSeo) {
            if (!self::hasVisibleText($data[$optionalSeo])) { $data[$optionalSeo] = ''; }
        }
        if ($data['slug'] !== '' && (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$data['slug'])
            || in_array($data['slug'],['feed','rss','sitemap','search','page','preview','admin','index'],true))) {
            throw new \InvalidArgumentException('Use a descriptive lowercase slug with letters, numbers and single hyphens.');
        }
        if ($data['cover_image'] !== '' && !self::validGalleryImage($data['cover_image'])) {
            throw new \InvalidArgumentException('Choose an existing JPEG, PNG or WebP from the site gallery.');
        }
        if (strpos($data['cover_image'],'..') !== false || strpos($data['cover_image'],'//') !== false) {
            throw new \InvalidArgumentException('Use a safe gallery image path.');
        }
        $data['body_format']=$format;
        if($format==='rich_v1'){
            $doc=BlogRichDocument::validate($data['body']===''?['version'=>1,'blocks'=>[]]:$data['body']);
            if($publishing&&!BlogRichDocument::hasContent($doc))throw new \InvalidArgumentException('Add article content before publishing.');
            $data['body']=json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        $ids = $input['related_product_ids'] ?? [];
        if (!is_array($ids) || count($ids) > 6) { throw new \InvalidArgumentException('Choose at most six related products.'); }
        $data['related_product_ids'] = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > 2147483647) { throw new \InvalidArgumentException('Related products must use valid product IDs.'); }
            if (!in_array($id,$data['related_product_ids'],true)) { $data['related_product_ids'][] = $id; }
        }
        if ($publishing) {
            foreach (['title','slug','excerpt','body','author_name'] as $required) {
                if (!self::hasVisibleText($data[$required])) { throw new \InvalidArgumentException('Add ' . str_replace('_',' ',$required) . ' before publishing.'); }
            }
            if ($data['cover_image'] !== '' && !self::hasVisibleText($data['cover_alt'])) { throw new \InvalidArgumentException('Describe the cover image before publishing.'); }
        }
        return $data;
    }

    public static function render(string $body,string $format='markdown',?callable $allowImage=null): string
    {
        if($format==='rich_v1')return BlogRichDocument::render($body,$allowImage);
        if($format!=='markdown')throw new \InvalidArgumentException('Unsupported article format.');
        $body = self::validate(['body'=>$body])['body'];
        $html = ''; $paragraph = []; $list = [];
        $flushParagraph = static function () use (&$html,&$paragraph): void {
            if ($paragraph) { $html .= '<p>' . self::inline(implode(' ',$paragraph)) . "</p>\n"; $paragraph = []; }
        };
        $flushList = static function () use (&$html,&$list): void {
            if ($list) { $html .= "<ul>\n"; foreach ($list as $line) { $html .= '<li>' . self::inline($line) . "</li>\n"; } $html .= "</ul>\n"; $list = []; }
        };
        foreach (explode("\n",$body) as $line) {
            if (trim($line) === '') { $flushParagraph(); $flushList(); continue; }
            if (preg_match('/\A(#{2,3})\s+(.+)\z/u',$line,$match)) {
                $flushParagraph(); $flushList(); $level = strlen($match[1]);
                $html .= '<h' . $level . '>' . self::inline($match[2]) . '</h' . $level . ">\n";
            } elseif (preg_match('/\A-\s+(.+)\z/u',$line,$match)) {
                $flushParagraph(); $list[] = $match[1];
            } else { $flushList(); $paragraph[] = $line; }
        }
        $flushParagraph(); $flushList();
        return $html;
    }

    private static function inline(string $text): string
    {
        $html = ''; $offset = 0;
        preg_match_all('/\[([^\]\n]{1,200})\]\(([^)\s]{1,2000})\)/u',$text,$matches,PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i=>$match) {
            $html .= self::escape(substr($text,$offset,$match[1]-$offset));
            $label = $matches[1][$i][0]; $url = $matches[2][$i][0];
            $html .= self::safeLink($url) ? '<a href="' . self::escape($url) . '">' . self::escape($label) . '</a>' : self::escape($match[0]);
            $offset = $match[1] + strlen($match[0]);
        }
        return $html . self::escape(substr($text,$offset));
    }

    /** Blank editor spacing and invisible format characters are not meaningful content. */
    public static function hasVisibleText(string $text): bool
    {
        return preg_match('/[^\s\p{Z}\p{Cf}]/u', $text) === 1;
    }

    public static function safeLink(string $url): bool
    {
        if (preg_match('/[\x00-\x20\x7F\\\\]/',$url)) { return false; }
        if (substr($url,0,1) === '/') { return substr($url,0,2) !== '//'; }
        $parts = parse_url($url);
        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''),['http','https'],true)
            && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && filter_var($url,FILTER_VALIDATE_URL) !== false;
    }

    public static function validGalleryImage(string $path):bool
    {
        return strlen($path)<=500 && preg_match('~\A/gallery/[A-Za-z0-9_-][A-Za-z0-9_./-]*\.(?:jpe?g|png|webp)\z~Di',$path)===1
            && strpos($path,'..')===false && strpos($path,'//')===false;
    }

    private static function escape(string $text): string { return htmlspecialchars($text,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'); }
}
