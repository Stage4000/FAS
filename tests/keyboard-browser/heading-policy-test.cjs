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
