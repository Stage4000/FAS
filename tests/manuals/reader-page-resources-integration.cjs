module.exports=async function(sourcePath,check){
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const tick=()=>new Promise(r=>setTimeout(r,0));
async function run(initialDeparture=false){
let destroyCount=0,disconnectCount=0;const listeners=new Map(),canvases=[],plans=new Map(),proxies=new Map(),cleanupCalls=[],activeRenders=new Map();
const addWindowListener=(t,f)=>listeners.set(t,f);
function el(tag='DIV'){const e={tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:900,hidden:false,disabled:true,value:'',textContent:'',width:300,height:150,addEventListener(t,f){this.listeners[t]=f},setAttribute(k,v){this.attributes[k]=v},removeAttribute(k){delete this.attributes[k]},append(...x){this.children.push(...x)},replaceChildren(...x){this.children=x},replaceWith(x){if(this.parent)this.parent.nodes.set('canvas',x);x.parent=this.parent},focus(){},getContext(){return{canvas:this}},querySelector(s){if(!this.nodes)this.nodes=new Map();if(!this.nodes.has(s)){const x=el(s==='canvas'?'CANVAS':'DIV');x.parent=this;this.nodes.set(s,x)}return this.nodes.get(s)}};if(tag==='CANVAS')canvases.push(e);return e;}
const nodes=new Map(),root=el();root.dataset.pdfUrl='/manuals/file.php?id=1';root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,el());return nodes.get(s)};const papers=[el(),el()];root.querySelectorAll=s=>s==='[data-paper]'?papers:[];
function pending(n){let resolve,reject;const plan={promise:new Promise((a,b)=>{resolve=a;reject=b}),cancelled:false,resolve:()=>resolve(),reject:(name='Error')=>{const error=new Error('synthetic render failure');error.name=name;reject(error)}};plans.set(n,plan);return plan;}
const pdf={numPages:8,async getPage(n){
if(!proxies.has(n))proxies.set(n,{getViewport({scale}){return{width:300*scale,height:400*scale}},
cleanup(){assert.equal(activeRenders.get(n)||0,0,'cleanup cannot precede render settlement');cleanupCalls.push(n);return true;},
render({canvasContext}){const p=plans.get(n);if(p){plans.delete(n);p.canvas=canvasContext.canvas;}activeRenders.set(n,(activeRenders.get(n)||0)+1);return{promise:(p?p.promise:Promise.resolve()).finally(()=>activeRenders.set(n,activeRenders.get(n)-1)),cancel(){if(p)p.cancelled=true}}},
async getTextContent(){return{items:[{str:'Page '+n}]}}});return proxies.get(n);
}};
  const context={console,URL,URLSearchParams,setTimeout,clearTimeout,history:{replaceState(...v){context.hash=v[2]}},location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:''},getComputedStyle(){return{paddingLeft:'0',paddingRight:'0'}},ResizeObserver:class{observe(){}disconnect(){disconnectCount++}},document:{querySelector:()=>root,createElement:tag=>el(tag.toUpperCase()),createDocumentFragment:()=>el(),addEventListener(){}},window:{matchMedia:()=>({matches:true,addEventListener(){}}),addEventListener:addWindowListener,devicePixelRatio:1},pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>({promise:Promise.resolve(pdf),destroy(){destroyCount++}})}};
vm.createContext(context);
let source=fs.readFileSync(sourcePath,'utf8').replace(/^import .*;\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/public/vendor/pdfjs-6.5.438/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);').replace('  const navigate =','  globalThis.draw = draw;\n  const navigate =');
const initial=initialDeparture?pending(1):null;
vm.runInContext(source,context);
if(initial){await tick();listeners.get('pagehide')({persisted:false});check(initial.cancelled&&initial.canvas.width>0,'initial pending render is cancelled without premature clearing');initial.resolve();await context.initialized;check(initial.canvas.width===0&&destroyCount===1,'initial pending render backing cleared after departure settles');return;}
await context.initialized;
const active=()=>papers.filter(p=>!p.hidden).map(p=>p.querySelector('canvas'));
check(cleanupCalls.length===0,'completed visible cover render resources retained');
let cover=active()[0];check(cover.width>0,'initial completed canvas retained');
const fail=pending(3),failed=context.draw(2);await tick();const staged=canvases.filter(c=>c!==cover&&c.width>0);check(cover.width>0,'old visible page retained while later spread page renders');fail.reject();await failed;check(active()[0]===cover&&cover.width>0,'failed spread preserves visible old canvas');check(fail.canvas.width===0&&staged.every(c=>c.width===0),'failed and earlier staged canvases released');
check(cleanupCalls.includes(2)&&cleanupCalls.includes(3)&&!cleanupCalls.includes(1),'failed spread cleans only staged render resources');
const oldTwoCount=cleanupCalls.filter(n=>n===2).length;
const stale=pending(2),older=context.draw(2);await tick();const newer=pending(4),latest=context.draw(4);await tick();check(stale.cancelled&&stale.canvas.width>0,'superseded canvas remains allocated until cancellation settles');check(cleanupCalls.filter(n=>n===2).length===oldTwoCount,'cancel request alone does not clean pending render resources');stale.resolve();await older;check(cleanupCalls.filter(n=>n===2).length===oldTwoCount+1,'superseded page cleaned after render settles');check(stale.canvas.width===0,'superseded settled canvas released');check(cover.width>0,'visible old canvas remains until new spread successful');newer.resolve();await latest;check(cover.width===0&&active().length===2&&active().every(c=>c.width>0),'successful swap releases detached old canvas only');
check(cleanupCalls.includes(1)&&!cleanupCalls.includes(4)&&!cleanupCalls.includes(5),'successful new spread cleans old cover but retains new pages');
const cancelled=pending(6),cancelDraw=context.draw(6);await tick();cancelled.reject('RenderingCancelledException');await cancelDraw;check(cancelled.canvas.width===0&&active().every(c=>c.width>0),'cancellation rejection releases staged backing but preserves visible pages');
check(cleanupCalls.includes(6)&&!cleanupCalls.includes(4),'cancelled new spread cleanup preserves visible resource cache');
const prior=active();nodes.get('[data-layout]').listeners.click();await tick();check(prior.every(c=>c.width===0),'spread to single releases replaced and hidden second canvas');check(papers[1].hidden&&papers[1].querySelector('canvas').width===0,'hidden paper has no allocated backing pixels');
check(cleanupCalls.includes(5)&&!cleanupCalls.includes(4),'single layout cleans hidden page resource cache only');
let visible=active()[0];const beforePersist=cleanupCalls.length;listeners.get('pagehide')({persisted:true});check(visible.width>0&&destroyCount===0,'bfcache departure retains visible backing store');
check(cleanupCalls.length===beforePersist,'bfcache does not clean visible page resources');
const departure=pending(6),drawing=context.draw(6);await tick();listeners.get('pagehide')({persisted:false});check(visible.width===0&&destroyCount===1,'permanent departure releases visible canvas');check(departure.cancelled&&departure.canvas.width>0,'permanent departure cancels but does not clear active rendering canvas');const beforeDepartureSettle=cleanupCalls.length;departure.resolve();await drawing;check(cleanupCalls.length===beforeDepartureSettle,'late departure relies on document destroy not page cleanup');check(departure.canvas.width===0,'departure pending canvas released after render settles');const count=canvases.length;await context.draw(7);check(canvases.length===count,'disposed reader cannot allocate new canvases');
}
await run();await run(true);
};
