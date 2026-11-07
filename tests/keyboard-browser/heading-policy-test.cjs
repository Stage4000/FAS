/* Pure policy checks. No browser imports or network access. */
'use strict';
const assert=require('node:assert/strict');
const p=require('./heading-policy.cjs');
const origin='http://127.0.0.1:8123';
let checks=0;
function check(value,expected){checks++;assert.equal(value,expected);}
for(const search of [p.SEARCH_EMPTY,p.SEARCH_FULL]) {
 const url=new URL(`/api/products.php?search=${search}&include_sidebar=1`,origin);
 check(p.allowedCatalogFetch(url,origin,'GET','fetch'),true);
 for(const method of ['POST','PUT','DELETE','PATCH'])check(p.allowedCatalogFetch(url,origin,method,'fetch'),false);
 for(const type of ['document','xhr','script','image','websocket'])check(p.allowedCatalogFetch(url,origin,'GET',type),false);
 for(const extra of ['&other=1','&search=evil','&include_sidebar=0'])check(p.allowedCatalogFetch(new URL(url.href+extra),origin,'GET','fetch'),false);
 check(p.allowedCatalogFetch(new URL(url.href.replace('127.0.0.1','example.invalid')),origin,'GET','fetch'),false);
}
for(const suffix of ['/','/products','/cart','/product/1/synthetic-test-part',`/products?search=${p.SEARCH_EMPTY}`])check(p.allowedDocument(new URL(suffix,origin),origin,'GET','document'),true);
for(const suffix of ['/public/test.html','/gallery/default.jpg','/api/products.php','/admin/','/products?show_hidden=1','/products?search=other']) {
 const url=new URL(suffix,origin);
 check(p.allowedDocument(url,origin,'GET','document'),false);
 check(p.allowedLocalAsset(url,origin,'GET','document'),false);
}
for(const [suffix,type] of [['/public/js/main.js','script'],['/public/css/style.css','stylesheet'],['/gallery/default.jpg','image'],['/public/fonts/sample.woff2','font']]) {
 const url=new URL(suffix,origin);
 check(p.allowedLocalAsset(url,origin,'GET',type),true);
 check(p.allowedLocalAsset(url,origin,'POST',type),false);
 check(p.allowedLocalAsset(url,origin,'GET','document'),false);
 check(p.allowedLocalAsset(new URL(url.href.replace('127.0.0.1','example.invalid')),origin,'GET',type),false);
}
for(const type of ['document','fetch','xhr','websocket','eventsource','other'])check(p.allowedCachedType(type),false);
for(const type of ['script','stylesheet','image','font'])check(p.allowedCachedType(type),true);
console.log(JSON.stringify({checks,passed:true,scope:'pure request policy only'}));

