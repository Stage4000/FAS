/* Pure test-local request predicate negatives; no browser/server/network/application execution. */
'use strict';const assert=require('node:assert/strict'),p=require('./email-restore.cjs');
const origin='http://127.0.0.1:12345',doc=new URL(origin+'/recover-cart.php'),api=new URL(origin+'/api/growth.php');let checks=0;
function check(test){assert(test);checks++}
check(p.allowRecoverDocument(doc,origin,'GET','document'));
for(const [url,method,type]of [[new URL('https://example.invalid/recover-cart.php'),'GET','document'],[new URL(origin+'/recover-cart.php?token=fake'),'GET','document'],[new URL(origin+'/recover-cart.php/extra'),'GET','document'],[new URL(origin+'/api/growth.php'),'GET','document'],[doc,'POST','document'],[doc,'GET','fetch']])check(!p.allowRecoverDocument(url,origin,method,type));
check(p.allowRestoreApi(api,origin,'GET','fetch'));
for(const token of [p.A,p.B])check(p.allowRestoreApi(api,origin,'POST','fetch',{action:'restore',csrf:p.CSRF,token},'application/json; charset=utf-8'));
const good={action:'restore',csrf:p.CSRF,token:p.A};
for(const [url,method,type,body,ct]of [
 [new URL('https://example.invalid/api/growth.php'),'POST','fetch',good,'application/json'],
 [new URL(origin+'/api/growth.php?x=1'),'POST','fetch',good,'application/json'],
 [new URL(origin+'/api/other.php'),'POST','fetch',good,'application/json'],
 [api,'POST','xhr',good,'application/json'],[api,'DELETE','fetch',good,'application/json'],[api,'POST','fetch',good,'text/plain'],
 [api,'GET','fetch',good,''],[api,'POST','fetch',null,'application/json'],[api,'POST','fetch',[],'application/json'],
 [api,'POST','fetch',{...good,action:'unsubscribe'},'application/json'],[api,'POST','fetch',{...good,csrf:'other'},'application/json'],
 [api,'POST','fetch',{...good,token:'real-looking-unapproved-token'},'application/json'],[api,'POST','fetch',{...good,extra:'no'},'application/json'],
 [api,'POST','fetch',{action:'restore',csrf:p.CSRF},'application/json'],
])check(!p.allowRestoreApi(url,origin,method,type,body,ct));
console.log(JSON.stringify({passed:true,checks,scope:'pure exact recover document and inert client API predicate negatives; no browser/server/network/application execution'}));
