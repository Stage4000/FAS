"""Keep condition badges readable on their fixed Bootstrap info background."""
from pathlib import Path
import re
root = Path(__file__).resolve().parents[1]
for name in ('products.php', 'api/products.php', 'includes/product-merchandising.php'):
    source = (root / name).read_text()
    badges = re.findall(r'<span class="([^"]*\bbg-info\b[^"]*)"', source)
    assert badges, f'{name}: condition badge missing'
    assert all('text-dark' in classes.split() for classes in badges), name
# Bootstrap 5.3.2 keeps --bs-dark-rgb=33,37,41 in both color modes.
# Match that fixed dark foreground to --bs-info-rgb=13,202,240.
def luminance(rgb):
    values = [c / 255 for c in rgb]
    linear = [v / 12.92 if v <= .04045 else ((v + .055) / 1.055) ** 2.4 for v in values]
    return sum(v*w for v,w in zip(linear, (.2126,.7152,.0722)))
ratio = (luminance((13,202,240)) + .05)/(luminance((33,37,41)) + .05)
assert ratio >= 4.5, ratio
print(f'PASS: all three condition-badge paths, fixed foreground contrast {ratio:.2f}:1')
