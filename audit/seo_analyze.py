"""Create the per-listing SEO queue from saved public crawl evidence."""
import csv
import json
import re
import zipfile
from collections import Counter, defaultdict
from pathlib import Path

OUT = Path(__file__).resolve().parent
def read(name):
    if (OUT/name).exists():
        return json.loads((OUT/name).read_text(encoding='utf-8'))
    with zipfile.ZipFile(OUT/'SEO-evidence.zip') as archive:
        return json.loads(archive.read(name).decode('utf-8'))

def available(name):
    if (OUT/name).exists(): return True
    if not (OUT/'SEO-evidence.zip').exists(): return False
    with zipfile.ZipFile(OUT/'SEO-evidence.zip') as archive:
        return name in archive.namelist()
def schema(page, kind): return next((x for x in page.get('schemas',[]) if isinstance(x,dict) and x.get('@type')==kind), {})
def tokens(text): return set(re.findall(r'[a-z0-9]{3,}',text.lower())) - {'the','and','for','with','from','new','used','parts','part'}

def main():
    pages=read('seo-pages.json'); items=read('seo-feed.json')
    byurl={x['url']:x for x in pages}
    # Successful targeted retries replace failed initial reads for analysis.
    if available('seo-retries.json'):
        for x in read('seo-retries.json'):
            if x['status']==200: byurl[x['url']]=x
    images={x['url']:x for x in read('seo-images.json')} if available('seo-images.json') else {}
    title_counts=Counter(x.get('title',[''])[0] for x in byurl.values() if x.get('title') and '/product/' in x['url'])
    desc_counts=Counter(x.get('meta',{}).get('description','') for x in byurl.values() if '/product/' in x['url'] and x['status']==200)
    schema_desc_counts=Counter(schema(x,'Product').get('description','') for x in byurl.values() if '/product/' in x['url'] and x['status']==200)
    rows=[]; groups=defaultdict(list)
    for f in sorted(items,key=lambda x:int(x['link'].split('/')[4])):
        p=byurl.get(f['link'],{}); s=schema(p,'Product'); offer=s.get('offers',{})
        title=(p.get('title') or [''])[0]; meta=p.get('meta',{}); description=meta.get('description','')
        primary=next((x for x in p.get('images',[]) if x.get('id')=='main-product-image'),{})
        flags=[]
        if p.get('status')!=200: flags.append('FETCH_UNVERIFIED')
        else:
            if p.get('canonical')!=[f['link']]:flags.append('CANONICAL_REVIEW')
            if 'noindex' in meta.get('robots',''):flags.append('NOINDEX_PRODUCT')
            if len([x for x in p.get('headings',[]) if x[0]=='h1'])!=1:flags.append('H1_REVIEW')
            if not title:flags.append('MISSING_TITLE')
            if not description:flags.append('MISSING_META')
            if len(title)>70:flags.append('LONG_TITLE_REVIEW')
            if title_counts[title]>1:flags.append('DUPLICATE_TITLE')
            if desc_counts[description]>1:flags.append('DUPLICATE_META')
            if not s or p.get('json_errors'):flags.append('SCHEMA_ERROR')
            if schema_desc_counts[s.get('description','')]>1:flags.append('DUPLICATE_SCHEMA_DESCRIPTION')
            if not primary.get('width') or not primary.get('height'):flags.append('IMAGE_DIMENSIONS')
            if not primary.get('srcset'):flags.append('RESPONSIVE_IMAGE')
            if not primary.get('alt'):flags.append('MISSING_MAIN_ALT')
            want=f.get('sale_price') or f['price']
            if str(offer.get('price'))!=want.split()[0]:flags.append('PRICE_MISMATCH')
            if offer.get('availability','').split('/')[-1] != {'in_stock':'InStock','out_of_stock':'OutOfStock'}.get(f['availability']):flags.append('AVAILABILITY_MISMATCH')
            if f['image_link'] not in s.get('image',[]):flags.append('SCHEMA_FEED_IMAGE_MISMATCH')
            st=tokens(s.get('description','')); nt=tokens(f['title'])
            if nt and len(nt & st)/len(nt)<0.25: flags.append('DESCRIPTION_RELEVANCE_REVIEW')
        if re.search('ebay|IMPORTANT BUYER NOTICE|porch pirates',f['description'],re.I):flags.append('MARKETPLACE_BOILERPLATE')
        if 'ebay shipping calculator' in f['description'].lower():flags.append('EBAY_SHIPPING_COPY')
        if len(f['description'].split())<50:flags.append('SHORT_DESCRIPTION_REVIEW')
        if not f.get('mpn'):flags.append('MISSING_MPN_REVIEW')
        if f.get('mpn','').lower().strip() in ['does not apply','n/a','na','none','unknown','no','not applicable']:flags.append('PLACEHOLDER_MPN')
        if images.get(f['image_link'],{}).get('status')!=200:flags.append('IMAGE_HTTP_REVIEW')
        if f['link'].split('/')[4]=='5649': flags.append('CONFIRMED_WRONG_DESCRIPTION')
        if f['condition']=='new':flags.append('USED_TEMPLATE_ON_NEW_ITEM')
        row={'product_id':f['link'].split('/')[4], 'feed_id':f['id'], 'url':f['link'],'title':title,
             'title_chars':len(title),'meta_description':description,'meta_chars':len(description),
             'http_status':p.get('status',0),'canonical':' | '.join(p.get('canonical',[])),
             'robots':meta.get('robots',''),'brand':f.get('brand',''),'mpn':f.get('mpn',''),
             'condition':f['condition'],'category':f['product_type'],'description_words':len(f['description'].split()),
             'schema_description':s.get('description',''),'image_count':len(s.get('image',[])),
             'primary_image_http':images.get(f['image_link'],{}).get('status',0),'flags':'; '.join(flags)}
        rows.append(row)
        for flag in flags: groups[flag].append(row['product_id'])
    with (OUT/'SEO-listings.csv').open('w',encoding='utf-8-sig',newline='') as fh:
        writer=csv.DictWriter(fh,fieldnames=list(rows[0]));writer.writeheader();writer.writerows(rows)
    summary={'listing_count':len(rows),'http_status':dict(Counter(x['http_status'] for x in rows)),
             'flags':{k:{'count':len(v),'product_ids':v} for k,v in sorted(groups.items())},
             'schema_description_duplicates':[{ 'description':k,'count':v} for k,v in schema_desc_counts.items() if v>1],
             'feed_categories':dict(Counter(f['product_type'].split(' > ')[0] for f in items))}
    (OUT/'seo-summary.json').write_text(json.dumps(summary,indent=2,ensure_ascii=False),encoding='utf-8')
    print(json.dumps({'listings':len(rows),'statuses':summary['http_status'],'flags':{k:v['count'] for k,v in summary['flags'].items()}},indent=2))

if __name__=='__main__':main()
