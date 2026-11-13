const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const sourcePath=__dirname+'/../../public/js/manual-reader.mjs';
let checks=0;const check=(value,label)=>{assert(value,label);checks++;console.log('PASS '+label);};
async function run(){
  let destroyCount=0, disconnectCount=0, releaseText;
  const pendingText=new Promise(resolve=>{releaseText=resolve});
  const windowListeners=new Map();
  const addWindowListener=(type,fn,options={})=>{if(!windowListeners.has(type))windowListeners.set(type,[]);windowListeners.get(type).push({fn,once:!!options.once});};
  const dispatch=(type,event)=>{for(const listener of [...(windowListeners.get(type)||[])]){if(listener.once)windowListeners.set(type,windowListeners.get(type).filter(x=>x!==listener));listener.fn(event);}};

  const nodes=new Map(), requests=[];
  function el(tag='DIV') { return {tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:600,hidden:false,disabled:true,value:'',textContent:'',addEventListener(t,fn){this.listeners[t]=fn},setAttribute(k,v){this.attributes[k]=v},removeAttribute(k){delete this.attributes[k]},append(...x){this.children.push(...x)},replaceChildren(...x){this.children=x},replaceWith(x){this.replacedBy=x},focus(){},getContext(){return{}},querySelector(s){if(!this.nodes)this.nodes=new Map();if(!this.nodes.has(s))this.nodes.set(s,el(s==='canvas'?'CANVAS':'DIV'));return this.nodes.get(s)}};}
  const root=el();root.dataset.pdfUrl='/manuals/file.php?id=1';root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,el());return nodes.get(s)};const papers=[el(),el()];root.querySelectorAll=s=>s==='[data-paper]'?papers:[];
  const pdf={numPages:4,async getPage(n){requests.push(n);return{getViewport({scale}){return {width:300*scale,height:400*scale}},render(){return {promise:Promise.resolve(),cancel(){}}},async getTextContent(){if(n===2)await pendingText;return {items:[{str:`Text of page ${n}`}]}}}}};
  const context={console,URL,URLSearchParams,setTimeout,clearTimeout,history:{replaceState(...v){context.hash=v[2]}},location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:''},getComputedStyle(){return{paddingLeft:'0',paddingRight:'0'}},ResizeObserver:class{observe(){}disconnect(){disconnectCount++}},document:{querySelector:()=>root,createElement:tag=>el(tag.toUpperCase()),createDocumentFragment:()=>el(),addEventListener(){}},window:{matchMedia:()=>({matches:true,addEventListener(){}}),addEventListener:addWindowListener,devicePixelRatio:1},pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>({promise:Promise.resolve(pdf),destroy(){destroyCount++}})}};
  vm.createContext(context);
  let source=fs.readFileSync(sourcePath,'utf8').replace(/^import .*;\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/public/vendor/pdfjs-6.5.438/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);');
  // Expose only this private cache reference for lifecycle assertions in the VM.
  source=source.replace('const textCache = createTextCache();','const textCache = globalThis.testCache = createTextCache();');
  vm.runInContext(source,context);await context.initialized;
  const text=()=>nodes.get('[data-page-text]').children[0]?.children.map(x=>x.textContent).join(' / ');
  check(text()==='Page 1 / Text of page 1','initial reader completes and caches current text');
  check(context.testCache.size===1,'initial extracted page is cached');
  dispatch('pagehide',{persisted:true});
  check(destroyCount===0&&disconnectCount===0,'bfcache departure keeps renderer and observer alive');
  check(context.testCache.size===1,'bfcache departure preserves text cache');
  check((windowListeners.get('pagehide')||[]).length===1,'cleanup listener survives persisted departure');
  dispatch('pageshow',{persisted:true});
  nodes.get('[data-page]').value='2';nodes.get('[data-page-form]').listeners.submit({preventDefault(){}});
  await new Promise(resolve=>setTimeout(resolve,0));
  check(papers[0].querySelector('[data-page-label]').textContent===2,'restored reader still navigates while new text extraction is pending');
  const before=nodes.get('[data-page-text]').children[0].textContent;
  dispatch('pagehide',{persisted:false});
  check(destroyCount===1&&disconnectCount===1,'later permanent departure tears down renderer and observer');
  check(context.testCache.size===0&&context.testCache.characters===0,'permanent departure empties cache');
  releaseText();await new Promise(resolve=>setTimeout(resolve,0));
  check(context.testCache.size===0&&context.testCache.characters===0,'late extraction cannot repopulate closed cache');
  check(nodes.get('[data-page-text]').children[0].textContent===before,'late extraction cannot change disposed page text');
}
(async()=>{await run();console.log(checks+' lifecycle checks passed with DOM/PDF stubs, not browser bfcache acceptance.')})().catch(e=>{console.error(e);process.exitCode=1});
