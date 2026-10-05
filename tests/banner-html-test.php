<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/banner-html.php';
require_once __DIR__ . '/../src/models/Banner.php';

$checks = 0;
function bannerCheck($ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}

$message = 'Have questions or comments? TEXT US <a href="sms:+14073085294">407-308-5294 (KAWI)</a> or email <a href="mailto:FLIPANDSTRIPCYCLES@GMAIL.COM">FLIPANDSTRIPCYCLES@GMAIL.COM</a>';
bannerCheck(fasBannerHtml($message) === $message, 'Contact links render as anchors');
bannerCheck(fasBannerText($message) === 'Have questions or comments? TEXT US 407-308-5294 (KAWI) or email FLIPANDSTRIPCYCLES@GMAIL.COM', 'Analytics and summaries contain readable text');
bannerCheck(fasBannerHtml('Save 5% & spend < $50 — café') === 'Save 5% &amp; spend &lt; $50 — café', 'Plain text and UTF-8 preserved');
bannerCheck(fasBannerHtml('<strong>Sale</strong><br><em>Today</em>') === '<strong>Sale</strong><br><em>Today</em>', 'Basic formatting preserved');
foreach (['https://example.com/?a=1&amp;b=2', 'http://example.com', '/products', '#details', 'products?page=2', 'tel:+14073085294'] as $href) {
    bannerCheck(strpos(fasBannerHtml('<a href="'.$href.'">Go</a>'), '<a href=') === 0, 'Allowed link: '.$href);
}
foreach (['javascript:alert(1)', 'java&#x73;cript:alert(1)', 'java&#10;script:alert(1)', 'data:text/html,boom', 'vbscript:boom', 'file:///test', 'https:\\example.com'] as $href) {
    bannerCheck(fasBannerHtml('<a href="'.$href.'">Go</a>') === 'Go', 'Unsafe link stripped: '.$href);
}
bannerCheck(fasBannerHtml('<a href="/products" onclick="alert(1)" style="position:fixed" id="bad" target="_blank">Shop</a>') === '<a href="/products" target="_blank" rel="noopener noreferrer">Shop</a>', 'Only safe attributes retained');
bannerCheck(fasBannerHtml('<script>alert(1)</script><style>body{display:none}</style><svg onload="alert(1)"><a href="/">Bad</a></svg><iframe srcdoc="bad"></iframe><img src=x onerror=alert(1)>OK') === 'OK', 'Active content removed');
bannerCheck(fasBannerHtml('&lt;script&gt;alert(1)&lt;/script&gt;') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'Escaped markup stays inert');
bannerCheck(fasBannerHtml('<strong>Open &amp; <em>nested') === '<strong>Open &amp; <em>nested</em></strong>', 'Malformed markup is closed');
bannerCheck(fasBannerHtml('<a href="/" title="&quot; onclick=&quot;bad">Go</a>') === '<a href="/" title="&quot; onclick=&quot;bad">Go</a>', 'Attribute values cannot escape quotes');

$encoded = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
bannerCheck(fasBannerHtml($encoded) === $message, 'Previously encoded contact links render');
bannerCheck(fasBannerHtml('Spend < $50 > $10') === 'Spend &lt; $50 &gt; $10', 'Literal comparisons are not mistaken for tags');
bannerCheck(fasBannerText($encoded) === fasBannerText($message), 'Encoded messages have readable summaries');
bannerCheck(fasBannerHtml('<A HREF=tel:+14073085294>Call</A>') === '<a href="tel:+14073085294">Call</a>', 'Uppercase tags and unquoted attributes work');
bannerCheck(fasBannerHtml("<a href='mailto:test@example.com' title='Email > call'>Email</a>") === '<a href="mailto:test@example.com" title="Email &gt; call">Email</a>', 'Single quotes and angle brackets in attributes work');
bannerCheck(fasBannerHtml('<strong>A<em>B</strong>C') === '<strong>A<em>B</em></strong>C', 'Crossed formatting cannot break surrounding markup');
bannerCheck(fasBannerHtml('<a href="/one">One<a href="/two">Two</a></a>') === '<a href="/one">One</a><a href="/two">Two</a>', 'Nested anchors are separated');
foreach ([
    '<a href="javascript:alert(1)" href="/safe">Go</a>',
    '<a href="jav&#x61;script:alert(1)">Go</a>',
    '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">Go</a>',
    '<a href="java&#x09;script:alert(1)">Go</a>',
] as $unsafe) {
    bannerCheck(fasBannerHtml($unsafe) === 'Go', 'Unsafe protocols cannot hide in duplicate attributes or entities');
    bannerCheck(fasBannerHtml(htmlspecialchars($unsafe, ENT_QUOTES, 'UTF-8')) === 'Go', 'Encoded unsafe markup is still filtered');
}
bannerCheck(fasBannerHtml('&lt;a href=&quot;/&quot; onclick=&quot;alert(1)&quot;&gt;Go&lt;/a&gt;&lt;script&gt;alert(1)&lt;/script&gt;') === '<a href="/">Go</a>', 'Decoded messages cannot introduce scripts or event handlers');
bannerCheck(fasBannerHtml('<span style="position:fixed" id="page" onmouseover="alert(1)">Safe</span><!-- comment -->') === '<span>Safe</span>', 'Formatting cannot inject layout, handlers or comments');

// Simulate a host without DOM even when the developer's PHP has it built in.
// The former fallback escapes these links and fails this regression check.
$source = file_get_contents(__DIR__ . '/../includes/banner-html.php');
eval('namespace BannerWithoutDom; function class_exists($name) { return false; } class DOMDocument { public function __construct() { throw new \\RuntimeException("DOM must not be required"); } } ' . substr($source, 5));
bannerCheck(BannerWithoutDom\fasBannerHtml($message) === $message, 'Contact HTML renders without DOM');
bannerCheck(BannerWithoutDom\fasBannerHtml($encoded) === $message, 'Encoded contact HTML renders without DOM');
bannerCheck(BannerWithoutDom\fasBannerHtml('<script>alert(1)</script><a href="javascript:alert(1)">Go</a>') === 'Go', 'Filtering remains active without DOM');

// Verify both persistence paths retain editable HTML without touching site data.
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE banners (id INTEGER PRIMARY KEY, message TEXT, bg_color TEXT, text_color TEXT, link_url TEXT, link_text TEXT, is_dismissible INTEGER, is_active INTEGER, sort_order INTEGER, show_countdown INTEGER, countdown_end TEXT, starts_at TEXT, ends_at TEXT)');
$model = new FAS\Models\Banner($db);
$model->create(['message' => $message]);
bannerCheck($model->getById(1)['message'] === $message, 'Create preserves editable HTML');
$model->update(1, ['message' => '<strong>Updated</strong><br>'.$message, 'is_active' => 1]);
bannerCheck(fasBannerHtml($model->getActive()[0]['message']) === '<strong>Updated</strong><br>'.$message, 'Updated active banner renders formatting and links');
echo "Banner HTML: $checks checks passed.\n";
