/* Deterministic document-loading lifecycle; no browser/network execution. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const filename=process.env.MANUAL_READER_SOURCE||__dirname+'/../../public/js/manual-reader.mjs';
let checks=0;const check=(v,m)=>{assert(v,m);checks++;};
function fixture(){
 let resolve,reject,destroys=0,disconnects=0,gets=0;
 const listeners=new Map(),nodes=new Map(),history=[];
 const el=(tag='DIV')=>({tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:600,hidden:false,disabled:true,value:'',textContent:'',width:0,height:0,addEventListener(n,f){this.listeners[n]=f;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},append(...x){this.children.push(...x);},replaceChildren(...x){this.children=x;},replaceWith(x){this.replacedBy=x;},focus(){this.focusCalls=(this.focusCalls||0)+1;context.document.activeElement=this;},getContext(){return{};},querySelector(s){this.nodes??=new Map();if(!this.nodes.has(s))this.nodes.set(s,el(s==='canvas'?'CANVAS':'DIV'));return this.nodes.get(s);}});
 const root=el();root.dataset.pdfUrl='/manuals/file.php?id=1';root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,el());return nodes.get(s);};const papers=[el(),el()];root.querySelectorAll=s=>s==='[data-paper]'?papers:[];
 const documentPromise=new Promise((yes,no)=>{resolve=yes;reject=no;});
 const task={promise:documentPromise,destroy(){destroys++;return Promise.resolve();}};
 const pdf={numPages:3,async getPage(n){gets++;return{getViewport:({scale})=>({width:300*scale,height:400*scale}),render:()=>({promise:Promise.resolve(),cancel(){}}),getTextContent:async()=>({items:[{str:'Synthetic text'}]})};}};
 const context={console,URL,URLSearchParams,setTimeout,clearTimeout,location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:''},history:{replaceState(...args){history.push(args);}},getComputedStyle:()=>({paddingLeft:'0',paddingRight:'0'}),ResizeObserver:class{observe(){}disconnect(){disconnects++;}},document:{querySelector:()=>root,createElement:tag=>el(tag.toUpperCase()),createDocumentFragment:()=>el(),addEventListener(){}},window:{devicePixelRatio:1,matchMedia:()=>({matches:true,addEventListener(){}}),addEventListener(type,fn){if(!listeners.has(type))listeners.set(type,[]);listeners.get(type).push(fn);}},pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>task}};
 vm.createContext(context);
 const source=fs.readFileSync(filename,'utf8').replace(/^import .*;\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/vendor/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);');
 vm.runInContext(source,context);
 return {resolve,reject,pdf,task,nodes,history,papers,context,listeners,hide(persisted){for(const f of listeners.get('pagehide')||[])f({persisted});},get destroys(){return destroys;},get disconnects(){return disconnects;},get gets(){return gets;}};
}
(async()=>{
 const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
 let t=fixture(),cancel=t.nodes.get('[data-load-cancel]'),status=t.nodes.get('[data-status]');
 check(cancel.hidden===false,'pending load exposes native stop control');check(typeof t.task.onProgress==='function','progress attached to actual task');
 t.task.onProgress({loaded:1,total:100});check(status.textContent==='Downloading manual… 0%','progress rounds down');
 t.task.onProgress({loaded:26,total:100});check(status.textContent==='Downloading manual… 25%','progress reports five percent increments');
 let writes=0,value=status.textContent;Object.defineProperty(status,'textContent',{get:()=>value,set:v=>{writes++;value=v;},configurable:true});t.task.onProgress({loaded:27,total:100});t.task.onProgress({loaded:29,total:100});check(writes===0,'same progress bucket does not repeatedly announce');
 const prior=status.textContent;for(const bad of [null,{}, {loaded:-1,total:100},{loaded:NaN},{loaded:Infinity},{loaded:'10'},{loaded:1.5}])t.task.onProgress(bad);
 check(status.textContent===prior,'malformed progress ignored');t.task.onProgress({loaded:10*1024*1024});check(status.textContent.includes('10 MiB'),'unknown total reports coarse received bytes');
 t.task.onProgress({loaded:100,total:100});check(status.textContent.includes('Preparing'),'received bytes not confused with ready pages');check(!cancel.hidden,'preparing document remains cancellable');
 cancel.listeners.click();check(t.destroys===1&&t.disconnects===1,'stop owns teardown exactly once');check(cancel.hidden,'stop control removed after cancellation');check(status.textContent.includes('Reader loading stopped'),'stopped message and explicit reload fallback');check(status.focusCalls===1,'hidden stop moves keyboard focus to explanation');
 const stopped=status.textContent;t.task.onProgress({loaded:50,total:100});t.task.onPassword();check(status.textContent===stopped,'late progress and password cannot overwrite stopped state');cancel.listeners.click();t.hide(false);check(t.destroys===1,'repeated stop/departure is idempotent');t.resolve(t.pdf);await t.context.initialized;check(t.gets===0&&t.history.length===0,'late resolution after stop never renders or navigates');check(t.nodes.get('[data-next]').disabled,'late resolution never activates reader');
 t=fixture();t.nodes.get('[data-load-cancel]').listeners.click();const text=t.nodes.get('[data-status]').textContent;t.reject(Error('cancel'));await t.context.initialized;check(t.nodes.get('[data-status]').textContent===text,'late rejection preserves stop explanation');
 t=fixture();t.hide(true);check(!t.nodes.get('[data-load-cancel]').hidden&&t.destroys===0,'persisted pending load remains cancellable');t.resolve(t.pdf);await t.context.initialized;check(t.nodes.get('[data-load-cancel]').hidden,'ordinary success hides stop');const ready=t.nodes.get('[data-status]').textContent;t.task.onProgress({loaded:1,total:100});check(t.nodes.get('[data-status]').textContent===ready,'late progress cannot replace rendered page status');t.nodes.get('[data-load-cancel]').listeners.click();check(t.destroys===0,'stale stop click cannot destroy ready document');
 t=fixture();t.reject(Error('network'));await t.context.initialized;check(t.nodes.get('[data-load-cancel]').hidden,'active error hides stop');check(t.nodes.get('[data-status]').textContent.includes('could not open'),'active failure remains visible');
 t=fixture();t.task.onPassword();check(t.nodes.get('[data-load-cancel]').hidden,'password failure hides stop');t.task.onProgress({loaded:20,total:100});check(t.nodes.get('[data-status]').textContent.includes('password'),'progress preserves password explanation');t.reject(Error('password'));await t.context.initialized;
 for(const mode of ['throw','reject']){
  t=fixture();const destroy=t.task.destroy;t.task.destroy=()=>{destroy();if(mode==='throw')throw Error('synthetic destroy');return Promise.reject(Error('synthetic destroy'));};t.nodes.get('[data-load-cancel]').listeners.click();await flush();check(t.nodes.get('[data-status]').textContent.includes('Reader loading stopped'),'destroy failure does not revive view');t.resolve(t.pdf);await t.context.initialized;check(t.gets===0,'destroy failure still suppresses late render');
 }
 t=fixture();t.hide(false);check(t.nodes.get('[data-load-cancel]').hidden,'permanent departure hides pending control');t.resolve(t.pdf);await t.context.initialized;
 for(const mode of ['success','error','password']){
  t=fixture();const button=t.nodes.get('[data-load-cancel]');button.focus();
  if(mode==='success')t.resolve(t.pdf);else if(mode==='error')t.reject(Error('error'));else{t.task.onPassword();t.reject(Error('password'));}
  await t.context.initialized;check(t.context.document.activeElement===t.nodes.get('[data-status]'),'automatic finish restores owned Stop focus: '+mode);
 }
 t=fixture();const unrelated=t.nodes.get('[data-page]');unrelated.focus();t.resolve(t.pdf);await t.context.initialized;check(t.context.document.activeElement===unrelated,'automatic finish does not steal unrelated focus');
 t=fixture();t.nodes.get('[data-load-cancel]').focus();t.hide(false);check(!t.nodes.get('[data-status]').focusCalls,'departure never focuses hidden page');t.resolve(t.pdf);await t.context.initialized;
 t=fixture();t.resolve(t.pdf);await t.context.initialized;const rendered=t.nodes.get('[data-status]').textContent;t.task.onPassword();check(t.destroys===0&&t.nodes.get('[data-status]').textContent===rendered,'late password callback cannot destroy ready reader');
 for(const mode of ['throw','reject']){
  t=fixture();const destroy=t.task.destroy;t.task.destroy=()=>{destroy();if(mode==='throw')throw Error('synthetic password destroy');return Promise.reject(Error('synthetic password destroy'));};t.task.onPassword();await flush();check(t.nodes.get('[data-status]').textContent.includes('password'),'password explanation survives destroy failure');t.task.onPassword();check(t.destroys===1,'password callback only owns pending load once');t.reject(Error('password'));await t.context.initialized;
 }
 console.log(checks+' load progress/cancellation checks passed; network savings remain unmeasured.');
})().catch(error=>{console.error(error);process.exitCode=1;});
