/* Focused state regression only: DOM/PDF stubs, never browser acceptance. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const sourcePath=__dirname+'/../../public/js/manual-reader.mjs';
let checks=0;
const check=(value,label)=>{assert(value,label);checks++;console.log('PASS '+label);};
async function scenario(action){
  const nodes=new Map();let releaseText,requestedText;
  const held=new Promise(resolve=>releaseText=resolve),seen=new Promise(resolve=>requestedText=resolve);
  function element(tag='DIV'){
    return {tagName:tag,style:{},dataset:{},children:[],attributes:{},listeners:{},clientWidth:600,hidden:false,disabled:true,value:'',textContent:'',
      addEventListener(t,fn){this.listeners[t]=fn;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},
      append(...items){this.children.push(...items);},replaceChildren(...items){this.children=items;},replaceWith(){},focus(){},getContext(){return{};},
      querySelector(selector){this.nodes??=new Map();if(!this.nodes.has(selector))this.nodes.set(selector,element());return this.nodes.get(selector);}};
  }
  const root=element();root.dataset.pdfUrl='/manuals/file.php?id=1';
  root.querySelector=s=>{if(!nodes.has(s))nodes.set(s,element());return nodes.get(s);};
  const papers=[element(),element()];root.querySelectorAll=()=>papers;
  const pdf={numPages:3,async getPage(n){return{getViewport({scale}){return{width:300*scale,height:400*scale};},render(){return{promise:Promise.resolve(),cancel(){}};},async getTextContent(){if(n===2){requestedText();await held;}return{items:[{str:'synthetic page '+n}]};}};}};
  const context={console,URL,URLSearchParams,setTimeout,clearTimeout,history:{replaceState(){}},location:{href:'https://example.test/manuals/view.php?id=1',origin:'https://example.test',hash:''},
    getComputedStyle:()=>({paddingLeft:'0',paddingRight:'0'}),ResizeObserver:class{observe(){}disconnect(){}},
    document:{querySelector:()=>root,createElement:tag=>element(tag.toUpperCase()),createDocumentFragment:()=>element(),addEventListener(){}},
    window:{matchMedia:()=>({matches:true,addEventListener(){}}),addEventListener(){},devicePixelRatio:1},
    pdfjs:{GlobalWorkerOptions:{},AnnotationMode:{DISABLE:0},getDocument:()=>({promise:Promise.resolve(pdf),destroy(){}})}};
  vm.createContext(context);
  const source=fs.readFileSync(sourcePath,'utf8').replace(/^import .*;\n/,'').replace("const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;","const vendor = 'https://example.test/public/vendor/pdfjs-6.5.438/';").replaceAll('export ','').replace('if (root) initialize(root);','if (root) globalThis.initialized=initialize(root);');
  vm.runInContext(source,context);await context.initialized;
  const input=root.querySelector('[data-search-input]'),submit=nodes.get('[data-search-form]').listeners.submit,cancel=nodes.get('[data-search-cancel]'),status=root.querySelector('[data-search-status]'),results=root.querySelector('[data-search-results]');
  input.value='synthetic';const pending=submit({preventDefault(){}});await seen;
  check(cancel.hidden===false,'active search exposes Stop: '+action);
  if(action==='empty'){
    input.value='   ';await submit({preventDefault(){}});
    check(cancel.hidden===true,'empty replacement hides obsolete Stop');
    check(status.textContent==='Enter a word or part number.','empty replacement explains input');
    check(results.children.length===0,'empty replacement clears old results');
    releaseText();await pending;
    check(status.textContent==='Enter a word or part number.','old search cannot replace empty-search status');
    check(results.children.length===0&&cancel.hidden,'old search cannot restore results or Stop');
  }else{
    cancel.listeners.click();const count=results.children.length;
    check(cancel.hidden===true,'Stop hides itself immediately');releaseText();await pending;
    check(status.textContent==='Search stopped. Results found so far are below.','canceled search cannot overwrite stop status');
    check(results.children.length===count,'canceled search cannot append late results');
  }
}
(async()=>{await scenario('empty');await scenario('stop');console.log(checks+' search cancellation checks passed with DOM/PDF stubs, not a browser.');})().catch(e=>{console.error(e);process.exitCode=1;});
