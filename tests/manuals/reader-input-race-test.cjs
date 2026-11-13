const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const sourcePath=__dirname+'/../../public/js/manual-reader.mjs';
let checks=0;const check=(value,label)=>{assert(value,label);checks++;console.log('PASS '+label);};
async function run(){
  let release;const pending = new Promise(resolve=>{release=resolve});
  const nodes=new Map(), requests=[];
  function el(tag='DIV') { return {tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:600,hidden:false,disabled:true,value:'',textContent:'',addEventListener(t,fn){this.listeners[t]=fn},setAttribute(k,v){this.attributes[k]=v},removeAttribute(k){delete this.attributes[k]},append(...x){this.children.push(...x)},replaceChildren(...x){this.children=x},replaceWith(x){this.replacedBy=x},focus(){},getContext(){return{}},querySelector(s){if(!this.nodes)this.nodes=new Map();if(!this.nodes.has(s))this.nodes.set(s,el(s==='canvas'?'CANVAS':'DIV'));return this.nodes.get(s)}};}
  const root=el();root.dataset.pdfUrl='/manuals/file.php?id=1';root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,el());return nodes.get(s)};const papers=[el(),el()];root.querySelectorAll=s=>s==='[data-paper]'?papers:[];
  const pdf={numPages:4,async getPage(n){requests.push(n);return{getViewport({scale}){return {width:300*scale,height:400*scale}},render(){return {promise:n===2?pending:Promise.resolve(),cancel(){}}},async getTextContent(){return {items:[{str:`Text of page ${n}`}]}}}}};
  const context={console,URL,URLSearchParams,setTimeout,clearTimeout,history:{replaceState(...v){context.hash=v[2]}},location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:''},getComputedStyle(){return{paddingLeft:'0',paddingRight:'0'}},ResizeObserver:class{observe(){}disconnect(){}},document:{querySelector:()=>root,createElement:tag=>el(tag.toUpperCase()),createDocumentFragment:()=>el(),addEventListener(){}},window:{matchMedia:()=>({matches:true,addEventListener(){}}),addEventListener(){},devicePixelRatio:1},pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>({promise:Promise.resolve(pdf),destroy(){}})}};
  vm.createContext(context);
  let source=fs.readFileSync(sourcePath,'utf8').replace(/^import .*;\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/public/vendor/pdfjs-6.5.438/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);');
  vm.runInContext(source,context);await context.initialized;
  const text=()=>nodes.get('[data-page-text]').children[0]?.children.map(x=>x.textContent).join(' / ');
  assert.equal(text(),'Page 1 / Text of page 1');
  nodes.get('[data-page]').value='2';nodes.get('[data-page-form]').listeners.submit({preventDefault(){}});
  await new Promise(resolve=>setTimeout(resolve,0));
  nodes.get('[data-page]').value='4';nodes.get('[data-page]').listeners.input?.();
  release();await new Promise(resolve=>setTimeout(resolve,0));
  check(papers[0].querySelector('[data-page-label]').textContent===2,'pending render completes');
  check(nodes.get('[data-page]').value==='4','older completed render preserves newer unsubmitted page edit');
  nodes.get('[data-page-form]').listeners.submit({preventDefault(){}});
  await new Promise(resolve=>setTimeout(resolve,0));
  check(papers[0].querySelector('[data-page-label]').textContent===4,'Go uses the edited page rather than an overwritten render value');
  check(context.hash==='#page=4','navigation hash matches latest submitted page');
  check(text()==='Page 4 / Text of page 4','accessible text matches latest submitted page');
  nodes.get('[data-page]').value='1';nodes.get('[data-page]').listeners.input?.();
  nodes.get('[data-prev]').listeners.click();await new Promise(resolve=>setTimeout(resolve,0));
  check(nodes.get('[data-page]').value===3,'explicit newer navigation supersedes an unsubmitted draft');

}
(async()=>{await run();console.log(checks+' input-race checks passed with DOM/PDF stubs, not a browser.')})().catch(e=>{console.error(e);process.exitCode=1});
