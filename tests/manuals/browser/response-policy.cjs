'use strict';
// These are the only intentionally exercised authorization-denial responses.
const ALLOWED_DENIALS=new Set([401,403,404]);
function unexpectedHttpResponse(pathname,status,expected=[]){
  if(!Number.isInteger(status)||status<100||status>599)throw new TypeError('Invalid HTTP status');
  if(!Array.isArray(expected)||expected.some(x=>!x||typeof x.path!=='string'||!x.path.startsWith('/')||!ALLOWED_DENIALS.has(x.status)))throw new TypeError('Invalid expected-denial policy');
  if(status<400)return false;
  return !expected.some(x=>x.path===pathname&&x.status===status);
}
module.exports={unexpectedHttpResponse};
