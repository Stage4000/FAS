"""Read-only public SEO inventory. Standard library only; run from repository root.

Fetches sitemap/feed and every discovered product with four workers. No login,
checkout, API mutation, or database connection. Results are dated snapshots.
"""
import concurrent.futures as futures
import csv
import datetime as dt
import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from collections import Counter
from html.parser import HTMLParser
from pathlib import Path

BASE = 'https://flipandstrip.com'
OUT = Path(__file__).resolve().parent

class Parser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.meta = {}; self.canonicals = []; self.images = []; self.links = []
        self.headings = []; self.titles = []; self.schemas = []; self.errors = []
        self.active = None; self.parts = []; self.body = []; self.skip = 0
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'meta': self.meta[a.get('name', a.get('property', ''))] = a.get('content', '')
        if tag == 'link' and a.get('rel') == 'canonical': self.canonicals.append(a.get('href', ''))
        if tag == 'img': self.images.append(a)
        if tag == 'a' and a.get('href'): self.links.append(a['href'])
        if tag in ('title', 'h1', 'h2', 'h3') or (tag == 'script' and a.get('type') == 'application/ld+json'):
            self.active = tag; self.parts = []
        if tag in ('script', 'style'): self.skip += 1
    def handle_data(self, data):
        if self.active: self.parts.append(data)
        if not self.skip: self.body.append(data)
    def handle_endtag(self, tag):
        if self.active == tag:
            s = ' '.join(''.join(self.parts).split())
            if tag == 'title': self.titles.append(s)
            elif tag == 'script':
                try: self.schemas.append(json.loads(''.join(self.parts)))
                except ValueError as e: self.errors.append(str(e))
            else: self.headings.append([tag, s])
            self.active = None
        if tag in ('script', 'style'): self.skip = max(0, self.skip - 1)

class Redirects(urllib.request.HTTPRedirectHandler):
    def __init__(self): self.chain = []
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        self.chain.append({'status': code, 'from': req.full_url, 'to': newurl})
        return super().redirect_request(req, fp, code, msg, headers, newurl)

def fetch(url):
    handler = Redirects(); start = time.monotonic()
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'FAS-SEO-Audit/1.0 (site-owner audit)'})
        try: r = urllib.request.build_opener(handler).open(req, timeout=35)
        except urllib.error.HTTPError as e: r = e
        with r: body = r.read(); status = r.status; final = r.url; headers = dict(r.headers)
        return {'url': url, 'status': status, 'final_url': final, 'redirects': handler.chain,
                'seconds': round(time.monotonic()-start, 3), 'bytes': len(body),
                'headers': {k:v for k,v in headers.items() if k.lower() in ['content-type','x-robots-tag','content-encoding','cache-control','last-modified']},
                'html': body.decode('utf-8', errors='replace')}
    except Exception as e: return {'url': url, 'status': 0, 'error': str(e), 'html': ''}

def inspect(url):
    r = fetch(url); p = Parser(); p.feed(r.pop('html'))
    r.update(title=p.titles, meta=p.meta, canonical=p.canonicals, headings=p.headings,
             schemas=p.schemas, json_errors=p.errors, images=p.images,
             links=sorted(set(urllib.parse.urljoin(r.get('final_url',url), x) for x in p.links)))
    text = ' '.join(' '.join(p.body).split())
    r['visible_text'] = text
    return r

def save(name, data):
    (OUT/name).write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding='utf-8')

def main():
    stamp = dt.datetime.now(dt.timezone.utc).isoformat()
    site = fetch(BASE+'/sitemap.xml'); feed = fetch(BASE+'/google-merchant-feed.php')
    robots = fetch(BASE+'/robots.txt')
    save('seo-endpoints.json', {'at':stamp, 'sitemap':site, 'feed':feed, 'robots':robots})
    urls = [x.text for x in ET.fromstring(site['html']).iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc')]
    items = []
    for item in ET.fromstring(feed['html']).findall('.//item'):
        row = {}
        for child in item:
            key = child.tag.split('}')[-1]
            if key == 'additional_image_link': row.setdefault(key, []).append(child.text)
            else: row[key] = child.text or ''
        items.append(row)
    save('seo-feed.json', items)
    seeds = sorted(set(urls + [x['link'] for x in items]))
    pages = []
    with futures.ThreadPoolExecutor(max_workers=4) as pool:
        for i, r in enumerate(pool.map(inspect, seeds), 1):
            pages.append(r)
            if i % 50 == 0: print(f'Crawled {i}/{len(seeds)}', flush=True); save('seo-pages.json', pages)
    save('seo-pages.json', pages)
    save('seo-run.json', {'at':stamp,'finished_at':dt.datetime.now(dt.timezone.utc).isoformat(),
         'sitemap_urls':urls,'seed_count':len(seeds),'feed_count':len(items),'status_counts':dict(Counter(r['status'] for r in pages))})
    print('Complete',len(pages),dict(Counter(r['status'] for r in pages)),flush=True)

if __name__ == '__main__': main()
