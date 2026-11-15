'use strict';
// Pure URL predicates and exact CSS controls, no browser/server/network.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {allowedScrollFetch,allowedScrollDocument}=require('./catalog-scroll-policy.cjs');
const {AFTER,BEFORE,NEW,digest,baselineCss}=require('./catalog-scroll-identity.cjs');
const origin='http://127.0.0.1:43210';let checks=0;
const check=(actual,expected)=>{checks++;assert.equal(actual,expected);};
for(const query of ['page=2&category=motorcycle&include_sidebar=1','category=motorcycle&search=Synthetic&include_sidebar=1','&include_sidebar=1']) {
    const target=origin+'/api/products.php?'+query;
    check(allowedScrollFetch(new URL(target),origin,'GET','fetch'),true);
    for(const [url,method,type] of [[target,'POST','fetch'],[target,'GET','xhr'],[target,'GET','document'],
        [target.replace('127.0.0.1','example.invalid'),'GET','fetch'],[target+'&extra=1','GET','fetch'],
        [target+'#fragment','GET','fetch'],[target.replace('/api/products.php','/api/growth.php'),'GET','fetch']])
        check(allowedScrollFetch(new URL(url),origin,method,type),false);
}
for(const suffix of ['/products','/products?category=motorcycle']) {
    const target=origin+suffix;
    check(allowedScrollDocument(new URL(target),origin,'GET','document'),true);
    for(const [url,method,type] of [[target,'POST','document'],[target,'GET','fetch'],
        [target.replace('127.0.0.1','example.invalid'),'GET','document'],[target+'#x','GET','document'],
        [target+(suffix.includes('?')?'&':'?')+'extra=1','GET','document']])
        check(allowedScrollDocument(new URL(url),origin,method,type),false);
}
for(const suffix of ['/cart','/admin/','/products/motorcycle?page=2','/products?category=motorcycle&category=boat',
    '/products?category=boat','/products?search=Synthetic','/products?category=motorcycle&page=2'])
    check(allowedScrollDocument(new URL(origin+suffix),origin,'GET','document'),false);
for(const query of ['include_sidebar=1','page=3&category=motorcycle&include_sidebar=1',
    'page=2&category=boat&include_sidebar=1','page=2&category=motorcycle&include_sidebar=1&include_sidebar=1',
    'category=motorcycle&search=Other&include_sidebar=1','category=motorcycle&page=2&include_sidebar=1'])
    check(allowedScrollFetch(new URL(origin+'/api/products.php?'+query),origin,'GET','fetch'),false);
const candidate=fs.readFileSync(path.join(__dirname,'../../public/css/style.css'));
check(digest(candidate),AFTER);const baseline=baselineCss(candidate);check(digest(baseline),BEFORE);
for(const mutation of [baseline,Buffer.concat([candidate,Buffer.from('changed')]),
    Buffer.from(candidate.toString().replace(NEW,NEW+'\n'+NEW)),
    Buffer.from(candidate.toString().replace('6rem','0'))]) {
    checks++;assert.throws(()=>baselineCss(mutation),/exact reviewed catalog CSS required/);
}
console.log(JSON.stringify({passed:true,checks,scope:'pure exact CSS and URL controls; not browser acceptance'}));
