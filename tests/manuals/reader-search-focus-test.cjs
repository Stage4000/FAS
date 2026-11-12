/* Deterministic actual-reader focus/cancellation regression; no browser launch. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const sourcePath=process.env.MANUAL_READER_SOURCE||__dirname+'/../../public/js/manual-reader.mjs';
const watchdog=setTimeout(()=>{throw new Error('Search focus test did not settle');},5000);
let checks=0;const check=(v,m)=>{assert.ok(v,m);checks++;};
async function fixture(){
 const nodes=new Map(),events=new Map(),requests=[];let destroys=0;
 const element=(tag='DIV')=>({tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:600,hidden:false,disabled:true,value:'',textContent:'',
  addEventListener(t,f){this.listeners[t]=f;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},append(...v){this.children.push(...v);},replaceChildren(...v){this.children=v;},replaceWith(){},focus(){context.document.activeElement=this;this.focusCalls=(this.focusCalls||0)+1;},getContext(){return{};},querySelector(s){this.nodes??=new Map();if(!this.nodes.has(s))this.nodes.set(s,element());return this.nodes.get(s);}});
 const root=element();root.dataset.pdfUrl='/manuals/file.php?id=1';root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,element());return nodes.get(s);};
 const papers=[element(),element()];root.querySelectorAll=s=>s==='[data-paper]'?papers:[];
 const textPanel={open:false,addEventListener(){}};root.querySelector('[data-page-text]').closest=()=>textPanel;
 const pdf={numPages:2,async getPage(n){return{getViewport:({scale})=>({width:300*scale,height:400*scale}),render:()=>({promise:Promise.resolve(),cancel(){}}),getTextContent:()=>new Promise((resolve,reject)=>requests.push({n,resolve:t=>resolve({items:[{str:t}]}),reject}))};}};
 const context={console,URL,URLSearchParams,setTimeout,clearTimeout,history:{replaceState(){}},location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:''},getComputedStyle:()=>({paddingLeft:'0',paddingRight:'0'}),ResizeObserver:class{observe(){}disconnect(){}},document:{querySelector:()=>root,createElement:tag=>element(tag.toUpperCase()),createDocumentFragment:()=>element(),addEventListener(){}},window:{matchMedia:()=>({matches:true,addEventListener(){}}),addEventListener(t,f){events.set(t,f);},devicePixelRatio:1},pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>({promise:Promise.resolve(pdf),destroy(){destroys++;return Promise.resolve();}})}};
 vm.createContext(context);const source=fs.readFileSync(sourcePath,'utf8').replace(/^import .*;\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/vendor/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);');vm.runInContext(source,context);await context.initialized;
 const get=s=>root.querySelector(s),flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
 return{nodes,requests,context,get,flush,submit(term='needle'){get('[data-search-input]').value=term;return get('[data-search-form]').listeners.submit({preventDefault(){}});},stop(){get('[data-search-cancel]').listeners.click();},hide(persisted){events.get('pagehide')({persisted});},get destroys(){return destroys;}};
}
(async()=>{
 const cancel=t=>t.get('[data-search-cancel]'),status=t=>t.get('[data-search-status]'),results=t=>t.get('[data-search-results]');
 let t=await fixture(),pending=t.submit();await t.flush();cancel(t).focus();t.stop();
 check(cancel(t).hidden,'Stop hides itself');check(t.context.document.activeElement===status(t),'keyboard Stop focuses visible search explanation');
 const count=results(t).children.length,explanation=status(t).textContent;t.requests[0].resolve('needle late');await pending;
 check(status(t).textContent===explanation&&results(t).children.length===count,'stale extraction cannot overwrite stopped status or results');check(status(t).focusCalls===1,'stale completion does not refocus');
 t.get('[data-search-input]').focus();t.stop();check(status(t).textContent===explanation&&t.context.document.activeElement===t.get('[data-search-input]'),'obsolete Stop does not change focus or terminal result');
 for(const mode of ['matches','absent','image','failure']){
  t=await fixture();pending=t.submit();await t.flush();cancel(t).focus();
  if(mode==='failure')t.requests[0].reject(Error('synthetic'));
  else{t.requests[0].resolve(mode==='matches'?'needle':mode==='absent'?'other':'');await t.flush();t.requests[1].resolve(mode==='matches'?'needle':mode==='absent'?'other':'');}
  await pending;check(cancel(t).hidden,'terminal control hidden '+mode);check(t.context.document.activeElement===status(t),'terminal focus repaired '+mode);
  check(status(t).textContent===({matches:'2 matching pages.',absent:'No matching document text found.',image:'This PDF has no extractable text. Scanned manuals need OCR before text search can find their contents.',failure:'Search could not finish. You can still turn pages or download the PDF.'}[mode]),'terminal status exact '+mode);
  const before=status(t).textContent;t.stop();check(status(t).textContent===before,'stale Stop preserves completion '+mode);
 }
 for(const mode of ['stop','success','failure','empty']){
  t=await fixture();pending=t.submit();await t.flush();const other=t.get('[data-page]');other.focus();
  if(mode==='stop')t.stop();else if(mode==='empty')await t.submit(' ');
  if(mode==='failure')t.requests[0].reject(Error('synthetic'));
  else{t.requests[0].resolve('needle');await t.flush();if(mode==='success')t.requests[1].resolve('needle');}
  await pending;check(t.context.document.activeElement===other&&!status(t).focusCalls,'unrelated focus never stolen '+mode);
 }
 t=await fixture();pending=t.submit();await t.flush();cancel(t).focus();await t.submit(' ');check(t.context.document.activeElement===status(t),'empty replacement repairs focused obsolete Stop');t.requests[0].resolve('needle');await pending;check(status(t).textContent==='Enter a word or part number.'&&status(t).focusCalls===1,'old search cannot overwrite or refocus empty replacement');
 t=await fixture();pending=t.submit('old');await t.flush();cancel(t).focus();const newer=t.submit('new');await t.flush();t.requests[0].resolve('new');await t.flush();await pending;check(!cancel(t).hidden&&!status(t).focusCalls,'stale completion leaves newer Stop and focus intact');t.requests[1].resolve('new');await newer;check(t.context.document.activeElement===status(t)&&status(t).focusCalls===1,'newest completion owns one focus repair');
 t=await fixture();pending=t.submit();await t.flush();cancel(t).focus();t.hide(false);check(cancel(t).hidden,'permanent departure hides obsolete search control');check(!status(t).focusCalls,'permanent departure never focuses outgoing page');const before=status(t).textContent;t.requests[0].resolve('needle');await pending;t.stop();await t.submit();check(status(t).textContent===before&&!status(t).focusCalls,'disposed search events remain inert');check(t.requests.length===1,'disposed submission cannot request more text');
 t=await fixture();pending=t.submit();await t.flush();cancel(t).focus();t.hide(true);check(!cancel(t).hidden&&t.destroys===0,'persisted departure preserves active search');t.requests[0].resolve('needle');await t.flush();t.requests[1].resolve('needle');await pending;check(t.context.document.activeElement===status(t),'resumable search retains focus repair');
 console.log(checks+' search terminal focus/ownership checks passed; deterministic DOM/PDF stubs only.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>clearTimeout(watchdog));
