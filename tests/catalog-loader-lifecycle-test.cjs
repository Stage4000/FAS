'use strict';
// Current-checkout catalog loader regression. VM is not a security sandbox; known dependencies are inert stubs.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const sourcePath = process.argv[2] || path.join(__dirname, '../products.php');
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const full = fs.readFileSync(sourcePath, 'utf8');
const start = 'let catalogRequestController;';
const end = '// Attach pagination handlers';
assert.equal(full.split(start).length, 2, 'Unique loader start');
assert.equal(full.split(end).length, 2, 'Unique loader end');
assert(full.indexOf(end) > full.indexOf(start), 'Loader marker ordering');
const source = full.slice(full.indexOf(start), full.indexOf(end));
assert(source.startsWith('let catalogRequestController;\nasync function loadProductsAndSidebar(params, focusId = null) {'), 'Expected loader entrypoint');
assert(!source.includes('<?') && !source.includes('<script') && !source.includes('</script'), 'JavaScript-only slice');
// Current checkout source is tested. Whole-page historical hashes must not make unrelated edits fail.
const SOURCE_SHA = sha(full), SLICE_SHA = sha(source);
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
function deferred() { let resolve, reject; const promise = new Promise((a,b) => {resolve=a;reject=b;}); return {promise,resolve,reject}; }
function fixture(code, options = {}) {
  const calls = [], effects = [], controllers = [], requests = [], pending = [];
  let contentHtml = 'INITIAL', sidebarHtml = 'INITIAL-SIDEBAR';
  const aos = Array.from({length:2}, (_, n) => ({
    removeAttribute: name => effects.push(['attribute',n,name]),
    classList: {remove: (...names) => effects.push(['class',n,...names])},
    style: {removeProperty: name => effects.push(['style',n,name])}
  }));
  const content = {
    get innerHTML() { return contentHtml; },
    set innerHTML(v) { contentHtml=v; effects.push(['content',v]); },
    querySelectorAll(selector) { assert.equal(selector,'[data-aos]'); return aos; }
  };
  const sidebar = {get innerHTML(){return sidebarHtml;},set innerHTML(v){sidebarHtml=v;effects.push(['sidebar',v]);}};
  const window = {location:{href:'/products?current=fixture',assign: url => effects.push(['navigate',url])},FAS_PRODUCTS_PAGE_DATA:{old:true}};
  if (!options.noAnalytics) window.fasAnalytics = {refreshProductImpressions: () => effects.push(['analytics'])};
  const context = {
    window,
    document: {
      getElementById(id) {assert.equal(id,'productsContent');return content;},
      querySelector(selector) {assert.equal(selector,'#categoryMenu .list-group');return options.noSidebar ? null : sidebar;}
    },
    AbortController: class {
      constructor(){this.signal={aborted:false};controllers.push(this);}
      abort(){this.signal.aborted=true;calls.push(['abort',controllers.indexOf(this)]);}
    },
    fetch(url, init) {
      assert.equal(Object.keys(init).join(','),'signal');
      const fetch = deferred(), body = deferred();
      requests.push({url,signal:init.signal,fetch,body});
      calls.push(['fetch',requests.length-1,url]);
      return fetch.promise;
    },
    fasApplyCatalogMetadata: data => effects.push(['metadata',data]),
  };
  for (const name of ['attachCategoryHandlers','attachPaginationHandlers','attachManufacturerFilterHandler','attachModelFilterHandler','renderSavedSearches']) {
    context[name] = () => {effects.push([name]);if(options.throwHelper===name)throw Error('synthetic helper failure');};
  }
  vm.createContext(context, {codeGeneration:{strings:false,wasm:false}});
  vm.runInContext(code+'\nglobalThis.runCatalog=loadProductsAndSidebar;', context, {timeout:1000});
  const run = query => {
    const params = new URLSearchParams(query), before=params.toString();
    const promise = context.runCatalog(params);pending.push(promise);
    assert.equal(params.toString(),before,'No caller query mutation');return promise;
  };
  const response = (index, ok=true) => requests[index].fetch.resolve({ok,json:()=>requests[index].body.promise});
  const data = tag => ({success:true,html:'HTML-'+tag,sidebar:'SIDE-'+tag,metadata:{tag},landing:{tag}});
  const complete = async (index, value=data(String(index))) => {response(index);await flush();requests[index].body.resolve(value);await pending[index];};
  return {calls,effects,controllers,requests,pending,run,response,data,complete,window,content,sidebar,
    snapshot:()=>JSON.stringify({effects,content:contentHtml,sidebar:sidebarHtml,landing:window.FAS_PRODUCTS_PAGE_DATA})};
}
function committed(f, tag) {
  assert.equal(f.content.innerHTML,'HTML-'+tag);
  assert.equal(f.sidebar.innerHTML,'SIDE-'+tag);
  assert.equal(JSON.stringify(f.window.FAS_PRODUCTS_PAGE_DATA),JSON.stringify({tag}));
  assert.deepEqual(f.effects.filter(e=>e[0]==='metadata'),[['metadata',{tag}]]);
  assert.equal(f.effects.filter(e=>e[0]==='analytics').length,1);
  assert.equal(f.effects.filter(e=>e[0]==='navigate').length,0);
}
const permutations = [[0,1,2],[0,2,1],[1,0,2],[1,2,0],[2,0,1],[2,1,0]];
async function suite(code) {
  const cases = [];
  async function test(name, body) {try{await body();cases.push({name,passed:true});}catch(e){cases.push({name,passed:false,error:String(e.message).slice(0,1000)});}}
  await test('normal response updates all effects and exact AOS cleanup',async()=>{
    const f=fixture(code);f.run('search=rotor+%26+bolt&page=2');await f.complete(0,f.data('normal'));committed(f,'normal');
    assert.equal(f.requests[0].url,'/api/products.php?search=rotor+%26+bolt&page=2&include_sidebar=1');
    for(const n of [0,1]) {
      assert.deepEqual(f.effects.filter(e=>e[0]==='attribute'&&e[1]===n).map(e=>e[2]),['data-aos','data-aos-delay','data-aos-duration','data-aos-offset']);
      assert.deepEqual(f.effects.filter(e=>e[0]==='class'&&e[1]===n),[['class',n,'aos-init','aos-animate']]);
      assert.deepEqual(f.effects.filter(e=>e[0]==='style'&&e[1]===n).map(e=>e[2]),['opacity','transform','transition-property']);
    }
    for(const name of ['attachCategoryHandlers','attachPaginationHandlers','attachManufacturerFilterHandler','attachModelFilterHandler','renderSavedSearches'])assert.equal(f.effects.filter(e=>e[0]===name).length,1);
  });
  await test('absent optional fields preserve existing sidebar and landing',async()=>{
    const f=fixture(code,{noAnalytics:true});f.run('');await f.complete(0,{success:true,html:'ONLY'});
    assert.equal(f.content.innerHTML,'ONLY');assert.equal(f.sidebar.innerHTML,'INITIAL-SIDEBAR');
    assert.deepEqual(f.window.FAS_PRODUCTS_PAGE_DATA,{old:true});assert.equal(f.effects.some(e=>e[0]==='navigate'||e[0]==='attachCategoryHandlers'),false);
  });
  await test('missing sidebar DOM is tolerated',async()=>{
    const f=fixture(code,{noSidebar:true});f.run('');await f.complete(0,f.data('ok'));
    assert.equal(f.content.innerHTML,'HTML-ok');assert.equal(f.effects.some(e=>e[0]==='navigate'),false);
  });
  await test('superseding request aborts previous before fetching',async()=>{
    const f=fixture(code);f.run('page=1');f.run('page=2');
    assert.equal(f.requests[0].signal.aborted,true);assert.equal(f.requests[1].signal.aborted,false);
    assert.deepEqual(f.calls.map(c=>c.slice(0,2)),[['fetch',0],['abort',0],['fetch',1]]);
    await f.complete(0);await f.complete(1);
  });
  for(const stage of ['fetch','json']) await test('stale '+stage+' completion does not change pending latest state',async()=>{
    const f=fixture(code);f.run('page=1');if(stage==='json'){f.response(0);await flush();}f.run('page=2');
    const before=f.snapshot();await f.complete(0,f.data('stale'));assert.equal(f.snapshot(),before);
    await f.complete(1,f.data('latest'));committed(f,'latest');
  });
  for(const kind of ['network','http','json','incomplete','abort']) await test('stale '+kind+' failure never navigates or commits',async()=>{
    const f=fixture(code);f.run('page=1');f.run('page=2');await f.complete(1,f.data('latest'));const before=f.snapshot();
    if(kind==='network')f.requests[0].fetch.reject(Error('synthetic'));
    else if(kind==='abort')f.requests[0].fetch.reject({name:'AbortError'});
    else {f.response(0,kind!=='http');await flush();if(kind==='json')f.requests[0].body.reject(Error('bad JSON'));else if(kind==='incomplete')f.requests[0].body.resolve({success:false});}
    await f.pending[0];assert.equal(f.snapshot(),before);committed(f,'latest');
  });
  for(const kind of ['network','http','json','false-success','empty-html','null-body']) await test('latest '+kind+' failure falls back once to current navigation',async()=>{
    const f=fixture(code);f.run('page=1');f.window.location.href='/products?newer-current=1';
    if(kind==='network')f.requests[0].fetch.reject(Error('synthetic'));
    else {f.response(0,kind!=='http');await flush();if(kind==='json')f.requests[0].body.reject(Error('bad JSON'));else f.requests[0].body.resolve(kind==='null-body'?null:{success:kind!=='false-success',html:kind==='empty-html'?'':'HTML'});}
    await f.pending[0];assert.deepEqual(f.effects.filter(e=>e[0]==='navigate'),[['navigate','/products?newer-current=1']]);
    assert.equal(f.effects.some(e=>e[0]==='metadata'||e[0]==='sidebar'),false);
  });
  await test('latest AbortError does not reload',async()=>{
    const f=fixture(code);f.run('');f.requests[0].fetch.reject({name:'AbortError'});await f.pending[0];
    assert.equal(f.effects.some(e=>e[0]==='navigate'),false);
  });
  await test('latest rendering helper failure falls back, without atomicity claim',async()=>{
    const f=fixture(code,{throwHelper:'attachPaginationHandlers'});f.run('');await f.complete(0,f.data('partial'));
    assert.equal(f.content.innerHTML,'HTML-partial');assert.equal(f.effects.filter(e=>e[0]==='navigate').length,1);
  });
  for(const order of permutations) for(const phase of ['response','body']) await test('three-request '+phase+' order '+order.join(''),async()=>{
    const f=fixture(code);for(let i=0;i<3;i++)f.run('page='+i);
    if(phase==='body'){for(let i=0;i<3;i++)f.response(i);await flush();}
    for(const i of order) {
      const before=f.snapshot();await f.complete(i,f.data('request-'+i));
      if(i!==2)assert.equal(f.snapshot(),before,'Stale response has zero observable effects');
    }
    committed(f,'request-2');assert.equal(f.controllers[0].signal.aborted,true);assert.equal(f.controllers[1].signal.aborted,true);
  });
  return {cases,total:cases.length,passed:cases.filter(x=>x.passed).length,failures:cases.filter(x=>!x.passed)};
}
function replaceOne(s,from,to){assert.equal(s.split(from).length,2,'Mutation target unique');return s.replace(from,to);}
// Unresolved promises alone do not keep Node alive. Never let an unfinished suite exit 0.
const identity = {source_sha256:SOURCE_SHA,slice_sha256:SLICE_SHA,harness_sha256:sha(fs.readFileSync(__filename)),node:process.version};
const HARNESS_TIMEOUT_MS = 10000;
let suiteFinished = false, incompleteReported = false;
function incomplete(reason) {
  if (suiteFinished || incompleteReported) return;
  incompleteReported = true;
  process.exitCode = 1;
  console.log(JSON.stringify({...identity,passed:false,error:'suite did not complete',reason,deadline_ms:HARNESS_TIMEOUT_MS}));
}
process.once('beforeExit', () => incomplete('beforeExit with unfinished async suite'));
const deadline = setTimeout(() => {
  incomplete('harness deadline');
  if (!suiteFinished) process.exit(1);
}, HARNESS_TIMEOUT_MS);
deadline.unref();
const failureSample = failures => failures.slice(0,6).map(f=>({name:f.name.slice(0,160),error:f.error.slice(0,240)}));
(async()=>{
  const baseline = await suite(source);
  const mutations = {
    'remove-success-ownership': replaceOne(source,'if (controller !== catalogRequestController) return;','/* negative: removed success ownership */'),
    'remove-error-ownership': replaceOne(source,"error.name === 'AbortError' || controller !== catalogRequestController","error.name === 'AbortError'"),
    'remove-abort': replaceOne(source,'if (catalogRequestController) catalogRequestController.abort();','/* negative: removed abort */'),
    'remove-navigation-fallback': replaceOne(source,'window.location.assign(window.location.href);','/* negative: removed fallback */'),
    'remove-animation-cleanup': replaceOne(source,"el.classList.remove('aos-init', 'aos-animate');",'/* negative: removed class cleanup */')
  };
  const controls = {};
  for(const [name,code] of Object.entries(mutations))controls[name]={source_sha256:sha(code),...await suite(code)};
  const result={...identity,
    scope:'Actual loader source, synthetic deferred callbacks and DOM/helper stubs only. VM is not a sandbox. No browser/HTTP/database/provider/full-application execution.',
    baseline,controls,passed:baseline.passed===baseline.total&&Object.values(controls).every(x=>x.failures.length>0)};
  console.log(JSON.stringify({...identity,passed:result.passed,
    baseline:{passed:baseline.passed,total:baseline.total,failures:failureSample(baseline.failures)},
    controls:Object.fromEntries(Object.entries(controls).map(([k,v])=>[k,{failed_cases:v.failures.length,failures:failureSample(v.failures)}])),
    undetected_mutations:Object.entries(controls).filter(([,v])=>v.failures.length===0).map(([name])=>name)}));
  if(!result.passed)process.exitCode=1;
  suiteFinished = true;
  clearTimeout(deadline);
})().catch(e=>{
  process.exitCode=1;
  console.log(JSON.stringify({...identity,passed:false,error:'harness exception',detail:String(e.message).slice(0,500)}));
  suiteFinished=true;
  clearTimeout(deadline);
});
