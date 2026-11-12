"""Exact feature-rule/contrast contracts, not browser cascade or screenshot acceptance."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[2]
css=(root/'public/css/manuals.css').read_text()
checks=0

def check(value,label):
 global checks
 assert value,label
 checks+=1;print('PASS '+label)
def rules(selector):
 found=[]
 for selectors,body in re.findall(r'([^{}]+)\{([^{}]*)\}',css):
  selectors=re.sub(r'/\*.*?\*/','',selectors,flags=re.S).strip()
  if selector in [s.strip() for s in selectors.split(',')]:
   found.append(dict(re.findall(r'([\w-]+)\s*:\s*([^;]+)',body)))
 return {key:val.strip() for d in found for key,val in d.items()}
def lum(color):
 channels=[int(color[i:i+2],16)/255 for i in (1,3,5)]
 channels=[x/12.92 if x<=.04045 else ((x+.055)/1.055)**2.4 for x in channels]
 return sum(x*y for x,y in zip(channels,[.2126,.7152,.0722]))
def ratio(a,b):
 x,y=lum(a),lum(b);return(max(x,y)+.05)/(min(x,y)+.05)
light=rules('.manual-library');dark=rules('[data-theme="dark"] .manual-library')
for selector in ['.manual-library .manual-eyebrow','.manual-library h1 span','.manual-library .manual-read-link','.manual-admin a:not(.manual-button)','.manual-catalog-search>a','.manual-empty>a']:
 check(rules(selector).get('color')=='var(--manual-text-accent)','scoped readable text rule '+selector)
for theme,tokens,backgrounds in [('light',light,['#ffffff','#f8f9fa']),('dark',dark,['#2d2d2d','#1a1a1a','#121212'])]:
 for bg in backgrounds:
  cr=ratio(tokens['--manual-text-accent'],bg);check(cr>=4.5,f'{theme} text accent {cr:.3f}:1 on {bg}')
  cr=ratio(tokens['--manual-placeholder'],bg);check(cr>=4.5,f'{theme} placeholder {cr:.3f}:1 on {bg}')
check(rules('.manual-admin')['--manual-text-accent']==light['--manual-text-accent'],'admin light accent matches catalog')
check(rules('[data-theme="dark"] .manual-admin')['--manual-text-accent']==dark['--manual-text-accent'],'admin dark accent matches catalog')
p=rules('.manual-library .manual-catalog-search input::placeholder')
check(p.get('color')=='var(--manual-placeholder)'and p.get('opacity')=='1','placeholder color and full opacity explicit')
p=rules('.manual-admin .manual-form input::placeholder')
check(p.get('color')=='var(--manual-placeholder)'and p.get('opacity')=='1','admin placeholder color and full opacity explicit')
check(rules('.manual-admin')['--manual-placeholder']==light['--manual-placeholder'],'admin light placeholder matches catalog')
check(rules('[data-theme="dark"] .manual-admin')['--manual-placeholder']==dark['--manual-placeholder'],'admin dark placeholder matches catalog')
for selector in ['.manual-button','.manual-form button','.manual-catalog-search button']:
 r=rules(selector);check(r.get('background')=='var(--manual-red)'and r.get('color')=='#fff','filled control unchanged '+selector)
check(rules('.manual-library')['--manual-red']=='#db0335','brand fill unchanged')
check('--manual-red' not in dark,'dark theme does not lighten button fill')
check(ratio('#ffffff','#db0335')>=4.5,'white button text retains contrast')
print(f'{checks} source contrast checks passed; computed browser styles remain a hosted acceptance gate.')
