<?php
/** Render the supported banner markup, rebuilding every element and attribute. */
function fasBannerHtml(string $message): string
{
    $escape = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    // Keep banners readable on hosts without the optional DOM extension.
    if (!class_exists('DOMDocument') || $message === '') {
        return $escape($message);
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $message . '</body></html>', LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$loaded) {
        return $escape($message);
    }

    $render = static function (DOMNode $node) use (&$render, $escape): string {
        if ($node instanceof DOMText) {
            return $escape($node->nodeValue);
        }
        if (!($node instanceof DOMElement)) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'head'], true)) {
            return '';
        }
        $children = '';
        foreach ($node->childNodes as $child) {
            $children .= $render($child);
        }
        if (!in_array($tag, ['a', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'span', 'br'], true)) {
            return $children;
        }
        if ($tag === 'br') {
            return '<br>';
        }
        $attributes = '';
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            // Disallow browser-normalized control characters and unknown protocols.
            $safe = $href !== '' && !preg_match('/[\x00-\x20\x7F\\\\]/', $href)
                && (!preg_match('/^[^\/?#]*:/', $href) || preg_match('/^(https?:|mailto:|tel:|sms:)/i', $href));
            if (!$safe) {
                return $children;
            }
            $attributes = ' href="' . $escape($href) . '"';
            if ($node->hasAttribute('title')) {
                $attributes .= ' title="' . $escape($node->getAttribute('title')) . '"';
            }
            if ($node->getAttribute('target') === '_blank') {
                $attributes .= ' target="_blank" rel="noopener noreferrer"';
            }
        }
        return '<' . $tag . $attributes . '>' . $children . '</' . $tag . '>';
    };
    return $render($document->documentElement);
}

function fasBannerText(string $message): string
{
    return trim(html_entity_decode(strip_tags(str_replace('<br>', ' ', fasBannerHtml($message))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}
