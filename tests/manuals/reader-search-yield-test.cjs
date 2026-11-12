/* Focused state regression only: DOM/PDF stubs, never browser acceptance. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const sourcePath=__dirname+'/../../public/js/manual-reader.mjs';
let checks=0;
const check=(value,label)=>{assert(value,label);checks++;console.log('PASS '+label);};
async function scenario(action,cached=true){
  const nodes=new Map(),listeners=new Map();let reads=0;
  function element(tag='DIV'){
    return {tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:600,hidden:false,disabled:true,value:'',textContent:'',
      addEventListener(t,fn){this.listeners[t]=fn;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},
      append(...items){this.children.push(...items);},replaceChildren(...items){this.children=items;},replaceWith(){},focus(){},getContext(){return{};},
      querySelector(selector){this.nodes??=new Map();if(!this.nodes.has(selector))this.nodes.set(selector,element());return this.nodes.get(selector);}};
  }
  const root=element();root.dataset.pdfUrl='/manuals/file.php?id=1';
  root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,element());return nodes.get(s);};
  const papers=[element(),element()];root.querySelectorAll=()=>papers;
  const pdf={numPages:80,async getPage(n){return{getViewport({scale}){return{width:300*scale,height:400*scale};},render(){return{promise:Promise.resolve(),cancel(){}};},async getTextContent(){reads++;return{items:[{str:'synthetic page '+n}]};}};}};
  const context={console,URL,URLSearchParams,setTimeout,clearTimeout,history:{replaceState(){}},location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:''},
    getComputedStyle:()=>({paddingLeft:'0',paddingRight:'0'}),ResizeObserver:class{observe(){}disconnect(){}},
    document:{querySelector:()=>root,createElement:tag=>element(tag.toUpperCase()),createDocumentFragment:()=>element(),addEventListener(){}},
    window:{matchMedia:()=>({matches:true,addEventListener(){}}),addEventListener(t,f){listeners.set(t,f)},devicePixelRatio:1},
    pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>({promise:Promise.resolve(pdf),destroy(){}})}};
  vm.createContext(context);
  let source=fs.readFileSync(sourcePath,'utf8').replace(/^import .*;\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/public/vendor/pdfjs-6.5.438/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);');
  source=source.replace('const textCache = createTextCache();','const textCache = globalThis.cache = createTextCache();');
  vm.runInContext(source,context);await context.initialized;
  const input=root.querySelector('[data-search-input]'),submit=nodes.get('[data-search-form]').listeners.submit,cancel=nodes.get('[data-search-cancel]'),status=root.querySelector('[data-search-status]'),results=root.querySelector('[data-search-results]');
  context.cache.clear();if(cached)for(let n=1;n<=80;n++)context.cache.set(n,'synthetic page '+n);reads=0;
  input.value=action==='all'?'page':action==='nomatch'?'missing':'synthetic';
  let atTimer=-1,replacement;
  const fired=new Promise(resolve=>setTimeout(()=>{
    atTimer=results.children.length;
    if(action==='stop')cancel.listeners.click();
    if(action==='replace'){input.value='page 79';replacement=submit({preventDefault(){}});}
    if(action==='teardown')listeners.get('pagehide')({persisted:false});
    resolve();
  },0));
  const pending=submit({preventDefault(){}});await fired;
  if(['stop','replace','teardown'].includes(action))check(atTimer===8,'timer can interrupt after first8pages: '+action+' cached='+cached);
  await pending;if(replacement)await replacement;
  if(action==='stop'){check(results.children.length===8,'Stop prevents further hits');check(status.textContent.startsWith('Search stopped.'),'Stop status not overwritten');check(cancel.hidden,'Stop control remains hidden');}
  if(action==='replace'){check(results.children.length===1,'replacement sees exactly its matching page');check(results.children[0].children[0].children[0].textContent==='Page 79','replacement search does not skip distant match');check(status.textContent==='1 matching page.','only replacement owns completion status');}
  if(action==='teardown'){check(results.children.length===8,'pagehide prevents further result mutation');check(context.cache.size===0,'pagehide closes cache');}
  if(action==='all'){check(atTimer===8,'50hit scan yields before limit');check(results.children.length===50,'50hit limit preserved');check(results.children.map(x=>x.children[0].children[0].textContent).every((v,i)=>v==='Page '+(i+1)),'no duplicates or skipped pages across yields');check(status.textContent.startsWith('First 50 matching pages'),'50hit completion message preserved');}
  if(action==='nomatch'){check(status.textContent==='No matching document text found.','full nohit search completes');check(results.children.length===0,'no false hits');if(!cached)check(reads===80,'uncached quick-resolving extraction visits all80pages exactly once');}
}
(async()=>{for(const cached of [true,false])for(const action of ['stop','replace','teardown','all','nomatch'])await scenario(action,cached);console.log(checks+' cooperative-search checks passed with real timer scheduling and DOM/PDF stubs, not browser responsiveness timing.');})().catch(e=>{console.error(e);process.exitCode=1;});
