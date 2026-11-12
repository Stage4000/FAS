/* Custom FAS visual editor. Stores a bounded rich_v1 document, never canvas HTML. */
(function () {
    'use strict';
    const source = document.getElementById('blog-body');
    if(!source)return;
    const form=source.form;
    const trackedFields=['title','slug','excerpt','body','body_format','author_name','seo_title','seo_description','cover_image','cover_alt','related_product_ids'];
    const formSnapshot=()=>JSON.stringify(trackedFields.map(name=>[name,form.elements.namedItem(name)?.value||'']));
    let baselineForm=formSnapshot(),baselineCanvas='',navigationAllowed=false;
    const serverUnsaved=form.dataset.unsaved==='true';
    let dirtyCanvas=()=>false;
    window.addEventListener('beforeunload',event=>{
        if(!navigationAllowed&&(serverUnsaved||formSnapshot()!==baselineForm||dirtyCanvas())){event.preventDefault();event.returnValue='';}
    });
    window.addEventListener('pageshow',()=>{navigationAllowed=false;});
    const leaveDialog=document.querySelector('[data-leave-dialog]');
    let pendingDestination=null,leaveTrigger=null;
    const hasUnsavedChanges=()=>serverUnsaved||formSnapshot()!==baselineForm||dirtyCanvas();
    if(leaveDialog){
        const keepEditing=leaveDialog.querySelector('[data-keep-editing]');
        const cancelLeave=()=>{pendingDestination=null;leaveDialog.close();leaveTrigger?.focus();};
        keepEditing.addEventListener('click',cancelLeave);
        leaveDialog.addEventListener('cancel',event=>{event.preventDefault();cancelLeave();});
        leaveDialog.querySelector('[data-confirm-leave]').addEventListener('click',()=>{
            if(!pendingDestination)return;
            const destination=pendingDestination;pendingDestination=null;
            navigationAllowed=true;leaveDialog.close();window.location.assign(destination);
        });
        document.addEventListener('click',event=>{
            if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||navigationAllowed||!hasUnsavedChanges())return;
            const link=event.target.closest?.('a[href]');
            if(!link||link.hasAttribute('download')||(link.target&&link.target.toLowerCase()!=='_self')||link.closest('[data-editor-canvas]'))return;
            const destination=new URL(link.href,window.location.href);
            if(!['http:','https:'].includes(destination.protocol))return;
            if(destination.origin===location.origin&&destination.pathname===location.pathname&&destination.search===location.search&&destination.hash)return;
            event.preventDefault();event.stopImmediatePropagation();
            pendingDestination=destination.href;leaveTrigger=link;
            if(!leaveDialog.open)leaveDialog.showModal();keepEditing.focus();
        },true);
    }

    const wrapper = document.getElementById('blog-visual-editor');
    if (!wrapper || !window.getSelection) {form.addEventListener('submit',()=>{navigationAllowed=true;});return;}
    const canvas = wrapper.querySelector('[data-editor-canvas]');
    const status = wrapper.querySelector('[data-editor-status]');
    const dialog = wrapper.querySelector('[data-link-dialog]');
    const imageDialog = wrapper.querySelector('[data-image-dialog]');
    const linkText = dialog.querySelector('[data-link-text]');
    const linkUrl = dialog.querySelector('[data-link-url]');
    const linkError = dialog.querySelector('[data-link-error]');
    const galleryGrid=imageDialog.querySelector('[data-gallery-grid]'),galleryStatus=imageDialog.querySelector('[data-gallery-status]'),galleryMore=imageDialog.querySelector('[data-gallery-more]');
    let galleryOffset=0,galleryAbort=null,imageTarget='body',galleryQuery='';
    function openImagePicker(target) {
        imageTarget=target;rememberSelection();imageDialog.querySelector('[data-image-error]').textContent='';
        imageDialog.querySelector('[data-image-apply]').textContent=target==='cover'?'Use as cover':'Insert image';
        imageDialog.querySelector('[data-image-src]').value=target==='cover'?form.elements.namedItem('cover_image').value:'';
        imageDialog.querySelector('[data-image-alt]').value=target==='cover'?form.elements.namedItem('cover_alt').value:'';
        imageDialog.querySelector('[data-image-caption]').value='';
        imageDialog.querySelector('[data-image-caption]').hidden=target==='cover';
        imageDialog.querySelector('label[for="blog-image-caption"]').hidden=target==='cover';
        imageDialog.querySelector('[data-gallery-selected]').textContent=target==='cover'&&form.elements.namedItem('cover_image').value?'Current cover: '+form.elements.namedItem('cover_image').value:'No image selected.';
        imageDialog.showModal();imageDialog.querySelector('[data-gallery-search]').focus();loadGallery();
    }
    form.querySelector('[data-cover-picker]')?.addEventListener('click',()=>openImagePicker('cover'));
    function validGalleryPath(src){return /^\/gallery\/[A-Za-z0-9_-][A-Za-z0-9_.\/-]*\.(jpe?g|png|webp)$/i.test(src)&&!src.includes('..')&&!src.includes('//');}
    async function loadGallery(more=false) {
        if(galleryAbort)galleryAbort.abort();galleryAbort=new AbortController();
        const request=galleryAbort;
        const query=imageDialog.querySelector('[data-gallery-search]').value.trim();
        if(more&&query!==galleryQuery)more=false;
        galleryQuery=query;
        if(!more){galleryOffset=0;galleryGrid.replaceChildren();}
        galleryStatus.textContent='Loading photos…';galleryMore.hidden=true;
        try {
            const response=await fetch('blog-images.php?q='+encodeURIComponent(query)+'&offset='+galleryOffset+'&limit=24',{credentials:'same-origin',cache:'no-store',signal:request.signal,headers:{Accept:'application/json'}});
            if(!response.ok)throw new Error('Gallery unavailable.');
            const result=await response.json();if(!Array.isArray(result.images))throw new Error('Invalid gallery response.');
            if(request!==galleryAbort)return;
            for(const item of result.images) {
                if(!item||typeof item.url!=='string'||!validGalleryPath(item.url))continue;
                const button=document.createElement('button');button.type='button';button.className='blog-gallery-choice';button.setAttribute('aria-pressed','false');
                const image=document.createElement('img');image.src=item.url;image.alt='';image.loading='lazy';
                const name=document.createElement('span');name.textContent=typeof item.name==='string'?item.name:item.url.split('/').pop();button.append(image,name);
                button.addEventListener('click',()=>{
                    galleryGrid.querySelectorAll('button').forEach(choice=>choice.setAttribute('aria-pressed','false'));button.setAttribute('aria-pressed','true');
                    imageDialog.querySelector('[data-image-src]').value=item.url;imageDialog.querySelector('[data-gallery-selected]').textContent='Selected: '+name.textContent;
                    imageDialog.querySelector('[data-image-alt]').focus();
                });galleryGrid.append(button);
            }
            galleryOffset=Number.isInteger(result.offset)?result.offset+result.images.length:galleryOffset+result.images.length;
            galleryMore.hidden=result.hasMore!==true;
            galleryStatus.textContent=galleryGrid.children.length?galleryGrid.children.length+' photos shown. Choose a photo, then describe it.':'No matching photos. Try a different filename.';
            if(result.truncated===true)galleryStatus.textContent=(galleryGrid.children.length?galleryGrid.children.length+' photos shown.':'No photos found in the scanned subset.')+' Results are incomplete because the scan limit was reached. Use the advanced path for a known image.';
        }catch(error){if(request===galleryAbort&&error.name!=='AbortError')galleryStatus.textContent='The gallery could not be loaded. Try Search again or use the advanced image path.';}
    }
    imageDialog.querySelector('[data-gallery-search-button]').addEventListener('click',()=>loadGallery());
    imageDialog.querySelector('[data-gallery-search]').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();loadGallery();}});
    galleryMore.addEventListener('click',()=>loadGallery(true));
    imageDialog.addEventListener('close',()=>{if(galleryAbort)galleryAbort.abort();});
    let savedRange = null, history = [], cursor = -1, restoring = false, serializationError = false;
    function safeUrl(value) {
        if (!value || /[\x00-\x20\x7f\\]/.test(value)) return false;
        if (value[0] === '/') return value[1] !== '/';
        try { const url = new URL(value); return ['http:', 'https:'].includes(url.protocol) && !!url.hostname && !url.username && !url.password; }
        catch (_) { return false; }
    }
    function runs(root, inherited={}) {
        const result=[];
        function walk(node,marks) {
            if(node.nodeType===Node.TEXT_NODE) { if(node.nodeValue) result.push({text:node.nodeValue.replace(/\u00a0/g,' '),...marks});return; }
            if(node.nodeType!==Node.ELEMENT_NODE)return;
            const tag=node.tagName ? node.tagName.toUpperCase() : ''; 
            if(['SCRIPT','STYLE','IFRAME','OBJECT','EMBED','IMG','SVG','MATH','INPUT','BUTTON','UL','OL'].includes(tag))return;
            if(tag==='BR'){result.push({text:'\n',...marks});return;}
            if(['P','DIV'].includes(tag)&&result.length&&!result[result.length-1].text.endsWith('\n'))result.push({text:'\n',...marks});
            const next={...marks};
            if(['B','STRONG'].includes(tag))next.bold=true;
            if(['I','EM'].includes(tag))next.italic=true;
            if(tag==='U')next.underline=true;
            // Native browser formatting sometimes cancels inherited marks with spans.
            if(node.style.fontWeight==='normal'||node.style.fontWeight==='400')delete next.bold;
            if(node.style.fontStyle==='normal')delete next.italic;
            if(node.style.textDecoration==='none'||node.style.textDecorationLine==='none')delete next.underline;
            if(tag==='A'&&safeUrl(node.getAttribute('href')||''))next.href=node.getAttribute('href');
            for(const child of node.childNodes)walk(child,next);
        }
        for(const child of root.childNodes)walk(child,inherited);
        const merged=[];
        for(const run of result) {
            const previous=merged[merged.length-1];
            if(previous&&['bold','italic','underline','href'].every(key=>previous[key]===run[key]))previous.text+=run.text;
            else merged.push(run);
        }
        return merged;
    }
    function serialize(root) {
        const blocks=[]; let loose=[];
        function flush(){loose=loose.filter(run=>run.text!=='');if(loose.length){blocks.push({type:'paragraph',children:loose});loose=[];}}
        function addImage(img,caption='') {
            blocks.push({type:'image',src:img.getAttribute('src')||'',alt:img.getAttribute('alt')||'',caption});
        }
        for(const node of root.childNodes) {
            const tag=node.tagName ? node.tagName.toUpperCase() : ''; 
            if(node.nodeType===Node.ELEMENT_NODE&&node.hasAttribute('data-editor-placeholder')&&!node.textContent)continue;
            if(tag==='UL'||tag==='OL') {
                if(node.querySelector('li ul,li ol'))throw new Error('Nested lists cannot be saved. Undo the indentation or use a single-level list. Your text remains in the editor.');
                if(node.querySelector('figure,img,blockquote,h1,h2,h3,h4,h5,h6'))throw new Error('List items can contain text and line breaks only. Undo or move images, quotes and headings outside the list. Your content remains in the editor.');
                flush();const items=[];
                function listItems(list) {
                    for(const li of list.children)if(li.tagName==='LI') {
                        items.push(runs(li));
                        for(const nested of li.children)if(['UL','OL'].includes(nested.tagName))listItems(nested);
                    }
                }
                listItems(node);
                for(let i=0;i<items.length;i+=100)blocks.push({type:tag==='UL'?'bullet_list':'ordered_list',items:items.slice(i,i+100)});
            } else if(tag==='FIGURE') {
                flush();const img=node.querySelector('img');
                if(img)addImage(img,node.querySelector('figcaption')?.textContent||'');
                else blocks.push({type:'paragraph',children:runs(node)});
            } else if(tag==='IMG'){flush();addImage(node);}
            else if(['P','DIV','H1','H2','H3','H4','H5','H6','BLOCKQUOTE'].includes(tag)) {
                flush();
                if(tag==='BLOCKQUOTE') {
                    if(node.querySelector('ul,ol,figure,img,blockquote,h1,h2,h3,h4,h5,h6'))throw new Error('Quotes can contain text and line breaks only. Undo or move lists, headings and images outside the quote. Your content remains in the editor.');
                    blocks.push({type:'blockquote',children:runs(node)});
                }
                else if(node.querySelector('p,div,ul,ol,h2,h3,figure,img'))blocks.push(...serialize(node).blocks);
                else if(tag==='H2'||tag==='H3')blocks.push({type:'heading',level:Number(tag[1]),children:runs(node)});
                else if(tag==='BLOCKQUOTE')blocks.push({type:'blockquote',children:runs(node)});
                else blocks.push({type:'paragraph',children:runs(node)});
            } else if(node.nodeType===Node.TEXT_NODE){if(node.nodeValue.trim()||loose.length)loose.push({text:node.nodeValue});}
            else if(node.nodeType===Node.ELEMENT_NODE){const holder=document.createElement('span');holder.append(node.cloneNode(true));loose.push(...runs(holder));}
        }
        flush();return {version:1,blocks};
    }
    function countDocument(doc) {
        let count=0,runCount=0,imageCount=0;
        for(const block of doc.blocks){
            if(block.type==='image'){imageCount++;count+=Array.from(block.alt+block.caption).length;}
            for(const group of block.items||[block.children||[]])for(const run of group){count+=Array.from(run.text).length;runCount++;}
        }
        return {count,runCount,imageCount};
    }
    function rememberSelection() {
        const selection = window.getSelection();
        if (selection.rangeCount && canvas.contains(selection.anchorNode) && canvas.contains(selection.focusNode)) {
            savedRange = selection.getRangeAt(0).cloneRange();
            for(const mark of ['bold','italic','underline'])wrapper.querySelector('[data-command="'+mark+'"]').setAttribute('aria-pressed',document.queryCommandIndeterm(mark)?'mixed':String(document.queryCommandState(mark)));
        }
    }
    function restoreSelection() {
        canvas.focus(); const selection = window.getSelection(); selection.removeAllRanges();
        if (savedRange && canvas.contains(savedRange.commonAncestorContainer)) selection.addRange(savedRange);
        else { const range = document.createRange(); range.selectNodeContents(canvas); range.collapse(false); selection.addRange(range); }
    }
    function selectionOffsets() {
        const selection=window.getSelection(); if (!selection.rangeCount || !canvas.contains(selection.anchorNode)) return null;
        const range=selection.getRangeAt(0), start=range.cloneRange(), end=range.cloneRange();
        start.selectNodeContents(canvas);start.setEnd(range.startContainer,range.startOffset);
        end.selectNodeContents(canvas);end.setEnd(range.endContainer,range.endOffset);
        return [start.toString().length,end.toString().length];
    }
    function setOffsets(offsets) {
        if (!offsets) return;
        const walker=document.createTreeWalker(canvas,NodeFilter.SHOW_TEXT); let node, count=0, points=[];
        while ((node=walker.nextNode())) { for (let i=0;i<2;i++) if (!points[i] && offsets[i]<=count+node.length) points[i]=[node,Math.max(0,offsets[i]-count)]; count+=node.length; }
        if (points[0] && points[1]) { const range=document.createRange();range.setStart(...points[0]);range.setEnd(...points[1]);const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);savedRange=range.cloneRange(); }
    }
    function sync(record=true) {
        let doc;
        try { doc=serialize(canvas); }
        catch(error) {serializationError=true;canvas.setAttribute('aria-invalid','true');status.textContent=error.message;wrapper.querySelector('[data-command="undo"]').disabled=!history[cursor];return false;}
        serializationError=false;
        source.value=JSON.stringify(doc);
        const {count,runCount,imageCount}=countDocument(doc);
        const invalid=count>60000||runCount>2000||imageCount>20||doc.blocks.length>500||new TextEncoder().encode(source.value).length>400000;
        status.textContent = count.toLocaleString() + ' / 60,000 characters' + (invalid ? '. Shorten the article before saving.' : '. Changes are not saved until you choose Save draft.');
        source.setCustomValidity('');
        canvas.setAttribute('aria-invalid',String(invalid));
        if (record && !restoring && (!history[cursor] || history[cursor].html !== canvas.innerHTML)) {
            history=history.slice(0,cursor+1);history.push({html:canvas.innerHTML,selection:selectionOffsets()});
            if(history.length>100) history.shift();cursor=history.length-1;
        }
        wrapper.querySelector('[data-command="undo"]').disabled=cursor<=0;
        wrapper.querySelector('[data-command="redo"]').disabled=cursor>=history.length-1;
        return true;
    }
    function undo(direction) {
        const next=serializationError&&direction===-1?cursor:cursor+direction; if(next<0||next>=history.length)return;
        restoring=true;cursor=next;canvas.innerHTML=history[cursor].html;canvas.focus();setOffsets(history[cursor].selection);sync(false);restoring=false;
    }
    function command(name) {
        if(name==='undo'||name==='redo'){undo(name==='undo'?-1:1);return;}
        rememberSelection();
        restoreSelection();
        if(name==='link') {
            rememberSelection();linkText.value=window.getSelection().toString();linkText.readOnly=!window.getSelection().isCollapsed;linkUrl.value='';linkError.textContent='';dialog.showModal();(linkText.value?linkUrl:linkText).focus();return;
        }
        if(name==='image') {openImagePicker('body');return;}
        if(['p','h2','h3','blockquote'].includes(name)) {
            if(document.queryCommandState('insertUnorderedList'))document.execCommand('insertUnorderedList');
            if(document.queryCommandState('insertOrderedList'))document.execCommand('insertOrderedList');
            document.execCommand('formatBlock',false,name);
        }
        if(name==='list') document.execCommand('insertUnorderedList');
        if(name==='ordered') document.execCommand('insertOrderedList');
        if(['bold','italic','underline'].includes(name)) document.execCommand(name);
        if(name==='unlink') document.execCommand('unlink');
        rememberSelection();sync();
    }
    wrapper.querySelectorAll('[data-command]').forEach(button=>{
        if(['bold','italic','underline'].includes(button.dataset.command))button.setAttribute('aria-pressed','false');
        button.addEventListener('mousedown',event=>event.preventDefault());
        button.addEventListener('click',()=>command(button.dataset.command));
    });
    wrapper.querySelector('[role=toolbar]').addEventListener('keydown',event=>{
        if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
        const buttons=Array.from(wrapper.querySelectorAll('[data-command]')).filter(button=>!button.disabled);
        const index=buttons.indexOf(document.activeElement);if(index<0)return;
        event.preventDefault();let next=event.key==='Home'?0:event.key==='End'?buttons.length-1:(index+(event.key==='ArrowRight'?1:-1)+buttons.length)%buttons.length;
        buttons[next].focus();
    });
    dialog.querySelector('[data-link-cancel]').addEventListener('click',()=>{dialog.close();restoreSelection();});
    dialog.addEventListener('cancel',()=>setTimeout(restoreSelection,0));
    dialog.querySelector('[data-link-apply]').addEventListener('click',()=>{
        const label=linkText.value,url=linkUrl.value.trim();
        if(!label.trim()||Array.from(label).length>60000||!safeUrl(url)||url.length>2000){linkError.textContent='Enter link text and a valid https://, http:// or /site-path URL.';return;}
        if(savedRange&&savedRange.cloneContents().querySelector('p,div,h1,h2,h3,h4,h5,h6,ul,ol,li,blockquote,figure')) {linkError.textContent='Select text within one paragraph or list item for a link. Your selection has not been changed.';return;}
        dialog.close();restoreSelection();
        const a=document.createElement('a');a.setAttribute('href',url);
        const range=window.getSelection().getRangeAt(0);
        if(range.toString()===label) {
            const fragment=range.extractContents();
            fragment.querySelectorAll('a').forEach(old=>old.replaceWith(...old.childNodes));
            a.append(fragment);
        } else {a.textContent=label;range.deleteContents();}
        range.insertNode(a);range.setStartAfter(a);range.collapse(true);
        const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);rememberSelection();sync();
    });
    imageDialog.querySelector('[data-image-cancel]').addEventListener('click',()=>{imageDialog.close();restoreSelection();});
    imageDialog.addEventListener('cancel',()=>setTimeout(restoreSelection,0));
    imageDialog.querySelector('[data-image-apply]').addEventListener('click',()=>{
        const src=imageDialog.querySelector('[data-image-src]').value.trim(),alt=imageDialog.querySelector('[data-image-alt]').value.trim(),caption=imageDialog.querySelector('[data-image-caption]').value.trim();
        if(!validGalleryPath(src)||!alt){imageDialog.querySelector('[data-image-error]').textContent='Choose a gallery photo and describe the image.';return;}
        if(imageTarget==='cover') {
            form.elements.namedItem('cover_image').value=src;
            if(alt)form.elements.namedItem('cover_alt').value=alt;
            imageDialog.close();form.querySelector('[data-cover-picker]').focus();return;
        }
        imageDialog.close();restoreSelection();
        const figure=document.createElement('figure'),img=document.createElement('img');figure.contentEditable='false';img.src=src;img.alt=alt;figure.append(img);
        if(caption){const label=document.createElement('figcaption');label.textContent=caption;figure.append(label);}
        const range=window.getSelection().getRangeAt(0);let block=range.startContainer.nodeType===Node.ELEMENT_NODE?range.startContainer:range.startContainer.parentElement;
        while(block&&block.parentElement!==canvas)block=block.parentElement;
        if(block)block.after(figure);else canvas.append(figure);
        const paragraph=document.createElement('p');paragraph.dataset.editorPlaceholder='true';paragraph.append(document.createElement('br'));figure.after(paragraph);
        const caret=document.createRange();caret.selectNodeContents(paragraph);caret.collapse(true);const selection=window.getSelection();selection.removeAllRanges();selection.addRange(caret);rememberSelection();sync();
    });
    canvas.addEventListener('click',event=>{if(event.target.closest('a'))event.preventDefault();});
    canvas.addEventListener('input',()=>{rememberSelection();sync();});
    canvas.addEventListener('paste',event=>{
        event.preventDefault(); const value=(event.clipboardData||window.clipboardData).getData('text/plain');
        if(Array.from(value).length>60000){status.textContent='Paste is too long. Paste less than 60,000 characters.';return;}
        restoreSelection();document.execCommand('insertText',false,value);rememberSelection();sync();
    });
    canvas.addEventListener('drop',event=>{event.preventDefault();status.textContent='Drag-and-drop is not supported. Paste text or choose a photo with the Image picker.';});
    canvas.addEventListener('keydown',event=>{
        if(event.ctrlKey||event.metaKey){
            const key=event.key.toLowerCase();
            if(['b','i','u','k','z','y'].includes(key)){event.preventDefault();if(key==='z')undo(event.shiftKey?1:-1);else if(key==='y')undo(1);else command({b:'bold',i:'italic',u:'underline',k:'link'}[key]);}
        }
    });
    canvas.addEventListener('beforeinput',event=>{
        if(event.inputType==='historyUndo'||event.inputType==='historyRedo'){event.preventDefault();undo(event.inputType==='historyUndo'?-1:1);}
    });
    document.addEventListener('selectionchange',rememberSelection);
    source.form.addEventListener('submit',event=>{
        sync(false);
        if(canvas.getAttribute('aria-invalid')==='true'){event.preventDefault();canvas.focus();}
        else navigationAllowed=true;
    });
    if(!canvas.childNodes.length)canvas.innerHTML='<p><br></p>';
    canvas.querySelectorAll('figure').forEach(figure=>figure.contentEditable='false');
    if(canvas.lastElementChild?.tagName==='FIGURE'){const paragraph=document.createElement('p');paragraph.dataset.editorPlaceholder='true';paragraph.append(document.createElement('br'));canvas.append(paragraph);}
    source.hidden=true;source.removeAttribute('required');source.setAttribute('aria-hidden','true');wrapper.hidden=false;
    const original=source.value;document.execCommand('styleWithCSS',false,false);
    if(sync()===false){source.hidden=false;source.removeAttribute('aria-hidden');wrapper.hidden=true;source.value=original;return;}
    source.form.querySelector('[name=body_format]').value='rich_v1';
    baselineForm=formSnapshot();baselineCanvas=canvas.innerHTML;dirtyCanvas=()=>canvas.innerHTML!==baselineCanvas;
    // Expose only deterministic serializer primitives for isolated regression tests.
    window.FASBlogEditor={serialize,safeUrl,countDocument,canvas,source};
})();
