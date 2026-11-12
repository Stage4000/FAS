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
 const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
 let t=fixture(),calls=0;
 t.pdf.getOutline=async()=>{calls++;return[{title:'Intro',dest:[0]},{title:'External',url:'https://example.test',dest:[1]}];};
 t.resolve(t.pdf);await t.context.initialized;
 check(calls===0,'outline not extracted while closed');
 const panel=t.nodes.get('[data-outline]');panel.open=true;panel.listeners.toggle();await flush();
 check(calls===1,'first open extracts outline');
 const list=t.nodes.get('[data-outline-list]');const items=list.children[0].children;
 check(items.length===2,'outline rendered');check(items[1].children[0].disabled,'external item disabled');
 panel.open=false;panel.listeners.toggle();panel.open=true;panel.listeners.toggle();await flush();check(calls===1,'reopen uses bounded retained index');
 let resolveDest;t.pdf.getDestination=()=>new Promise(r=>resolveDest=r);
 t=fixture();t.pdf.getOutline=async()=>[{title:'Named',dest:'name'}];t.pdf.getDestination=()=>new Promise(r=>resolveDest=r);t.resolve(t.pdf);await t.context.initialized;
 const p=t.nodes.get('[data-outline]');p.open=true;p.listeners.toggle();await flush();const b=t.nodes.get('[data-outline-list]').children[0].children[0].children[0];
 let click=b.listeners.click();p.open=false;p.listeners.toggle();p.open=true;p.listeners.toggle();check(t.nodes.get('[data-outline-status]').textContent.includes('1 section'),'close and reopen restores section summary immediately');resolveDest([2]);await click;check(t.history.length===0,'closing prevents late navigation');check(t.nodes.get('[data-outline-status]').textContent.includes('1 section'),'late canceled selection preserves restored summary');
 p.open=true;p.listeners.toggle();click=b.listeners.click();t.hide(false);resolveDest([2]);await click;check(t.history.length===0,'departure prevents late navigation');
 t=fixture();let resolveOutline;t.pdf.getOutline=()=>new Promise(r=>resolveOutline=r);t.resolve(t.pdf);await t.context.initialized;const late=t.nodes.get('[data-outline]');late.open=true;late.listeners.toggle();t.hide(false);resolveOutline([{title:'late',dest:[0]}]);await flush();check(t.nodes.get('[data-outline-list]').children.length===0,'departure drops late outline');
 t=fixture();t.pdf.getOutline=async()=>null;t.resolve(t.pdf);await t.context.initialized;t.nodes.get('[data-outline]').open=true;t.nodes.get('[data-outline]').listeners.toggle();await flush();check(t.nodes.get('[data-outline-status]').textContent.includes('no embedded'),'empty guidance');
 t=fixture();t.pdf.getOutline=async()=>{throw Error('bad')};t.resolve(t.pdf);await t.context.initialized;t.nodes.get('[data-outline]').open=true;t.nodes.get('[data-outline]').listeners.toggle();await flush();check(t.nodes.get('[data-outline-status]').textContent.includes('could not be loaded'),'failure guidance');
 t=fixture();t.pdf.getOutline=async()=>[{title:'Last',dest:[2]}];t.nodes.get('[data-outline]').open=true;t.resolve(t.pdf);await t.context.initialized;await flush();check(t.nodes.get('[data-outline-list]').children.length===1,'opening before PDF readiness loads once ready');
 await t.nodes.get('[data-outline-list]').children[0].children[0].children[0].listeners.click();await flush();check(t.history.at(-1)[2].includes('page=3'),'internal section navigates to resolved page');
 t=fixture();const waits=[];t.pdf.getOutline=async()=>[{title:'Section',dest:'name'}];t.pdf.getDestination=()=>new Promise(r=>waits.push(r));t.resolve(t.pdf);await t.context.initialized;const pp=t.nodes.get('[data-outline]');pp.open=true;pp.listeners.toggle();await flush();const bb=t.nodes.get('[data-outline-list]').children[0].children[0].children[0];
 const older=bb.listeners.click(),newer=bb.listeners.click();waits[1]([1]);await newer;await flush();const historySize=t.history.length;waits[0]([2]);await older;await flush();check(t.history.length===historySize&&t.history.at(-1)[2].includes('page=2'),'newer selection owns navigation');
 const pending=bb.listeners.click();t.nodes.get('[data-next]').listeners.click();await flush();check(t.nodes.get('[data-outline-status]').textContent.includes('1 section'),'manual page turn restores outline summary');const afterTurn=t.history.length;waits[2]([0]);await pending;check(t.history.length===afterTurn&&t.nodes.get('[data-outline-status]').textContent.includes('1 section'),'old selection cannot replace page or summary after manual navigation');
 const invalid=bb.listeners.click();waits[3]([9999]);await invalid;check(t.nodes.get('[data-outline-status]').textContent.includes('could not be found'),'invalid section does not navigate');check(t.history.length===afterTurn,'invalid section preserves page');
 console.log(checks+' actual reader outline checks passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
