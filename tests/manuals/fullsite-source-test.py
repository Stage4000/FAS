#!/usr/bin/env python3
"""Source-contract integration checks, not browser/CSP/production acceptance.
Requires an independently retrieved exact main snapshot; never fetches it itself.
"""
import argparse, hashlib, os, re, subprocess
from pathlib import Path
from html.parser import HTMLParser
p=argparse.ArgumentParser();p.add_argument('--shared-root',required=True);p.add_argument('--candidate-root',default=str(Path(__file__).resolve().parents[2]));p.add_argument('--php',default=os.environ.get('FAS_TEST_PHP','php'));args=p.parse_args()
root=Path(args.candidate_root).resolve(); shared=Path(args.shared_root).resolve()
pins={'includes/header.php':'b8ecc74f67f306afa142339b1641fc916a624e2c','includes/footer.php':'2ad0d3cb8581fc09e71d307650606149c6dc7009','admin/includes/nav.php':'ee99d51f04701b4d25cdbe8478fcb7ede55257a6','admin/includes/footer.php':'ba7ab9e938b550bc09cd54608bacc6d7b28c22a8','admin/css/admin-style.css':'61aea4e802731d01af115ae4688ea5088f91f47c','public/css/style.css':'b6c36199b40dc6af75d6a5433671b01cf9c8b052','.htaccess':'f3cf9228476acf087b16cfd6782f6fb678d941f6'}
checks=0
def check(condition,label):
 global checks
 assert condition,label
 checks+=1;print('PASS '+label)
for name,expected in pins.items():
 data=(shared/name).read_bytes();check(hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()==expected,'exact main source '+name)
css=(root/'public/css/manuals.css').read_text();admin=(shared/'admin/css/admin-style.css').read_text()
def declarations(text,selector):
 text=re.sub(r'/\*.*?\*/','',text,flags=re.S)
 match=re.search(r'(?:^|})\s*'+re.escape(selector)+r'\s*\{([^{}]*)\}',text);assert match,selector
 return dict((k.strip(),v.strip()) for k,v in re.findall(r'([\w-]+)\s*:\s*([^;{}]+)',re.sub(r'/\*.*?\*/','',match[1],flags=re.S)))
def resolve(value,variables):
 # Only the simple custom-property expressions used by these exact source rules.
 for _ in range(12):
  result=re.sub(r'var\((--[\w-]+)(?:,([^()]+))?\)',lambda m:variables.get(m[1],m[2] or ''),value)
  if result==value:return result.strip()
  value=result
 raise AssertionError('recursive custom property')
def luminance(h):
 h=h.strip('#');h=''.join(x*2 for x in h) if len(h)==3 else h
 channels=[int(h[i:i+2],16)/255 for i in (0,2,4)]
 return sum(w*(x/12.92 if x<=.04045 else ((x+.055)/1.055)**2.4) for w,x in zip((.2126,.7152,.0722),channels))
manual=declarations(css,'.manual-admin');body=declarations(admin,'body');fields=declarations(css,'.manual-form input,.manual-form select,.manual-form textarea')
for theme in ('dark','light'):
 values=declarations(admin,':root')
 if theme=='dark':values.update(declarations(admin,'[data-theme="dark"]'))
 values.update({k:v for k,v in manual.items() if k.startswith('--')})
 ink=resolve(manual['color'],values);bg=resolve(body['background-color'].replace('!important',''),values)
 a,b=sorted((luminance(ink),luminance(bg)));check((b+.05)/(a+.05)>=4.5,theme+' manual text contrast against real admin background')
 check(luminance(resolve(fields['background'],values))==luminance(values['--admin-card-bg']),theme+' form uses actual admin surface')
 check(ink==values['--admin-text'],theme+' content uses actual admin text')
# Render only wrapper functions with the actual pinned nav/footer. No auth, DB,
# scripts or remote resources execute. Read-only PHP includes produce HTML.
common=(root/'admin/manuals-common.php').read_text();start=common.index('function manualsAdminHeader');end=common.index('\nif(!',start)
functions=common[start:end].replace('__DIR__',repr(str(shared/'admin')))
php="$_SERVER['PHP_SELF']='/admin/manuals.php';$_SESSION=['admin_username'=>'Synthetic'];function manualsH($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}"+functions+"manualsAdminHeader('Synthetic <title>');echo '<p>Fixture</p>';manualsAdminFooter();"
result=subprocess.run([args.php,'-d','display_errors=1','-r',php],capture_output=True,text=True,check=True)
check(not result.stderr,'real shared wrapper emits no PHP warnings')
class Links(HTMLParser):
 def __init__(self):super().__init__();self.links=[]
 def handle_starttag(self,tag,attrs):
  if tag=='link':self.links.append(dict(attrs))
links=Links();links.feed(result.stdout)
icon=[x for x in links.links if 'font-awesome/' in x.get('href','')]
check(len(icon)==1,'shared navigation and theme toggle receive icon font')
check(icon[0].get('integrity')=='sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==' and icon[0].get('crossorigin')=='anonymous','icon resource uses existing storefront pin')
check('id="navbarThemeToggle"' in result.stdout and 'id="themeToggle"' in result.stdout,'actual shared theme controls rendered')
check('Synthetic &lt;title&gt;' in result.stdout,'wrapper title escaped')
check(result.stdout.count('<main ')==1 and result.stdout.count('</main>')==1,'manual main wrapper balanced')
print(f'{checks} source integration checks passed; no browser or production acceptance claimed.')
