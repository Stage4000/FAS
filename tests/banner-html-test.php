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
