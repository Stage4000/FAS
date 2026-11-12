<?php
declare(strict_types=1);
namespace FAS\Blog;

/** Structured first-party editor format. Arbitrary HTML never enters storage/rendering. */
final class BlogRichDocument
{
    public static function validate($input): array
    {
        if (is_string($input)) {
            if (strlen($input)>400000) { throw new \InvalidArgumentException('The formatted article is too large.'); }
            try { $input=json_decode($input,true,20,JSON_THROW_ON_ERROR); }
            catch (\Throwable $e) { throw new \InvalidArgumentException('The formatted article is invalid.'); }
        }
        if (!is_array($input) || ($input['version']??null)!==1 || !isset($input['blocks']) || !is_array($input['blocks']) || !self::isList($input['blocks']) || count($input['blocks'])>500) { throw new \InvalidArgumentException('Use a supported formatted article document.'); }
        self::keys($input,['version','blocks']);
        $characters=0; $runs=0; $images=0; $blocks=[];
        foreach ($input['blocks'] as $block) {
            if (!is_array($block)) { throw new \InvalidArgumentException('Invalid article block.'); }
            $type=$block['type']??null;
            if (in_array($type,['paragraph','heading','blockquote'],true)) {
                self::keys($block,$type==='heading'?['type','level','children']:['type','children']);
                $out=['type'=>$type];
                if ($type==='heading') {
                    if (!in_array($block['level']??null,[2,3],true)) { throw new \InvalidArgumentException('Use heading level two or three.'); }
                    $out['level']=$block['level'];
                }
                $out['children']=self::runs($block['children']??null,$characters,$runs); $blocks[]=$out;
            } elseif (in_array($type,['bullet_list','ordered_list'],true)) {
                self::keys($block,['type','items']); $items=$block['items']??null;
                if (!is_array($items)||!self::isList($items)||count($items)<1||count($items)>100) { throw new \InvalidArgumentException('Use between one and 100 list items.'); }
                $out=['type'=>$type,'items'=>[]];
                foreach($items as $item) { $out['items'][]=self::runs($item,$characters,$runs); } $blocks[]=$out;
            } elseif ($type==='image') {
                self::keys($block,['type','src','alt','caption']);
                if (++$images>20 || !is_string($block['src']??null) || !BlogContent::validGalleryImage($block['src'])) { throw new \InvalidArgumentException('Use at most 20 existing site-gallery images.'); }
                $alt=self::text($block['alt']??null,160); $caption=self::text($block['caption']??'',320);
                if (!BlogContent::hasVisibleText($alt)) { throw new \InvalidArgumentException('Describe each article image.'); }
                $blocks[]=['type'=>'image','src'=>$block['src'],'alt'=>$alt,'caption'=>$caption];
                $characters+=preg_match_all('/./us',$alt.$caption);
            } else { throw new \InvalidArgumentException('Unsupported article block.'); }
            if ($characters>60000) { throw new \InvalidArgumentException('The article exceeds 60,000 text characters.'); }
        }
        $document=['version'=>1,'blocks'=>$blocks];
        if (strlen(json_encode($document,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>400000) { throw new \InvalidArgumentException('The formatted article is too large.'); }
        return $document;
    }

    public static function hasContent(array $document): bool
    {
        foreach ($document['blocks'] as $block) {
            if ($block['type']==='image') { return true; }
            foreach ($block['items']??[$block['children']] as $runs) { foreach($runs as $run) { if(BlogContent::hasVisibleText($run['text']))return true; } }
        }
        return false;
    }

    public static function imageSources($document): array
    {
        $result=[]; foreach(self::validate($document)['blocks'] as $block) { if($block['type']==='image')$result[]=$block['src']; } return array_values(array_unique($result));
    }

    public static function render($document,?callable $allowImage=null): string
    {
        $html='';
        foreach(self::validate($document)['blocks'] as $block) {
            switch($block['type']) {
                case 'paragraph': $html.='<p>'.self::renderRuns($block['children'])."</p>\n"; break;
                case 'heading': $tag='h'.$block['level'];$html.='<'.$tag.'>'.self::renderRuns($block['children']).'</'.$tag.">\n";break;
                case 'blockquote': $html.='<blockquote><p>'.self::renderRuns($block['children'])."</p></blockquote>\n";break;
                case 'bullet_list': case 'ordered_list':
                    $tag=$block['type']==='bullet_list'?'ul':'ol';$html.='<'.$tag.'>';
                    foreach($block['items'] as $item){$html.='<li>'.self::renderRuns($item).'</li>';}$html.='</'.$tag.">\n";break;
                case 'image':
                    if($allowImage!==null && $allowImage($block['src'])!==true)break;
                    $html.='<figure><img src="'.self::escape($block['src']).'" alt="'.self::escape($block['alt']).'" loading="lazy" decoding="async">';
                    if($block['caption']!=='')$html.='<figcaption>'.self::escape($block['caption']).'</figcaption>';
                    $html.="</figure>\n";break;
            }
        }
        return $html;
    }

    /** Converts the previously supported Markdown display, preserving literal punctuation. */
    public static function fromMarkdown(string $body): array
    {
        $body=BlogContent::validate(['body'=>$body])['body'];$blocks=[];$paragraph=[];$list=[];
        $flushP=static function()use(&$blocks,&$paragraph):void{if($paragraph){$blocks[]=['type'=>'paragraph','children'=>self::markdownRuns(implode(' ',$paragraph))];$paragraph=[];}};
        $flushL=static function()use(&$blocks,&$list):void{if($list){foreach(array_chunk($list,100)as$items)$blocks[]=['type'=>'bullet_list','items'=>$items];$list=[];}};
        foreach(explode("\n",$body) as $line){
            if(trim($line)===''){$flushP();$flushL();continue;}
            if(preg_match('/\A(#{2,3})\s+(.+)\z/u',$line,$m)){$flushP();$flushL();$blocks[]=['type'=>'heading','level'=>strlen($m[1]),'children'=>self::markdownRuns($m[2])];}
            elseif(preg_match('/\A-\s+(.+)\z/u',$line,$m)){$flushP();$list[]=self::markdownRuns($m[1]);}
            else{$flushL();$paragraph[]=$line;}
        }
        $flushP();$flushL();return self::validate(['version'=>1,'blocks'=>$blocks]);
    }

    private static function markdownRuns(string $text): array
    {
        $result=[];$offset=0;preg_match_all('/\[([^\]\n]{1,200})\]\(([^)\s]{1,2000})\)/u',$text,$matches,PREG_OFFSET_CAPTURE);
        foreach($matches[0] as $i=>$m){if($m[1]>$offset)$result[]=['text'=>substr($text,$offset,$m[1]-$offset)];
            $url=$matches[2][$i][0];$result[]=BlogContent::safeLink($url)?['text'=>$matches[1][$i][0],'href'=>$url]:['text'=>$m[0]];$offset=$m[1]+strlen($m[0]);}
        if($offset<strlen($text))$result[]=['text'=>substr($text,$offset)];return $result;
    }
    private static function runs($input,int &$characters,int &$count): array
    {
        if(!is_array($input)||!self::isList($input)||count($input)>2000)throw new \InvalidArgumentException('Invalid article text.');
        $result=[];
        foreach($input as $run){if(!is_array($run)||++$count>2000)throw new \InvalidArgumentException('The article has too many formatting segments.');
            self::keys($run,['text','bold','italic','underline','href']);$text=self::text($run['text']??null,60000);$characters+=preg_match_all('/./us',$text);
            if($characters>60000)throw new \InvalidArgumentException('The article exceeds 60,000 text characters.');
            $out=['text'=>$text];foreach(['bold','italic','underline'] as $mark){if(array_key_exists($mark,$run)){if(!is_bool($run[$mark]))throw new \InvalidArgumentException('Invalid article formatting.');if($run[$mark])$out[$mark]=true;}}
            if(array_key_exists('href',$run)){if(!is_string($run['href'])||strlen($run['href'])>2000||($run['href']!==''&&!BlogContent::safeLink($run['href'])))throw new \InvalidArgumentException('Use a safe website or site-relative link.');if($run['href']!=='')$out['href']=$run['href'];}
            if($text!=='')$result[]=$out;
        }
        return $result;
    }
    private static function renderRuns(array $runs): string
    {
        $out='';foreach($runs as $run){$text=str_replace("\n",'<br>',self::escape($run['text']));if(isset($run['bold']))$text='<strong>'.$text.'</strong>';if(isset($run['italic']))$text='<em>'.$text.'</em>';if(isset($run['underline']))$text='<u>'.$text.'</u>';if(isset($run['href']))$text='<a href="'.self::escape($run['href']).'">'.$text.'</a>';$out.=$text;}return $out;
    }
    private static function keys(array $data,array $allowed):void { if(array_diff(array_keys($data),$allowed))throw new \InvalidArgumentException('Unsupported formatted article attributes.'); }
    private static function isList(array $data):bool { return $data===[]||array_keys($data)===range(0,count($data)-1); }
    private static function text($value,int $max):string { if(!is_string($value)||strlen($value)>$max*4||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)||preg_match_all('/./us',$value)>$max)throw new \InvalidArgumentException('Invalid formatted article text.');return str_replace(["\r\n","\r"],"\n",$value); }
    private static function escape(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
