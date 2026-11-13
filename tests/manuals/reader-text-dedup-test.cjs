/* Focused state regression only: DOM/PDF stubs, never browser acceptance. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const sourcePath=__dirname+'/../../public/js/manual-reader.mjs';
let checks=0;
const check=(value,label)=>{assert(value,label);checks++;console.log('PASS '+label);};
async function scenario(){
  const nodes=new Map(),listeners=new Map(),plans=new Map(),calls=new Map();
  const tick=()=>new Promise(r=>setTimeout(r,0));
  function hold(n){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});plans.set(n,promise);return{resolve,reject};}
  function element(tag='DIV'){
    return {tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:600,hidden:false,disabled:true,value:'',textContent:'',
      addEventListener(t,fn){this.listeners[t]=fn;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},
      append(...items){this.children.push(...items);},replaceChildren(...items){this.children=items;},replaceWith(){},focus(){},getContext(){return{};},
      querySelector(selector){this.nodes??=new Map();if(!this.nodes.has(selector))this.nodes.set(selector,element());return this.nodes.get(selector);}};
  }
  const root=element();root.dataset.pdfUrl='/manuals/file.php?id=1';
  root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,element());return nodes.get(s);};
  const papers=[element(),element()];root.querySelectorAll=()=>papers;
  const pdf={numPages:3,async getPage(n){return{getViewport({scale}){return{width:300*scale,height:400*scale};},render(){return{promise:Promise.resolve(),cancel(){}};},async getTextContent(){calls.set(n,(calls.get(n)||0)+1);if(plans.has(n))return await plans.get(n);return{items:[{str:'synthetic page '+n}]};}};}};
  const context={console,URL,URLSearchParams,setTimeout,clearTimeout,history:{replaceState(){}},location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:''},
    getComputedStyle:()=>({paddingLeft:'0',paddingRight:'0'}),ResizeObserver:class{observe(){}disconnect(){}},
    document:{querySelector:()=>root,createElement:tag=>element(tag.toUpperCase()),createDocumentFragment:()=>element(),addEventListener(){}},
    window:{matchMedia:()=>({matches:true,addEventListener(){}}),addEventListener(t,f){listeners.set(t,f)},devicePixelRatio:1},
    pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>({promise:Promise.resolve(pdf),destroy(){}})}};
  vm.createContext(context);
  let source=fs.readFileSync(sourcePath,'utf8').replace(/^import .*;\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/public/vendor/pdfjs-6.5.438/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);');
  source=source.replace('  const setControls =', '  globalThis.getText=textFor; globalThis.cache=textCache; globalThis.inFlight=typeof pendingText!=="undefined"?pendingText:null;\n  const setControls =');
  vm.runInContext(source,context);await context.initialized;
  const p2=hold(2);const first=context.getText(2),second=context.getText(2);await tick();
  check(calls.get(2)===1,'two concurrent consumers cause one underlying extraction');p2.resolve({items:[{str:'all text',hasEOL:true},{str:'tail'}]});
  check(await first==='all text\ntail '&&await second==='all text\ntail ','both consumers receive complete formatted text');
  check(context.inFlight.size===0,'settled promise no longer retained');await context.getText(2);check(calls.get(2)===1,'normal bounded cache still used');
  context.cache.clear();const failed=hold(2);const failures=Promise.allSettled([context.getText(2),context.getText(2)]);await tick();failed.reject(new Error('synthetic extraction failure'));check((await failures).every(x=>x.status==='rejected'),'shared rejection reaches both consumers');check(context.inFlight.size===0,'failed promise removed for retry');plans.delete(2);check((await context.getText(2)).includes('synthetic page 2')&&calls.get(2)===3,'failed extraction retries successfully');
  context.cache.clear();const big='x'.repeat(2000001);const large=hold(2);const largePair=Promise.all([context.getText(2),context.getText(2)]);await tick();const before=calls.get(2);large.resolve({items:[{str:big}]});const full=await largePair;check(full.every(x=>x===big+' '),'oversized extraction not truncated for either consumer');check(!context.cache.has(2)&&context.inFlight.size===0,'oversized text not retained by cache or settled promise');await context.getText(2);check(calls.get(2)===before+1,'oversized text is extracted again after settlement');
  plans.delete(2);context.cache.clear();const shared=hold(2);nodes.get('[data-page]').value='2';nodes.get('[data-page-form]').listeners.submit({preventDefault(){}});await tick();const drawCalls=calls.get(2);
  const input=root.querySelector('[data-search-input]'),submit=nodes.get('[data-search-form]').listeners.submit,cancel=nodes.get('[data-search-cancel]'),status=root.querySelector('[data-search-status]'),results=root.querySelector('[data-search-results]');
  input.value='synthetic';const oldSearch=submit({preventDefault(){}});await tick();check(calls.get(2)===drawCalls,'draw and concurrent search share page extraction');cancel.listeners.click();const count=results.children.length;shared.resolve({items:[{str:'synthetic page 2'}]});await oldSearch;await tick();check(results.children.length===count&&status.textContent.startsWith('Search stopped.'),'canceled search cannot append shared late text');check(nodes.get('[data-page-text]').children[0].children.some(e=>e.textContent==='synthetic page 2'),'canceling search does not cancel visible-page text consumer');
  context.cache.clear();const stale=hold(2);input.value='synthetic';const old=submit({preventDefault(){}});await tick();const staleCalls=calls.get(2);input.value='page 2';const current=submit({preventDefault(){}});await tick();check(calls.get(2)===staleCalls,'replacement search shares in-flight work');stale.resolve({items:[{str:'synthetic page 2'}]});await Promise.all([old,current]);check(results.children.length===1&&status.textContent==='1 matching page.','only newest search owns shared completion UI');
  context.cache.clear();const end=hold(2),late=context.getText(2);await tick();listeners.get('pagehide')({persisted:true});check(context.inFlight.size===1,'bfcache keeps in-flight extraction');listeners.get('pagehide')({persisted:false});check(context.inFlight.size===0&&context.cache.size===0,'permanent departure clears promise/cache references');end.resolve({items:[{str:'late full text'}]});check(await late==='late full text ','already waiting consumer receives full result');check(context.cache.size===0&&context.inFlight.size===0,'late completion cannot repopulate caches');const endCalls=calls.get(2);await assert.rejects(context.getText(2));check(calls.get(2)===endCalls,'disposed reader starts no new extraction');
}
(async()=>{await scenario();console.log(checks+' in-flight text checks passed; deterministic stub calls, not worker memory or browser acceptance.');})().catch(e=>{console.error(e);process.exitCode=1;});
