/* Deterministic document-loading lifecycle; no browser/network execution. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const filename=process.env.MANUAL_READER_SOURCE||__dirname+'/../../public/js/manual-reader.mjs';
let checks=0;const check=(v,m)=>{assert(v,m);checks++;};
function fixture(){
 let resolve,reject,destroys=0,disconnects=0,gets=0;
 const listeners=new Map(),nodes=new Map(),history=[];
 const el=(tag='DIV')=>({tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:600,hidden:false,disabled:true,value:'',textContent:'',width:0,height:0,addEventListener(n,f){this.listeners[n]=f;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},append(...x){this.children.push(...x);},replaceChildren(...x){this.children=x;},replaceWith(x){this.replacedBy=x;},focus(){},getContext(){return{};},querySelector(s){this.nodes??=new Map();if(!this.nodes.has(s))this.nodes.set(s,el(s==='canvas'?'CANVAS':'DIV'));return this.nodes.get(s);}});
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
 let t=fixture();check((t.listeners.get('pagehide')||[]).length===1,'teardown registered while loading is pending');check(t.gets===0,'pending document does not render');
 t.hide(false);check(t.destroys===1,'permanent departure destroys pending loading task');check(t.disconnects===1,'pending departure disconnects observer');t.hide(false);check(t.destroys===1&&t.disconnects===1,'repeated permanent departure is idempotent');
 const status=t.nodes.get('[data-status]').textContent;t.resolve(t.pdf);await t.context.initialized;
 check(t.gets===0,'late resolution after departure does not request/render pages');check(t.nodes.get('[data-next]').disabled,'late resolution does not enable controls');check(t.nodes.get('[data-status]').textContent===status,'late resolution does not overwrite status');check(t.history.length===0,'late resolution does not change page URL');check(t.papers.every(p=>p.querySelector('canvas').width===0),'departed visible canvases remain released');
 t=fixture();t.hide(false);const before=t.nodes.get('[data-status]').textContent;t.reject(new Error('synthetic rejected after departure'));await t.context.initialized;
 check(t.nodes.get('[data-status]').textContent===before,'late loading rejection does not announce spurious error');check(t.destroys===1,'late rejection does not trigger second teardown');
 t=fixture();t.hide(false);t.task.onPassword();check(t.destroys===1,'late password callback does not destroy again');check(!t.nodes.get('[data-status]').textContent.includes('password'),'late password callback does not alter departed UI');t.reject(new Error('cancel'));await t.context.initialized;
 t=fixture();t.hide(true);check(t.destroys===0&&t.disconnects===0,'persisted pending departure retains loader and observer');t.resolve(t.pdf);await t.context.initialized;
 check(t.gets>0,'persisted pending load can complete normally');check(!t.nodes.get('[data-next]').disabled,'persisted pending load enables controls when ready');check((t.listeners.get('pagehide')||[]).length===1,'ready reader still has a single teardown listener');t.hide(false);check(t.destroys===1&&t.disconnects===1,'later permanent departure tears down retained reader');
 t=fixture();t.resolve(t.pdf);await t.context.initialized;check(t.gets>0,'ordinary load still renders');check(!t.nodes.get('[data-next]').disabled,'ordinary load still enables controls');t.hide(true);check(t.destroys===0,'ordinary persisted departure retains reader');t.hide(false);check(t.destroys===1,'ordinary permanent departure destroys once');
 t=fixture();t.reject(new Error('synthetic network error'));await t.context.initialized;check(t.nodes.get('[data-status]').textContent.includes('could not open'),'active load error still visible');t.hide(false);check(t.disconnects===1,'failed active load observer is cleaned on departure');
 t=fixture();t.task.onPassword();check(t.nodes.get('[data-status]').textContent.includes('password'),'active password prompt unchanged');check(t.destroys===1,'active password handler still stops load');t.reject(new Error('password'));await t.context.initialized;check(t.nodes.get('[data-status]').textContent.includes('password'),'active password explanation preserved');
 console.log(checks+' pending-load lifecycle checks passed; real browser/network cancellation remains separate.');
})().catch(error=>{console.error(error);process.exit(1);});
