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


source = (Path(__file__).resolve().parents[1] / "index.php").read_text()
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
print("PASS 9 homepage branding assertions; approved logo reference, cache version, and shopping links verified")
