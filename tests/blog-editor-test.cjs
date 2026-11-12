// Dependency-free serializer/negative controls. This is not browser acceptance.
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert');
const file=path.join(__dirname,'../admin/js/blog-editor.js'),source=fs.readFileSync(file,'utf8');
class Text {constructor(value){this.nodeType=3;this.nodeValue=value;}get textContent(){return this.nodeValue;}cloneNode(){return new Text(this.nodeValue);}}
class Element {
 constructor(tag,children=[],attrs={},style={}){this.nodeType=1;this.tagName=tag.toUpperCase();this.childNodes=children;this.attrs=attrs;this.style=style;}
 get children(){return this.childNodes.filter(n=>n.nodeType===1);}
 get textContent(){return this.childNodes.map(n=>n.textContent).join('');}
 getAttribute(name){return this.attrs[name]??null;}hasAttribute(name){return Object.hasOwn(this.attrs,name);}
 append(...nodes){this.childNodes.push(...nodes);}
 cloneNode(){return new Element(this.tagName,this.childNodes.map(n=>n.cloneNode(true)),{...this.attrs},{...this.style});}
 querySelector(selector){
  const choices=selector.split(',').map(s=>s.trim().toUpperCase());
  for(const child of this.children){
   if(choices.some(choice=>!choice.includes(' ')&&choice===child.tagName))return child;
   for(const choice of choices.filter(c=>c.includes(' '))){const [outer,inner]=choice.split(' ');if(child.tagName===outer){const found=child.querySelector(inner);if(found)return found;}}
   const found=child.querySelector(selector);if(found)return found;
  }return null;
 }
}
const text=value=>new Text(value),element=(tag,...children)=>new Element(tag,children),root=(...children)=>element('div',...children);
const context=vm.createContext({Node:{TEXT_NODE:3,ELEMENT_NODE:1},URL,document:{createElement:tag=>element(tag)}});
const start=source.indexOf('    function safeUrl('),end=source.indexOf('    function rememberSelection(');
assert(start>=0&&end>start,'Serializer functions remain inspectable');
vm.runInContext(source.slice(start,end)+';globalThis.api={safeUrl,serialize,countDocument};',context);
const api=context.api,serialize=node=>JSON.parse(JSON.stringify(api.serialize(node)));let passed=0;
function check(value,message){assert(value,message);passed++;console.log('PASS '+message);}
let doc=serialize(root(element('p',text('# [x](/path) \\ **literal** <script>'))));
check(doc.blocks[0].children[0].text==='# [x](/path) \\ **literal** <script>','Literal punctuation remains text');
doc=serialize(root(element('strong',text('one')),text(' '),element('em',text('two'))));
check(doc.blocks[0].children.map(r=>r.text).join('')==='one two','Root inline whitespace survives');
check(doc.blocks[0].children[0].bold&&doc.blocks[0].children[2].italic,'Root mixed marks survive');
doc=serialize(root(element('blockquote',element('p',text('one')),element('p',text('two')))));
check(doc.blocks[0].type==='blockquote'&&doc.blocks[0].children.map(r=>r.text).join('')==='one\ntwo','Quote wrapper and paragraph boundaries survive');
assert.throws(()=>serialize(root(element('ul',element('li',text('one'),element('ul',element('li',text('two'))))))),/Nested lists/);passed++;
for(const child of [element('ul',element('li',text('keep'))),element('ol',element('li',text('keep'))),element('figure',new Element('img',[],{src:'/gallery/keep.jpg',alt:'keep'})),new Element('img',[],{src:'/gallery/keep.jpg',alt:'keep'})]) {
 assert.throws(()=>serialize(root(element('blockquote',child))),/Quotes can contain text/);passed++;
}
for(const child of [element('figure',new Element('img',[],{src:'/gallery/keep.jpg',alt:'keep'})),new Element('img',[],{src:'/gallery/keep.jpg',alt:'keep'}),element('blockquote',text('keep'))]) {
 assert.throws(()=>serialize(root(element('ul',element('li',child)))),/List items can contain text/);passed++;
}
const image=new Element('img',[],{src:'/gallery/test.jpg',alt:'Image only'});
doc=serialize(root(element('figure',image)));
check(doc.blocks[0].type==='image'&&doc.blocks[0].alt==='Image only','Image-only document survives');
doc=serialize(root(element('figure',text('Text after removal'))));check(doc.blocks[0].children[0].text==='Text after removal','Text after image removal survives');
doc=serialize(root(element('p',text('one'),element('br'),text('two'))));check(doc.blocks[0].children.map(r=>r.text).join('')==='one\ntwo','BR is one newline');
doc=serialize(root(element('p',text('safe'),element('script',text('bad')),element('svg',text('bad')),element('iframe',text('bad')))));
check(doc.blocks[0].children.map(r=>r.text).join('')==='safe','Unsafe embedded trees are excluded');
doc=serialize(root(element('p',new Element('a',[element('strong',text('bold')),text(' '),element('em',text('italic'))],{href:'/parts?a=(b)'}))));
check(doc.blocks[0].children[0].bold&&doc.blocks[0].children[0].href==='/parts?a=(b)'&&doc.blocks[0].children[2].italic,'Mixed link runs preserve marks and URL punctuation');
doc=serialize(root(element('p',element('strong',new Element('span',[text('normal')],{},{fontWeight:'normal'})))));
check(!doc.blocks[0].children[0].bold,'Native explicit unbold cancels inherited mark');
doc=serialize(root(element('ol',...Array.from({length:101},(_,i)=>element('li',text(String(i)))))));
check(doc.blocks.length===2&&doc.blocks[0].items.length===100&&doc.blocks[1].items.length===1,'Long list is split at validated block bound without lost items');
doc=serialize(root(new Element('p',[element('br')],{'data-editor-placeholder':'true'})));
check(doc.blocks.length===0,'Empty image caret placeholder is not stored');
doc=serialize(root(new Element('p',[text('Typed after image')],{'data-editor-placeholder':'true'})));
check(doc.blocks[0].children[0].text==='Typed after image','Typed placeholder content is stored');
for(const url of ['javascript:alert(1)','data:text/html,test','//evil.test','https://user:pass@example.test','/\\evil.test','https://example.test/\n'])check(!api.safeUrl(url),'Unsafe URL rejected '+JSON.stringify(url));
for(const url of ['/products.php','https://example.test/a_(b)?x=1&y=2'])check(api.safeUrl(url),'Supported URL accepted '+url);
check(source.includes("getData('text/plain')")&&!source.includes("getData('text/html')"),'Paste uses plain text only');
check(source.includes('linkText.readOnly=!window.getSelection().isCollapsed'),'Existing selection link label is read-only');
check(!source.includes('toString().slice(0,200)'),'Selected link text is not truncated');
check(source.includes("source.form.addEventListener('submit'")&&source.includes("if(canvas.getAttribute('aria-invalid')==='true'){event.preventDefault();canvas.focus();}"),'Rejected serializer submission retains specific recovery message');
check(source.includes("if(sync()===false){source.hidden=false;")&&source.includes('source.value=original;return;'),'Failed initialization preserves original source');
check(source.includes("if(!canvas.childNodes.length)")&&!source.includes("if(!canvas.textContent.trim())"),'Image-only initialization is not mistaken for empty text');
const historyContext=vm.createContext({TextEncoder,source:{value:'',setCustomValidity(){}},status:{textContent:''},canvas:{innerHTML:'oversized',attributes:{},setAttribute(k,v){this.attributes[k]=v},getAttribute(k){return this.attributes[k]},focus(){}},wrapper:{querySelector(){return {disabled:false}}},serialize(){return {blocks:[{type:'paragraph',children:[{text:'x'}]}]}},countDocument(){return {count:60001,runCount:1,imageCount:0}},selectionOffsets(){return null},setOffsets(){}});
vm.runInContext('let history=[{html:"valid",selection:null}],cursor=0,restoring=false,serializationError=false;'+source.slice(source.indexOf('    function sync('),source.indexOf('    function command('))+';sync();undo(-1);globalThis.result={html:canvas.innerHTML,cursor};',historyContext);
check(historyContext.result.html==='valid'&&historyContext.result.cursor===0,'Undo steps back from recorded over-limit state');
check(source.includes("[data-command=\"undo\"]').disabled=!history[cursor]"),'First serializer rejection enables toolbar Undo when a valid snapshot exists');
check(source.includes("window.addEventListener('pageshow',()=>{navigationAllowed=false;})"),'Back-forward cache restore resets navigation warning suppression');
check(source.includes('if(more&&query!==galleryQuery)more=false;'),'Gallery changed search resets paging');
check(source.includes("request===galleryAbort&&error.name!=='AbortError'"),'Stale gallery failures cannot overwrite newer result status');
check(source.includes("result.truncated===true")&&source.includes('Results are incomplete'),'Gallery scan truncation is disclosed');
check(source.includes("setAttribute('aria-pressed'")&&source.includes('document.queryCommandState(mark)'),'Inline toolbar reports current selection formatting');
const navEvents={},dialogEvents={},keepEvents={},leaveEvents={};let dirty=true,assigned=null,focusReturned=false;
const keep={addEventListener:(n,f)=>keepEvents[n]=f,focus(){this.focused=true}};
const leave={addEventListener:(n,f)=>leaveEvents[n]=f};
const modal={open:false,querySelector:s=>s==='[data-keep-editing]'?keep:leave,addEventListener:(n,f)=>dialogEvents[n]=f,showModal(){this.open=true},close(){this.open=false}};
const navWindow={location:{href:'https://fixture.invalid/admin/blog-edit.php?id=1',assign:v=>assigned=v}};
const navContext=vm.createContext({URL,window:navWindow,location:new URL(navWindow.location.href),document:{querySelector:()=>modal,addEventListener:(n,f)=>navEvents[n]=f},formSnapshot:()=>dirty?'changed':'initial',dirtyCanvas:()=>false});
vm.runInContext("let serverUnsaved=false,baselineForm='initial',navigationAllowed=false;"+source.slice(source.indexOf('    const leaveDialog='),source.indexOf('    const wrapper =')),navContext);
const navLink={href:'https://fixture.invalid/admin/blog.php',target:'',hasAttribute:()=>false,closest:()=>null,focus(){focusReturned=true}};
const click=(overrides={})=>{const e={button:0,target:{closest:()=>navLink},preventDefault(){this.prevented=true},stopImmediatePropagation(){},...overrides};navEvents.click(e);return e};
check(click().prevented&&modal.open&&keep.focused,'Dirty current-tab navigation opens first-party dialog with safe default');
keepEvents.click();check(!modal.open&&focusReturned&&assigned===null,'Keep editing cancels navigation and restores trigger focus');
check(!click({ctrlKey:true}).prevented,'Modified new-tab click is not intercepted');
navLink.target='_blank';check(!click().prevented,'Explicit new tab is not intercepted');navLink.target='';
navLink.href=navWindow.location.href+'#details';check(!click().prevented,'Same-document fragment is not intercepted');navLink.href='https://fixture.invalid/admin/blog.php';
click();dialogEvents.cancel({preventDefault(){}});check(!modal.open&&assigned===null,'Escape cancels without navigation');
dirty=false;check(!click().prevented,'Clean page navigation remains native');dirty=true;
click();leaveEvents.click();leaveEvents.click();check(assigned===navLink.href&&!modal.open,'Confirm consumes exact pending URL once');
console.log(JSON.stringify({checks:passed,scope:'Node built-in serializer/static and history controls; not browser acceptance'}));