{
/* Pure observer fixtures: no Playwright import, server, application or network. */
'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/heading.cjs','utf8');
const observeSource=source.slice(source.indexOf('function observe(surface)'),source.indexOf('\n(async()=>'));
let checks=0;
function run(surface,restore=[],missing=null,attribute=null,omit=null){
 const make=(text,tag='H2',code=null)=>({textContent:text,tagName:tag,code,
  getBoundingClientRect:()=>({x:0,y:0,width:100,height:20}),checkVisibility:()=>true,
  hasAttribute:name=>!!attribute && code===attribute.code && name===attribute.name,getAttribute:()=>null,closest:()=>null,
  classList:{contains:()=>true},compareDocumentPosition:()=>4,
  parentElement:{contains:()=>true},querySelector:()=>null});
 const items=[make('Synthetic linked <part> & title','H2','CART_ITEM'),make('Synthetic plain <part> & title','H2','CART_ITEM')];
 items[0].querySelector=s=>s==='a'?{getAttribute:()=>'/product/1'}:null;
 const status=make('Your cart is empty','P','CART_EMPTY_STATUS'),summary=make('Order Summary','H2','CART_SUMMARY'),shipping=make('Shipping Estimate','H3','CART_SHIPPING'),payment=make('We Accept','P','CART_PAYMENT_LABEL'),seller=make('Synthetic Heading Seller','P','SELLER_IDENTITY');
 const nodes=[status,summary,shipping,payment,seller,...items];
 const tags={CART_EMPTY_STATUS:'H3',CART_SUMMARY:'H4',CART_SHIPPING:'H5',CART_PAYMENT_LABEL:'H6',CART_ITEM:'H6',SELLER_IDENTITY:'H6'};
 nodes.forEach(n=>{if(restore.includes(n.code))n.tagName=tags[n.code];});
 const footer=['FLIP AND STRIP','Quick Links','Connect'].map(t=>make(t));
 const all=[make('Page','H1'),...nodes,...footer];
 const selectors={
  'main h1':[all[0]],
  '#empty-cart-message > .card-body > *':[status],
  '.order-summary-mobile > .card-body > *':[summary],
  '.shipping-estimator-card > .card-body > *':[shipping],
  '.order-summary-mobile .mt-4.pt-3.border-top > *':[payment],
  '#cart-items-container .cart-item-details > .fw-bold':items,
  'main [data-theme-card] .text-uppercase + *':[seller],
  'footer p':[make('Synthetic Heading Seller','P')],
  'footer h1,footer h2,footer h3,footer h4,footer h5,footer h6':footer,
 };
 const document={querySelectorAll:s=>{
  const found=s==='h1,h2,h3,h4,h5,h6'?all.filter(n=>/^H\d$/.test(n.tagName)):
   s.startsWith('h1,h2,h3,h4,h5,h6,')?all.filter(n=>/^H\d$/.test(n.tagName) || n===status && s.includes('#empty-cart-message > .card-body > p.h3') || n===payment && s.includes('.order-summary-mobile .mt-4.pt-3.border-top > p.h6') || n===seller && s.includes('main [data-theme-card] .text-uppercase + p.h6')):selectors[s]||[];
  return found.filter(n=>n.code!==missing);
 },querySelector:()=>null,documentElement:{getAttribute:()=> 'light',scrollWidth:390}};
 const ctx={document,Node:{DOCUMENT_POSITION_FOLLOWING:4},innerWidth:390,getComputedStyle:()=>({visibility:'visible',display:'block'})};
 vm.createContext(ctx);return JSON.parse(JSON.stringify(vm.runInContext((omit?observeSource.replace(','+omit,''):observeSource)+';observe('+JSON.stringify(surface)+')',ctx)));
}
const codes=['CART_EMPTY_STATUS','CART_SUMMARY','CART_SHIPPING','CART_PAYMENT_LABEL','CART_ITEM','SELLER_IDENTITY'];
const residualFailures=r=>r.assertions.filter(a=>!a.ok&&codes.includes(a.code)).map(a=>a.code);
for(const code of codes){
 const surface=code==='SELLER_IDENTITY'?'product':'cart-populated';
 const good=run(surface),bad=run(surface,[code]);
 assert.deepEqual(residualFailures(good),[]);checks++;
 assert.deepEqual(residualFailures(bad),[code]);checks++;
 assert.equal(good.visual.length,bad.visual.length);checks++;
 assert.deepEqual(good.visual.map(v=>v.text),bad.visual.map(v=>v.text));checks++;
 const absent=run(surface,[],code);
 assert(absent.assertions.some(a=>!a.ok&&['RESIDUAL_TARGET_PRESENT','CART_ITEM_COUNT'].includes(a.code)));checks++;
}
for(const code of codes) for(const name of ['role','aria-level','tabindex']) {
 const r=run(code==='SELLER_IDENTITY'?'product':'cart-populated',[],null,{code,name});
 if(code!=='CART_ITEM') { assert(residualFailures(r).includes(code)); checks++; }
}
for(const clause of ['#empty-cart-message > .card-body > p.h3','.order-summary-mobile .mt-4.pt-3.border-top > p.h6','main [data-theme-card] .text-uppercase + p.h6']) {
 assert.equal(run('cart-populated',[],null,null,clause).visual.length,run('cart-populated').visual.length-1);checks++;
}
console.log(JSON.stringify({checks,passed:true,scope:'pure actual observe function with synthetic DOM objects; not browser acceptance'}));

}
