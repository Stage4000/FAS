<?php
/** Render a small banner HTML vocabulary without requiring PHP's DOM extension. */
function fasBannerHtml(string $message): string
{
    $escape = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $decode = static function (string $value): string {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    };
    // Older/pasted messages can contain encoded markup. Decode one layer only,
    // then apply the same allowlist; never output decoded input directly.
    if (strpos($message, '<') === false
        && preg_match('/&lt;\/?(?:a|strong|b|em|i|u|s|small|span|br)\b/i', $message)) {
        $message = $decode($message);
    }

    // Tokens are only candidates for reconstruction, not trusted HTML. Everything
    // between them is escaped, and no original tag or attribute is emitted.
    preg_match_all('~<!--.*?(?:-->|$)|<(?:[^<>"\']|"[^"]*"|\'[^\']*\')*>~s', $message, $tokens, PREG_OFFSET_CAPTURE);
    $allowed = ['a', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'span', 'br'];
    $blocked = ['script', 'style', 'iframe', 'object', 'svg', 'math', 'template', 'noscript', 'head'];
    $output = '';
    $offset = 0;
    $stack = [];
    $suppressed = [];
    $closeTo = static function (int $index) use (&$stack, &$output): void {
        while (count($stack) > $index) {
            $entry = array_pop($stack);
            if ($entry['emit']) $output .= '</' . $entry['tag'] . '>';
        }
    };
    foreach ($tokens[0] as [$token, $position]) {
        if (!$suppressed) $output .= $escape($decode(substr($message, $offset, $position - $offset)));
        $offset = $position + strlen($token);
        if (!preg_match('~^<(\/?)\s*([a-z][a-z0-9:-]*)\b(.*?)>$~is', $token, $parts)) {
            if (!$suppressed && !in_array(substr($token, 0, 2), ['<!', '<?'], true)) {
                $output .= $escape($decode($token));
            }
            continue;
        }
        $closing = $parts[1] === '/';
        $tag = strtolower($parts[2]);
        if ($suppressed) {
            if ($closing && $tag === end($suppressed)) array_pop($suppressed);
            elseif (!$closing && in_array($tag, $blocked, true)) $suppressed[] = $tag;
            continue;
        }
        if (in_array($tag, $blocked, true)) {
            if (!$closing) $suppressed[] = $tag;
            continue;
        }
        if (!in_array($tag, $allowed, true)) continue;
        if ($closing) {
            for ($i = count($stack) - 1; $i >= 0; $i--) {
                if ($stack[$i]['tag'] === $tag) { $closeTo($i); break; }
            }
            continue;
        }
        if ($tag === 'br') { $output .= '<br>'; continue; }

        $attributes = '';
        $emit = true;
        if ($tag === 'a') {
            // Browsers cannot nest anchors; close a previous anchor consistently.
            for ($i = count($stack) - 1; $i >= 0; $i--) {
                if ($stack[$i]['tag'] === 'a') { $closeTo($i); break; }
            }
            $values = [];
            $source = $parts[3];
            $cursor = 0;
            while (preg_match('~\G\s+([^\s=<>/"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s<>"\'=\x60]+)))?~', $source, $attr, PREG_UNMATCHED_AS_NULL, $cursor)) {
                $name = strtolower($attr[1]);
                if (!array_key_exists($name, $values)) $values[$name] = $decode($attr[2] ?? $attr[3] ?? $attr[4] ?? '');
                $cursor += strlen($attr[0]);
            }
            $href = trim($values['href'] ?? '');
            $emit = in_array(trim(substr($source, $cursor)), ['', '/'], true)
                && $href !== '' && !preg_match('/[\x00-\x20\x7F\\\\]/', $href)
                && (!preg_match('/^[^\/?#]*:/', $href) || preg_match('/^(https?:|mailto:|tel:|sms:)/i', $href));
            if ($emit) {
                $attributes = ' href="' . $escape($href) . '"';
                if (isset($values['title'])) $attributes .= ' title="' . $escape($values['title']) . '"';
                if (($values['target'] ?? '') === '_blank') $attributes .= ' target="_blank" rel="noopener noreferrer"';
            }
        }
        $stack[] = ['tag' => $tag, 'emit' => $emit];
        if ($emit) $output .= '<' . $tag . $attributes . '>';
    }
    if (!$suppressed) $output .= $escape($decode(substr($message, $offset)));
    $closeTo(0);
    return $output;
}

function fasBannerText(string $message): string
{
    return trim(html_entity_decode(strip_tags(str_replace('<br>', ' ', fasBannerHtml($message))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}
