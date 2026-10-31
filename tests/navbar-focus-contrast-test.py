"""Source/contrast regression, not a substitute for rendered browser acceptance."""
from pathlib import Path
import re

css = (Path(__file__).resolve().parents[1] / 'public/css/style.css').read_text()
css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
rules = re.findall(r'\.navbar\s+\.nav-link:focus-visible\s*\{([^{}]*)\}', css)
assert len(rules) == 1, 'Expected one scoped navbar keyboard focus rule'
declarations = dict(re.findall(r'([\w-]+)\s*:\s*([^;]+);', rules[0]))
assert declarations == {
    'outline': '3px solid #fff', 'outline-offset': '3px',
    'box-shadow': 'none', 'transition': 'none',
}, 'Keep the solid immediate outline; do not rely on translucent shadows'

def luminance(rgb):
    def linear(v):
        v /= 255
        return v / 12.92 if v <= .04045 else ((v + .055) / 1.055) ** 2.4
    return sum(c * linear(v) for c, v in zip((.2126, .7152, .0722), rgb))

def contrast(a, b):
    lo, hi = sorted((luminance(a), luminance(b)))
    return (hi + .05) / (lo + .05)

white = (255, 255, 255)
for background in ((33, 37, 41), (36, 38, 41), (0, 0, 0)):
    ratio = contrast(white, background)
    assert ratio >= 3, (background, ratio)
    print('PASS solid navbar outline contrast', background, ratio)

# Sensitivity checks: the observed Bootstrap shadow and brand red are insufficient.
background = (33, 37, 41)
composite = tuple(.25 * fg + .75 * bg for fg, bg in zip((13, 110, 253), background))
assert contrast(composite, background) < 3
assert contrast((219, 3, 53), background) < 3
assert contrast((0, 0, 0), white) == 21
assert contrast(white, white) == 1
print('PASS source contract and contrast sensitivity controls (not browser rendering)')
