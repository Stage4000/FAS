import * as pdfjs from '../vendor/pdfjs-6.5.438/pdf.mjs';

// This is an original reader, not a port of a proprietary flipbook library.
// No annotation layer, embedded actions, form widgets, or PDF JavaScript runs here.
const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;
pdfjs.GlobalWorkerOptions.workerSrc = `${vendor}pdf.worker.mjs`;
export function spreadStart(page, spread) { return !spread || page === 1 ? page : page - page % 2; }
export function pagesFor(page, count, spread) {
  const start = Math.max(1, Math.min(count, spreadStart(page, spread)));
  return spread && start > 1 && start < count ? [start, start + 1] : [start];
}
export function nextPage(page, count, spread, direction) {
  const start = spreadStart(page, spread);
  return Math.max(1, Math.min(count, start + (spread ? (direction > 0 && start === 1 ? 1 : 2) : 1) * direction));
}
export function queryPage(hash, count) {
  const value = new URLSearchParams(hash.replace(/^#/, '')).get('page');
  return value && /^\d+$/.test(value) ? Math.max(1, Math.min(count, Number(value))) : 1;
}

// Limit retained extracted text by both page count and UTF-16 code units.
// This bounds this cache, not PDF.js worker/native memory or a single extraction.
export function createTextCache(maxCharacters = 2_000_000, maxEntries = 80) {
  if (!Number.isSafeInteger(maxCharacters) || maxCharacters < 1 || !Number.isSafeInteger(maxEntries) || maxEntries < 1) throw new RangeError('Invalid text cache bounds');
  const entries = new Map(); let characters = 0, closed = false;
  return {
    get size() { return entries.size; },
    get characters() { return characters; },
    has(key) { return entries.has(key); },
    get(key) {
      if (!entries.has(key)) return undefined;
      const value = entries.get(key); entries.delete(key); entries.set(key, value); return value;
    },
    set(key, text) {
      if (closed) return;
      if (typeof text !== 'string') throw new TypeError('Expected extracted text');
      if (entries.has(key)) { characters -= entries.get(key).length; entries.delete(key); }
      // Oversized pages remain fully searchable/readable but are not retained.
      if (text.length > maxCharacters) return;
      while (entries.size >= maxEntries || characters + text.length > maxCharacters) {
        const oldest = entries.keys().next().value;
        characters -= entries.get(oldest).length; entries.delete(oldest);
      }
      entries.set(key, text); characters += text.length;
    },
    clear() { entries.clear(); characters = 0; },
    close() { closed = true; entries.clear(); characters = 0; },
  };
}

const root = document.querySelector('[data-manual-reader]');
if (root) initialize(root);
async function initialize(root) {
  const $ = selector => root.querySelector(selector);
  const status = $('[data-status]'), stage = $('[data-stage]'), book = $('[data-book]');
  const papers = [...root.querySelectorAll('[data-paper]')];
  const controls = ['[data-prev]', '[data-next]', '[data-page]', '[data-go]', '[data-layout]', '[data-zoom-in]', '[data-zoom-out]', '[data-fit]', '[data-search-submit]'];
  let loadingTask = null, loadError = null, disposed = false;
  let pdf = null, page = 1, zoom = 1, preferredSpread = true, renderEpoch = 0, searchEpoch = 0;
  let renders = [], animations = [], motionOff = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let visiblePages = [], resizeTimer, lastWidth = stage.clientWidth;
  let pageInputEdited = false;
  $('[data-page]').addEventListener('input', () => { pageInputEdited = true; });
  const textCache = createTextCache();
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const wide = () => stage.clientWidth >= 740;
  const spread = () => preferredSpread && wide();
  const say = message => { status.textContent = message; };
  const updateMotion = () => {
    $('[data-motion]').textContent = motionOff ? 'Page animation off' : 'Page animation on';
    $('[data-motion]').setAttribute('aria-pressed', String(motionOff));
  };
  updateMotion();
  reducedMotion.addEventListener('change', event => { motionOff = event.matches; updateMotion(); });
  $('[data-motion]').addEventListener('click', () => { motionOff = !motionOff; updateMotion(); });
  const stopAnimations = () => { animations.forEach(a => a.cancel()); animations = []; papers.forEach(p => { p.style.transform = ''; }); };
  const animate = async (element, frames, options) => {
    if (motionOff || reducedMotion.matches || !element.animate) return;
    const animation = element.animate(frames, options); animations.push(animation);
    try { await animation.finished; } catch (_) { /* A newer navigation owns the view. */ }
  };
  const pendingText = new Map();
  const textFor = async number => {
    if (disposed) throw new Error('The reader is closed.');
    if (textCache.has(number)) return textCache.get(number);
    if (pendingText.has(number)) return pendingText.get(number);
    const task = (async () => {
      const p = await pdf.getPage(number), content = await p.getTextContent();
      const text = content.items.filter(x => typeof x.str === 'string').map(x => x.str + (x.hasEOL ? '\n' : ' ')).join('');
      // No document text is persisted; oversized entries bypass the bounded cache.
      textCache.set(number, text); return text;
    })();
    pendingText.set(number, task);
    try { return await task; }
    finally { pendingText.delete(number); }
  };
  const setControls = () => {
    const first = spreadStart(page, spread());
    $('[data-prev]').disabled = first === 1;
    $('[data-next]').disabled = visiblePages.at(-1) >= pdf.numPages;
    // A completed older render must not replace a newer, not-yet-submitted edit.
    if (!pageInputEdited) $('[data-page]').value = page;
    $('[data-page]').max = pdf.numPages;
    $('[data-count]').textContent = `of ${pdf.numPages}`;
    $('[data-layout]').textContent = spread() ? 'Single page' : 'Two-page view';
    $('[data-layout]').disabled = !wide();
    $('[data-layout]').title = wide() ? 'Change the page layout' : 'Two-page view is available on wider screens';
    $('[data-zoom]').textContent = `${Math.round(zoom * 100)}%`;
    $('[data-zoom-out]').disabled = zoom <= .75;
    $('[data-zoom-in]').disabled = zoom >= 2.5;
  };
  // Resizing to zero releases pixels without waiting for detached DOM garbage collection.
  const releaseCanvas = canvas => { canvas.width = 0; canvas.height = 0; };
  async function draw(requested, turn = false, updateHash = true) {
    if (!pdf || disposed) return;
    const ownedCanvases = new Set();
    const oldPage = page, token = ++renderEpoch;
    renders.forEach(task => { try { task.cancel(); } catch (_) {} }); renders = []; stopAnimations();
    const targetPage = Math.max(1, Math.min(pdf.numPages, requested));
    const selected = pagesFor(targetPage, pdf.numPages, spread());
    say(`Loading page${selected.length > 1 ? 's' : ''} ${selected.join('–')}…`);
    stage.setAttribute('aria-busy', 'true');
    try {
      const docs = await Promise.all(selected.map(n => pdf.getPage(n)));
      if (token !== renderEpoch) return;
      const padding = parseFloat(getComputedStyle(stage).paddingLeft) + parseFloat(getComputedStyle(stage).paddingRight);
      const width = Math.max(140, stage.clientWidth - padding - 3);
      const baseline = docs.map(p => p.getViewport({scale: 1}));
      const totalWidth = baseline.reduce((sum, v) => sum + v.width, 0);
      const fit = Math.min(width / totalWidth, 720 / Math.max(...baseline.map(v => v.height)));
      const staged = [];
      for (let i = 0; i < docs.length; i++) {
        if (token !== renderEpoch) return;
        const viewport = docs[i].getViewport({scale: fit * zoom});
        // Cap backing pixels and DPR to stop large or malicious pages exhausting memory.
        const dpr = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(8000000 / (viewport.width * viewport.height)));
        const canvas = document.createElement('canvas');
        ownedCanvases.add(canvas);
        canvas.width = Math.max(1, Math.ceil(viewport.width * dpr)); canvas.height = Math.max(1, Math.ceil(viewport.height * dpr));
        canvas.style.width = `${viewport.width}px`; canvas.style.height = `${viewport.height}px`;
        canvas.setAttribute('aria-hidden','true');
        const task = docs[i].render({canvasContext: canvas.getContext('2d', {alpha: false}), viewport, transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null, annotationMode: pdfjs.AnnotationMode.DISABLE});
        renders.push(task);
        // Never clear a canvas until its render promise has settled, even on cancellation.
        try { await task.promise; } finally { renders = renders.filter(active => active !== task); }
        staged.push(canvas);
      }
      if (token !== renderEpoch) return;
      if (turn && visiblePages.length && oldPage !== targetPage) {
        const leaf = targetPage > oldPage ? papers[visiblePages.length - 1] : papers[0];
        await animate(leaf, [{transform: 'rotateY(0deg)', filter:'brightness(1)'}, {transform: `rotateY(${targetPage > oldPage ? -82 : 82}deg)`, filter:'brightness(.65)'}], {duration:190,easing:'ease-in',fill:'none'});
      }
      if (token !== renderEpoch) return;
      papers.forEach((paper, i) => {
        paper.hidden = !staged[i];
        const previous = paper.querySelector('canvas');
        if (staged[i]) { previous.replaceWith(staged[i]); ownedCanvases.delete(staged[i]); paper.querySelector('[data-page-label]').textContent = selected[i]; }
        releaseCanvas(previous);
      });
      page = targetPage;
      visiblePages = selected;
      const pendingText = document.createElement('p');
      pendingText.textContent = `Loading text for page${selected.length > 1 ? 's' : ''} ${selected.join('–')}…`;
      $('[data-page-text]').replaceChildren(pendingText);
      book.dataset.spread = String(selected.length > 1);
      setControls(); stage.scrollLeft = 0;
      if (updateHash) history.replaceState(null, '', `#page=${page}`);
      say(`Page${selected.length > 1 ? 's' : ''} ${selected.join('–')} of ${pdf.numPages}`);
      stage.removeAttribute('aria-busy');
      if (turn && oldPage !== page) {
        const leaf = page > oldPage ? papers[0] : papers[selected.length - 1];
        await animate(leaf, [{transform:`rotateY(${page > oldPage ? 82 : -82}deg)`,filter:'brightness(.7)'},{transform:'rotateY(0deg)',filter:'brightness(1)'}], {duration:210,easing:'ease-out',fill:'none'});
      }
      try {
        const extracted = await Promise.all(selected.map(textFor));
        if (token !== renderEpoch) return;
        const fragment = document.createDocumentFragment();
        selected.forEach((n, i) => { const heading = document.createElement('h2'); heading.textContent = `Page ${n}`; const text = document.createElement('p'); text.textContent = extracted[i].trim() || 'This page has no extractable text. It may be a scan or illustration. Use the PDF download with an OCR-capable reader if needed.'; fragment.append(heading, text); });
        $('[data-page-text]').replaceChildren(fragment);
      } catch (_) {
        if (token !== renderEpoch) return;
        const unavailable = document.createElement('p');
        unavailable.textContent = `Text could not be extracted from page${selected.length > 1 ? 's' : ''} ${selected.join('–')}. The displayed pages are still available, or you can download the original PDF.`;
        $('[data-page-text]').replaceChildren(unavailable);
      }
    } catch (error) {
      if (token !== renderEpoch || error?.name === 'RenderingCancelledException') return;
      setControls();
      say(`Page ${targetPage} could not be rendered.${visiblePages.length ? ` Still showing ${visiblePages.join('–')}.` : ''} Try another page, or download the original PDF.`);
      stage.removeAttribute('aria-busy');
    } finally {
      // Uncommitted canvases include earlier pages when a later page fails or supersedes.
      ownedCanvases.forEach(releaseCanvas);
    }
  }
  const navigate = (requested, turn = true, updateHash = true) => { pageInputEdited = false; return draw(requested, turn, updateHash); };
  const turn = direction => navigate(nextPage(page, pdf.numPages, spread(), direction));
  $('[data-prev]').addEventListener('click', () => { if(pdf)turn(-1); });
  $('[data-next]').addEventListener('click', () => { if(pdf)turn(1); });
  $('[data-page-form]').addEventListener('submit', event => { event.preventDefault(); const n=Number($('[data-page]').value); if(pdf && Number.isSafeInteger(n) && n>=1 && n<=pdf.numPages) { pageInputEdited=false;draw(n,true); } });
  $('[data-layout]').addEventListener('click', () => { preferredSpread=!preferredSpread;draw(page); });
  $('[data-zoom-out]').addEventListener('click', () => {zoom=Math.max(.75,zoom-.25);draw(page);});
  $('[data-zoom-in]').addEventListener('click', () => {zoom=Math.min(2.5,zoom+.25);draw(page);});
  $('[data-fit]').addEventListener('click', () => {zoom=1;draw(page);});
  root.addEventListener('keydown', event => {
    if(!pdf || /^(INPUT|TEXTAREA|SELECT|BUTTON|A)$/.test(event.target.tagName) || event.ctrlKey || event.metaKey || event.altKey) return;
    if(event.key==='ArrowLeft'||event.key==='ArrowRight'){event.preventDefault();turn(event.key==='ArrowLeft'?-1:1);}
    if(event.key==='Home'||event.key==='End'){event.preventDefault();navigate(event.key==='Home'?1:pdf.numPages);}
  });
  let touch=null;
  stage.addEventListener('pointerdown', event => {
    // A second pointer cancels the gesture; only the initiating primary touch
    // may complete a page swipe. Never reinterpret a pinch as a page turn.
    touch=pdf&&event.pointerType==='touch'&&event.isPrimary&&zoom===1
      ? {id:event.pointerId,x:event.clientX,y:event.clientY} : null;
  });
  stage.addEventListener('pointerup', event => {
    const start=touch;touch=null;
    if(!start||!pdf||zoom!==1||event.pointerType!=='touch'||!event.isPrimary||event.pointerId!==start.id)return;
    const dx=event.clientX-start.x,dy=event.clientY-start.y;
    if(Math.abs(dx)>60&&Math.abs(dx)>Math.abs(dy)*1.5)turn(dx>0?-1:1);
  });
  window.addEventListener('pointerdown',event=>{if(event.pointerType==='touch'&&!event.isPrimary)touch=null;},true);
  stage.addEventListener('pointercancel',()=>{touch=null;});
  stage.addEventListener('lostpointercapture',()=>{touch=null;});
  window.addEventListener('blur',()=>{touch=null;});
  window.addEventListener('hashchange',()=>{if(pdf)navigate(queryPage(location.hash,pdf.numPages),false,false);});
  const observer=new ResizeObserver(()=>{const width=stage.clientWidth;if(Math.abs(width-lastWidth)<2)return;lastWidth=width;clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>draw(page),120);});observer.observe(stage);
  const full=$('[data-fullscreen]');
  if(document.fullscreenEnabled&&root.requestFullscreen){full.hidden=false;full.addEventListener('click',async()=>{try{if(document.fullscreenElement===root)await document.exitFullscreen();else await root.requestFullscreen();}catch(_){say('Full screen is unavailable in this browser.');}});document.addEventListener('fullscreenchange',()=>{full.textContent=document.fullscreenElement===root?'Exit full screen':'Full screen';draw(page);});}
  $('[data-search-cancel]').addEventListener('click',()=>{searchEpoch++;$('[data-search-cancel]').hidden=true;$('[data-search-status]').textContent='Search stopped. Results found so far are below.';});
  $('[data-search-form]').addEventListener('submit',async event=>{
    event.preventDefault();if(!pdf)return;
    const term=$('[data-search-input]').value.trim().toLocaleLowerCase(), token=++searchEpoch;
    $('[data-search-results]').replaceChildren();
    if(!term){$('[data-search-cancel]').hidden=true;$('[data-search-status]').textContent='Enter a word or part number.';return;}
    $('[data-search-cancel]').hidden=false;let hits=0,hasText=false;
    try{
      for(let n=1;n<=pdf.numPages;n++){
        if(token!==searchEpoch)return;
        // Cached/quick text resolves in microtasks; let Stop, replacement and teardown run.
        if(n>1&&(n-1)%8===0){await new Promise(resolve=>setTimeout(resolve,0));if(token!==searchEpoch)return;}
        $('[data-search-status]').textContent=`Searching page ${n} of ${pdf.numPages}…`;
        const text=await textFor(n);if(token!==searchEpoch)return;hasText=hasText||!!text.trim();
        const pos=text.toLocaleLowerCase().indexOf(term);
        if(pos>=0){hits++;const li=document.createElement('li'),button=document.createElement('button'),strong=document.createElement('strong'),excerpt=document.createElement('span');button.type='button';strong.textContent=`Page ${n}`;excerpt.textContent=(pos>45?'…':'')+text.slice(Math.max(0,pos-45),pos+term.length+90).replace(/\s+/g,' ');button.append(strong,excerpt);button.addEventListener('click',()=>{navigate(n);stage.focus();});li.append(button);$('[data-search-results]').append(li);}
        if(hits>=50){$('[data-search-status]').textContent='First 50 matching pages shown. Narrow your search for more specific results.';break;}
        if(n===pdf.numPages)$('[data-search-status]').textContent=hits?`${hits} matching page${hits===1?'':'s'}.`:hasText?'No matching document text found.':'This PDF has no extractable text. Scanned manuals need OCR before text search can find their contents.';
      }
    }catch(_){if(token===searchEpoch)$('[data-search-status]').textContent='Search could not finish. You can still turn pages or download the PDF.';}
    finally{if(token===searchEpoch)$('[data-search-cancel]').hidden=true;}
  });
  // Register before awaiting the document: a large pending load is still owned
  // by this view and must be released on permanent departure.
  window.addEventListener('pagehide',event=>{touch=null;if(event.persisted||disposed)return;disposed=true;renderEpoch++;searchEpoch++;clearTimeout(resizeTimer);renders.forEach(task=>{try{task.cancel();}catch(_){}});papers.forEach(p=>releaseCanvas(p.querySelector('canvas')));observer.disconnect();stopAnimations();textCache.close();pendingText.clear();loadingTask?.destroy();});
  try{
    const url=new URL(root.dataset.pdfUrl,location.href);
    if(url.origin!==location.origin)throw new Error('Only this site’s manual files can be read.');
    const task=loadingTask=pdfjs.getDocument({url:url.href,cMapUrl:`${vendor}cmaps/`,cMapPacked:true,standardFontDataUrl:`${vendor}standard_fonts/`,wasmUrl:`${vendor}wasm/`,isEvalSupported:false,enableXfa:false,disableAutoFetch:true,disableStream:true,disableRange:true,disableWorkerRendering:true,maxImageSize:16000000,canvasMaxAreaInBytes:32000000});
    task.onPassword=()=>{if(disposed)return;loadError='This PDF requires a password. Download the original or ask for an unlocked, authorized copy.';task.destroy();say(loadError);};
    const loaded=await task.promise;
    if(disposed)return;
    pdf=loaded;
    if(pdf.numPages>5000){await loadingTask.destroy();pdf=null;throw new Error('This PDF exceeds the 5,000-page reader limit.');}
    controls.forEach(selector=>{$(selector).disabled=false;});
    await draw(queryPage(location.hash,pdf.numPages),false,false);
  }catch(error){if(disposed)return;say(loadError || 'The book reader could not open this PDF. Download the original to continue, or try again later.');}
}
