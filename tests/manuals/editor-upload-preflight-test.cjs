/* DOM state regression only, not browser transport acceptance. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let count=0;const check=(value,label)=>{assert(value,label);count++;console.log('PASS '+label);};
function setup(withUpload=true){
  const listeners={},windowListeners={},fields={title:'Original title',password:'',manufacturer:'Maker'};
  class File{constructor(name,size){this.name=name;this.size=size;this.lastModified=1;}}
  const upload={files:[],message:'',listeners:{},reports:0,addEventListener(n,f){this.listeners[n]=f;},setCustomValidity(m){this.message=m;},reportValidity(){this.reports++;return!this.message;}};
  const maximum={value:'1048576'},feedback={textContent:''};
  const form={dataset:{unsaved:'false'},querySelector(s){return s.includes('type="file"')?(withUpload?upload:null):s.includes('MAX_FILE_SIZE')?maximum:feedback;},addEventListener(n,f){listeners[n]=f;}};
  class FormData{*[Symbol.iterator](){yield*Object.entries(fields);if(withUpload)for(const f of upload.files)yield['pdf',f];}}
  const context={document:{querySelector:()=>form},window:{addEventListener(n,f){windowListeners[n]=f;}},FormData,File};
  vm.runInNewContext(fs.readFileSync(__dirname+'/../../public/js/manual-editor.js','utf8'),context);
  const event=()=>({defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}});
  return {upload,maximum,feedback,form,fields,File,choose(size){upload.files=[new File('manual.pdf',size)];upload.listeners.change?.();},submit(){const e=event();listeners.submit(e);return e;},leave(){const e=event();windowListeners.beforeunload(e);return e;},restore(){windowListeners.pageshow({persisted:true});},listeners,event};
}
let t=setup();check(t.upload.message==='','empty initial field has no stale custom error');
t.fields.title='Kept metadata';t.choose(1048577);check(t.upload.message.includes('1.00 MiB'),'oversized selection explains effective limit');check(t.feedback.textContent===t.upload.message,'accessible status matches field error');check(t.submit().defaultPrevented,'oversized submit blocked');check(t.upload.reports===1,'invalid programmatic event reports field error');check(t.fields.title==='Kept metadata','metadata preserved');check(t.leave().defaultPrevented,'blocked upload retains unsaved warning');
t.choose(0);check(t.upload.message.includes('empty'),'empty selection rejected');check(t.submit().defaultPrevented,'empty submit blocked');
t.choose(1048576);check(t.upload.message===''&&t.feedback.textContent==='','exact limit clears error');check(!t.submit().defaultPrevented,'exact limit allowed');check(!t.leave().defaultPrevented,'genuine submission suppresses departure warning');t.restore();check(t.leave().defaultPrevented,'restored form resumes unsaved warning');
t.upload.files=[new t.File('changed-without-event.pdf',1048577)];check(t.submit().defaultPrevented,'submit rechecks silently replaced selection');
t.upload.files=[];t.upload.listeners.change();check(t.upload.message==='','clearing selection clears custom error; native required remains responsible');
for(const value of ['0','-1','bad','Infinity','1.5','9007199254740992']){t.maximum.value=value;t.choose(1);check(t.submit().defaultPrevented,'invalid or unavailable server limit '+value+' blocks transfer');}
t=setup(false);t.fields.password='not retained';check(!t.leave().defaultPrevented,'password-only edit never counted in unsaved snapshot');t.fields.title='Draft edit';check(t.leave().defaultPrevented,'existing manual metadata warning unchanged');check(!t.submit().defaultPrevented,'existing manual submit unaffected');t.restore();check(t.leave().defaultPrevented,'existing edit restored lifecycle resets submission state');
t=setup();t.fields.title='Draft';const prevented=t.event();prevented.preventDefault();t.listeners.submit(prevented);check(t.leave().defaultPrevented,'already-canceled submit does not suppress warning');
console.log(count+' upload-preflight state checks passed; native browser and server validation remain separate.');
