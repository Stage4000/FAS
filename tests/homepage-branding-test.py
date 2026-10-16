"""Homepage copy regression: no runtime data or external services required."""
from html.parser import HTMLParser
from pathlib import Path
import re


class HomepageCopy(HTMLParser):
    def __init__(self):
        super().__init__()
        self.elements = []
        self.text = []

    def handle_starttag(self, tag, attrs):
        self.elements.append((tag, dict(attrs)))

    def handle_data(self, data):
        self.text.append(data)


root = Path(__file__).resolve().parents[1]
source = (root / "index.php").read_text()
hero = source.split("<!-- Hero Section -->", 1)[1].split("</section>", 1)[0]
cta = source.split("<!-- Call to Action -->", 1)[1].split("</section>", 1)[0]
parser = HomepageCopy()
parser.feed(hero + cta)
copy = " ".join(" ".join(parser.text).split())
assert re.search(r'<h1\b[^>]*>FLIP AND STRIP PARTS</h1>', hero)
assert re.search(r'<p\b[^>]*class="lead [^"]*"[^>]*>Your Powersports Parts House</p>', hero)
assert re.search(r'<h2\b[^>]*>Great parts at Great Prices</h2>', cta)
assert "crash into cash" not in copy.lower()
assert "motorcycle, ATV/UTV, boat & automotive parts" in copy
assert "Harley Davidson, Yamaha, Honda, Kawasaki, Suzuki & more." in copy
assert sum(tag == "h1" for tag, _ in parser.elements) == 1
assert any(tag == "img" and attrs.get("src") == "gallery/hero-image.png?v=20261016"
           and attrs.get("alt") == "Flip and Strip Parts - Your Powersports Parts House" for tag, attrs in parser.elements)
links = [attrs.get("href") for tag, attrs in parser.elements if tag == "a"]
assert links == ["/products", "products", "contact"], links
header = (root / "includes/header.php").read_text()
seo = (root / "src/utils/Seo.php").read_text()
navigation = HomepageCopy()
navigation.feed(header.split('<!-- Navigation -->', 1)[1])
nav_brand_match = re.search(r'<a\b[^>]*class="[^"]*\bnavbar-brand\b[^"]*"[^>]*>.*?</a>', header, re.S)
assert nav_brand_match, "shared public navigation must have a brand link"
nav_brand = HomepageCopy()
nav_brand.feed(nav_brand_match.group())
assert " ".join(" ".join(nav_brand.text).split()) == "FLIP AND STRIP PARTS"
nav_brand_link = next(attrs for tag, attrs in nav_brand.elements if tag == "a")
assert nav_brand_link.get("aria-label") == "Flip and Strip Parts Home"
assert nav_brand_link.get("href") == "/"
nav_logo = next(attrs for tag, attrs in navigation.elements if tag == "img")
assert nav_logo.get("src") == "/gallery/hero-image.png?v=20261016"
assert nav_logo.get("alt") == "Flip and Strip Parts Logo"
assert (nav_logo.get("width"), nav_logo.get("height")) == ("40", "40")
assert "object-fit: contain" in nav_logo.get("style", "") and "rounded-circle" not in nav_logo.get("class", "")
assert "border-radius: 0" in nav_logo.get("style", "") and "border: 0" in nav_logo.get("style", "")
assert "Seo::absoluteUrl('/gallery/hero-image.png?v=20261016')" in header
assert "'logo' => self::absoluteUrl('/gallery/hero-image.png?v=20261016')" in seo
assert "FLIPANDSTRIP.COM_d00a_018a.jpg" not in header + seo
print("PASS homepage branding assertions; hero, public brand text/accessibility, navigation, social/schema logo references and shopping links verified")
