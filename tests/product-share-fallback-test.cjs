'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(process.argv[2] || __dirname + '/../product.php', 'utf8');
const start = source.indexOf('// Share button functionality\n');
const end = source.indexOf('\n</script>', start);
assert(start >= 0 && end > start, 'exact share fragment present');
const code = source.slice(start, end);
let checks = 0;
async function scenario(name, mode, outcome, expected, attached=true) {
  let handler, removed=0, appended=0, selected=0, ranges=0, calls=0;
  const notices=[];
  const textarea={style:{},setAttribute(){}, select(){selected++},setSelectionRange(){ranges++},remove(){removed++}};
  const document={getElementById(id){assert.equal(id,'share-button'); return attached?{addEventListener(type,fn){assert.equal(type,'click');handler=fn}}:null},createElement(tag){assert.equal(tag,'textarea');return textarea},body:{appendChild(){appended++}},execCommand(command){calls++;assert.equal(command,'copy');if(outcome==='throw')throw new Error('synthetic denial');return outcome}};
  const navigator=mode==='modern'?{clipboard:{writeText(url){calls++;assert.equal(url,'https://example.invalid/product');return outcome==='reject'?Promise.reject(new Error('synthetic denial')):Promise.resolve()}}}:{};
  vm.runInNewContext(code,{document,navigator,window:{location:{href:'https://example.invalid/product'}},console:{error(){}},showNotification(message,type){notices.push({message,type})}});
  if(!attached){assert.equal(handler,undefined);assert.equal(calls,0);checks+=2;return;}
  handler();await new Promise(resolve=>setImmediate(resolve));
  assert.equal(notices.length,1,name+' one feedback');assert.equal(notices[0].type,expected,name+' correct outcome');assert.equal(calls,1,name+' one copy');checks+=3;
  if(mode==='legacy'){assert.equal(removed,1);assert.equal(appended,1);assert.equal(selected,1);assert.equal(ranges,1);checks+=4;}else{assert.equal(appended,0);assert.equal(removed,0);checks+=2;}
}
(async()=>{
 const failures=[];
 for(const args of [['legacy successful copy','legacy',true,'success'],['legacy rejected copy','legacy',false,'danger'],['legacy thrown copy','legacy','throw','danger'],['modern successful copy','modern','resolve','success'],['modern rejected copy','modern','reject','danger'],['missing share button','legacy',true,'success',false]]){
  try{await scenario(...args)}catch(e){failures.push({scenario:args[0],error:e.message})}
 }
 console.log(JSON.stringify({scenarios:6,checks,failures},null,2)); if(failures.length)process.exitCode=1;
})();
