const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../public/js/manual-reader.mjs','utf8');
const helpers=source.slice(source.indexOf('export function spreadStart'),source.indexOf('const root = document.querySelector')).replaceAll('export ','');
const context={URLSearchParams};vm.createContext(context);vm.runInContext(helpers,context);
let checks=0;const check=(label,fn)=>{fn();checks++;console.log('PASS '+label);};
const cache=context.createTextCache(12,3);
check('empty counters',()=>{assert.equal(cache.size,0);assert.equal(cache.characters,0)});
check('counts UTF-16 units, not page count only',()=>{cache.set(1,'12345');cache.set(2,'67890');assert.equal(cache.characters,10)});
check('character pressure evicts oldest',()=>{cache.set(3,'abcde');assert(!cache.has(1));assert.equal(cache.characters,10)});
check('read refreshes LRU',()=>{assert.equal(cache.get(2),'67890');cache.set(4,'abcde');assert(!cache.has(3));assert(cache.has(2))});
check('replacement adjusts accounting',()=>{cache.set(2,'x');assert.equal(cache.characters,6)});
check('oversized text bypasses cache without truncation',()=>{cache.set(5,'a'.repeat(13));assert(!cache.has(5));assert.equal(cache.characters,6)});
check('oversized replacement removes stale entry',()=>{cache.set(2,'a'.repeat(13));assert(!cache.has(2));assert.equal(cache.characters,5)});
check('empty text is a cached hit',()=>{cache.set(6,'');assert(cache.has(6));assert.equal(cache.get(6),'')});
check('entry limit includes empty pages',()=>{cache.set(7,'');cache.set(8,'');assert.equal(cache.size,3);assert(!cache.has(4));assert.equal(cache.characters,0)});
check('clear releases all cache references',()=>{cache.clear();assert.equal(cache.characters,0);assert.equal(cache.size,0)});
check('miss is undefined',()=>assert.equal(cache.get('missing'),undefined));
check('invalid bounds rejected',()=>{for(const value of [0,-1,Infinity,1.5,NaN])assert.throws(()=>context.createTextCache(value,3));assert.throws(()=>context.createTextCache(12,0))});
check('nonstrings rejected without corrupting counters',()=>{assert.throws(()=>cache.set(1,{}));assert.equal(cache.characters,0)});
check('UTF-16 supplementary characters count correctly',()=>{cache.set(1,'😀');assert.equal(cache.characters,2)});
check('5000 large pages never exceed production cache budgets',()=>{
 const bounded=context.createTextCache();const old=new Map();let oldUnits=0,peak=0;
 for(let page=1;page<=5000;page++){
  const text=String(page).padEnd(page%17===0?2_000_001:100_000+(page%5)*10_000,'x');
  bounded.set(page,text);if(old.size>=80){const key=old.keys().next().value;oldUnits-=old.get(key).length;old.delete(key)}old.set(page,text);oldUnits+=text.length;peak=Math.max(peak,bounded.characters);
  assert(bounded.characters<=2_000_000);assert(bounded.size<=80);
 }
 assert(oldUnits>2_000_000);console.log(JSON.stringify({syntheticPages:5000,priorRetainedCharacters:oldUnits,newRetainedCharacters:bounded.characters,newPeakRetainedCharacters:peak,budgetCharacters:2_000_000,note:'Cache accounting only, not total heap/PDF.js/browser memory.'}));
});
check('closed cache cannot be repopulated by late extraction',()=>{cache.close();cache.set(99,'late completed text');assert.equal(cache.size,0);assert.equal(cache.characters,0);assert(!cache.has(99))});
console.log(checks+' bounded-cache checks passed; synthetic strings, not browser acceptance.');
