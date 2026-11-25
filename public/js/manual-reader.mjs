import * as pdfjs from '../vendor/pdfjs-6.5.438/pdf.mjs';

// This is an original reader, not a port of a proprietary flipbook library.
// No annotation layer, embedded actions, form widgets, or PDF JavaScript runs here.
// Use explicit ranges for large files even when a proxy removes Content-Length.
const manualRangeChunkSize = 1024 * 1024;
export async function openManualRange(url, controller, onFailure, fetchRange = fetch) {
  const {signal} = controller;
  const read = async (begin, end, original) => {
    const response = await fetchRange(url, {headers: {Range: `bytes=${begin}-${end - 1}`}, credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal});
    const match = /^bytes (\d+)-(\d+)\/(\d+)$/.exec(response.headers.get('Content-Range') || '');
    const encoding = response.headers.get('Content-Encoding');
    const etag = response.headers.get('ETag');
    const length = match ? Number(match[3]) : 0;
    const limit = Math.min(end, length);
    if (response.status !== 206 || (encoding && encoding !== 'identity') ||
        response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase() !== 'application/pdf' ||
        !match || !Number.isSafeInteger(length) || length <= begin || length > 400 * 1024 * 1024 ||
        Number(match[1]) !== begin || Number(match[2]) !== limit - 1 ||
        !/^"[a-f0-9]{64}"$/.test(etag || '') ||
        (original && (original.length !== length || original.etag !== etag)) || !response.body) {
      response.body?.cancel().catch(() => {});
      throw new Error('The server did not return the requested PDF bytes.');
    }
    const reader = response.body.getReader();
    const bytes = new Uint8Array(limit - begin);
    let received = 0;
    try {
      for (;;) {
        const {value, done} = await reader.read();
        if (done) break;
        if (value.byteLength > bytes.byteLength - received) throw new Error('The PDF byte range is too long.');
        bytes.set(value, received);
        received += value.byteLength;
      }
      if (received !== bytes.byteLength) throw new Error('The PDF byte range was incomplete.');
    } catch (error) {
      reader.cancel().catch(() => {});
      throw error;
    } finally {
      reader.releaseLock();
    }
    return {length, etag, bytes};
  };
  const initial = await read(0, manualRangeChunkSize);
  signal.throwIfAborted();
  // Do not retain or reuse the initial buffer after handing ownership to PDF.js.
  const original = {length: initial.length, etag: initial.etag};
  return new class extends pdfjs.PDFDataRangeTransport {
    constructor() { super(initial.length, initial.bytes, true); }
    requestDataRange(begin, end) {
      read(begin, end, original).then(({bytes}) => {
        if (!signal.aborted) this.onDataRange(begin, bytes);
      }).catch(error => {
        if (!signal.aborted) { this.abort(); onFailure(error); }
      });
    }
    abort() { controller.abort(); }
  };
}

const vendor = new URL('../vendor/pdfjs-6.5.438/', import.meta.url).href;
pdfjs.GlobalWorkerOptions.workerSrc = `${vendor}pdf.worker.mjs`;
// Retain a bounded navigation index, never PDF links, actions or raw outline trees.
export function manualOutline(items) {
  const entries = [], stack = [{items: Array.isArray(items) ? items : [], index: 0, depth: 0}];
  let truncated = false;
  while (stack.length) {
    const frame = stack.at(-1);
    if (frame.index >= frame.items.length) { stack.pop(); continue; }
    if (entries.length >= 500) { truncated = true; break; }
    const item = frame.items[frame.index++];
    if (!item || typeof item !== 'object') { entries.push({title: 'Untitled section', depth: frame.depth, dest: null}); continue; }
    const rawTitle = typeof item.title === 'string' ? item.title.trim() : '';
    let title = rawTitle ? rawTitle.slice(0, 200) : 'Untitled section';
    if (rawTitle.length > 200 && /[\uD800-\uDBFF]$/.test(title)) title = title.slice(0, -1);
    if (rawTitle.length > 200) truncated = true;
    let dest = null;
    if (!item.url && !item.unsafeUrl && !item.action) {
      if (typeof item.dest === 'string' && item.dest.length > 0 && item.dest.length <= 1024) dest = item.dest;
      else if (Array.isArray(item.dest)) {
        const target = item.dest[0];
        if (Number.isSafeInteger(target) && target >= 0) dest = [target];
        else if (target && Number.isSafeInteger(target.num) && target.num >= 0 && Number.isSafeInteger(target.gen) && target.gen >= 0) dest = [{num: target.num, gen: target.gen}];
      }
    }
    entries.push({title, depth: frame.depth, dest});
    if (Array.isArray(item.items) && item.items.length) {
      if (frame.depth >= 11) truncated = true;
      else stack.push({items: item.items, index: 0, depth: frame.depth + 1});
    }
  }
  return {entries, truncated};
}
export async function outlinePage(pdf, dest) {
  if (typeof dest === 'string') dest = await pdf.getDestination(dest);
  if (!Array.isArray(dest) || !dest.length) throw new Error('No internal destination');
  const target = dest[0];
  let index;
  if (Number.isSafeInteger(target) && target >= 0) index = target;
  else if (target && Number.isSafeInteger(target.num) && target.num >= 0 && Number.isSafeInteger(target.gen) && target.gen >= 0) index = await pdf.getPageIndex({num: target.num, gen: target.gen});
  if (!Number.isSafeInteger(index) || index < 0 || index >= pdf.numPages) throw new Error('Invalid internal page');
  return index + 1;
}

// Keep render resources for visible pages only. PDF.js cleanup defers internally
// while its own render work is active; our leases also cover overlapping draws.
export function createPageResourceTracker() {
  const active = new Map();
  let visible = new Set(), closed = false;
  const collect = proxy => {
    if (closed || visible.has(proxy) || active.has(proxy)) return;
    try { proxy.cleanup?.(); } catch (_) { /* Resource cleanup must not hide a render result. */ }
  };
  return {
    acquire(proxy) { if (!closed) active.set(proxy, (active.get(proxy) || 0) + 1); },
    release(proxy) {
      if (closed || !active.has(proxy)) return;
      const remaining = active.get(proxy) - 1;
      if (remaining) active.set(proxy, remaining); else active.delete(proxy);
      collect(proxy);
    },
    setVisible(pages) {
      if (closed) return;
      const previous = visible;
      visible = new Set(pages);
      previous.forEach(collect);
    },
    close() { closed = true; active.clear(); visible.clear(); }
  };
}

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

// A developable fold starts at the free bottom corner. Points on the spine
// stay on the book plane; a bounded cylindrical band rounds the moving crease.
export function cornerFold(u, v, progress, width, height) {
  const p = Math.max(0, Math.min(1, progress));
  if (p === 0) return {x:u, y:v, shade:0, back:false};
  if (p === 1) return {x:-u, y:v, shade:0, back:true};
  const {nx,ny,mx,my,radius}=cornerFoldFrame(p,width,height);
  const d = (u - mx) * nx + (v - my) * ny;
  if (d <= 0) return {x:u, y:v, shade:0, back:false};
  const angle = Math.min(Math.PI, d / radius);
  const along = d < Math.PI * radius ? radius * Math.sin(angle) : Math.PI * radius - d;
  const lift = radius * (1 - Math.cos(angle));
  return {x:u + (along - d) * nx, y:v + (along - d) * ny - .5 * lift,
    shade:.12 + .3 * Math.sin(angle), back:angle > Math.PI / 2};
}

function cornerFoldFrame(p,width,height) {
  const dx=2*width*p,dy=.22*Math.min(width,height)*Math.sin(Math.PI*p),length=Math.hypot(dx,dy);
  return {nx:dx/length,ny:dy/length,mx:width-dx/2,my:height-dy/2,
    radius:Math.min(.06*width*Math.sin(Math.PI*p),length/(4*Math.PI))};
}

const root = document.querySelector('[data-manual-reader]');
if (root) initialize(root);
async function initialize(root) {
  const $ = selector => root.querySelector(selector);
  const status = $('[data-status]'), stage = $('[data-stage]'), book = $('[data-book]');
  const papers = [...root.querySelectorAll('[data-paper]')];
  const controls = ['[data-prev]', '[data-next]', '[data-page]', '[data-go]', '[data-layout]', '[data-zoom-in]', '[data-zoom-out]', '[data-fit]', '[data-search-submit]'];
  let rangeController = null, loadingTask = null, loadError = null, disposed = false, loadPending = true;
  const loadCancel = $('[data-load-cancel]');
  let progressMessage = '';
  const finishLoading = () => {
    loadPending = false;
    const ownedFocus = !disposed && loadCancel && document.activeElement === loadCancel;
    if (loadCancel) loadCancel.hidden = true;
    if (ownedFocus) status.focus?.();
  };
  const destroyLoadingTask = () => {
    try { return Promise.resolve(loadingTask?.destroy()).catch(()=>{}); }
    catch (_) { return Promise.resolve(); }
  };
  let pdf = null, page = 1, zoom = 1, preferredSpread = true, renderEpoch = 0, searchEpoch = 0;
  const pageResources = createPageResourceTracker();
  let renders = [], animations = [], manualMotionOff = false, activeCurl = null;
  let visiblePages = [], resizeTimer, lastWidth = stage.clientWidth;
  let pendingDraw = null;
  let pageInputEdited = false;
  $('[data-page]').addEventListener('input', () => { pageInputEdited = true; });
  const textCache = createTextCache();
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const wide = () => stage.clientWidth >= 740;
  const spread = () => preferredSpread && wide();
  const say = message => { status.textContent = message; };
  const stopAnimations = () => { activeCurl?.close(); animations.forEach(a => a.cancel()); animations = []; papers.forEach(p => { p.style.transform = ''; }); };
  const updateMotion = () => {
    if (disposed) return;
    const off = manualMotionOff || reducedMotion.matches;
    if (off) stopAnimations();
    const button = $('[data-motion]');
    button.textContent = reducedMotion.matches ? 'Page animation off (system preference)' : off ? 'Page animation off' : 'Page animation on';
    button.setAttribute('aria-pressed', String(off));
    // Keep a focused button in place while the OS makes its action unavailable.
    button.setAttribute('aria-disabled', String(reducedMotion.matches));
  };
  updateMotion();
  reducedMotion.addEventListener('change', updateMotion);
  $('[data-motion]').addEventListener('click', () => {
    if (disposed || reducedMotion.matches) return;
    manualMotionOff = !manualMotionOff; updateMotion();
  });
  const animate = async (element, frames, options, paint = null) => {
    if (manualMotionOff || reducedMotion.matches || !element.animate) return;
    const animation = element.animate(frames, options); animations.push(animation);
    let frame;
    const update = () => {
      if (!paint || !animations.includes(animation)) return;
      try { paint(animation.effect.getComputedTiming().progress ?? 0); }
      catch (_) { activeCurl?.close(); paint = null; animation.cancel(); return; }
      frame = window.requestAnimationFrame(update);
    };
    try { if (paint) update(); await animation.finished; if (paint) paint(1); }
    catch (_) { /* Preference changes, newer navigation or teardown may cancel motion. */ }
    finally { if (frame !== undefined) window.cancelAnimationFrame(frame); animations = animations.filter(a => a !== animation); }
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
  const textContainer = $('[data-page-text]');
  // Embedded readers without a disclosure retain their always-visible text.
  const textPanel = textContainer.closest?.('details') || null;
  const textIsOpen = () => !textPanel || textPanel.open;
  let textEpoch = 0;
  const textPlaceholder = selected => {
    const message = document.createElement('p');
    message.textContent = textIsOpen()
      ? `Loading text for page${selected.length > 1 ? 's' : ''} ${selected.join('–')}…`
      : 'Open Read text to extract text from the current page. Document search remains available.';
    textContainer.replaceChildren(message);
  };
  const displayText = async (selected, owner) => {
    const ownsPages = () => owner === renderEpoch && selected.length === visiblePages.length && selected.every((n, i) => n === visiblePages[i]);
    // A disclosure can open while the next page is rendering. Its text must
    // still belong to the pages actually displayed when extraction settles.
    if (disposed || !selected.length || !ownsPages()) return;
    const request = ++textEpoch;
    textPlaceholder(selected);
    if (!textIsOpen()) return;
    const current = () => !disposed && textIsOpen() && ownsPages() && request === textEpoch;
    try {
      const extracted = await Promise.all(selected.map(textFor));
      if (!current()) return;
      const fragment = document.createDocumentFragment();
      selected.forEach((n, i) => { const heading = document.createElement('h2'); heading.textContent = `Page ${n}`; const text = document.createElement('p'); text.textContent = extracted[i].trim() || 'This page has no extractable text. It may be a scan or illustration. Use the PDF download with an OCR-capable reader if needed.'; fragment.append(heading, text); });
      textContainer.replaceChildren(fragment);
    } catch (_) {
      if (!current()) return;
      const unavailable = document.createElement('p');
      unavailable.textContent = `Text could not be extracted from page${selected.length > 1 ? 's' : ''} ${selected.join('–')}. The displayed pages are still available, or you can download the original PDF.`;
      textContainer.replaceChildren(unavailable);
    }
  };
  textPanel?.addEventListener('toggle', () => {
    if (disposed) return;
    if (!textIsOpen()) { textEpoch++; textPlaceholder(visiblePages); return; }
    if (pdf && visiblePages.length) displayText([...visiblePages], renderEpoch);
  });
  const outlinePanel = $('[data-outline]');
  const outlineStatus = $('[data-outline-status]'), outlineList = $('[data-outline-list]');
  let outlineStarted = false, outlineEpoch = 0, outlinePending = false, outlineSummary = '';
  const cancelOutlineSelection = () => {
    outlineEpoch++;
    if (outlinePending) { outlinePending = false; outlineStatus.textContent = outlineSummary; }
  };
  const loadOutline = async () => {
    if (!outlinePanel?.open || !pdf || disposed || outlineStarted) return;
    outlineStarted = true;
    outlineStatus.textContent = 'Loading table of contents…';
    try {
      const result = manualOutline(await pdf.getOutline());
      if (disposed) return;
      const fragment = document.createDocumentFragment();
      result.entries.forEach(entry => {
        const li = document.createElement('li'), button = document.createElement('button');
        button.type = 'button'; button.textContent = `${entry.depth ? '↳ '.repeat(entry.depth) : ''}${entry.title}`;
        button.disabled = !entry.dest;
        if (!entry.dest) button.title = 'No supported internal page destination';
        button.addEventListener('click', async () => {
          if (disposed || !entry.dest || !outlinePanel.open) return;
          const token = ++outlineEpoch, owner = renderEpoch;
          outlinePending = true; outlineStatus.textContent = 'Finding section…';
          try {
            const selected = await outlinePage(pdf, entry.dest);
            if (disposed || !outlinePanel.open || token !== outlineEpoch || owner !== renderEpoch) return;
            outlinePending = false; outlineStatus.textContent = `Opening page ${selected}.`;
            navigate(selected); stage.focus();
          } catch (_) {
            if (!disposed && outlinePanel.open && token === outlineEpoch && owner === renderEpoch) { outlinePending = false; outlineStatus.textContent = 'This section’s page could not be found. Use page numbers or document search instead.'; }
          }
        });
        li.append(button); fragment.append(li);
      });
      outlineList.replaceChildren(fragment);
      outlineStatus.textContent = outlineSummary = !result.entries.length ? 'This PDF has no embedded table of contents. Use page numbers or document search.' : result.truncated ? 'Showing up to 500 entries, 12 levels and 200 characters per heading. Use document search for omitted content.' : `${result.entries.length} section${result.entries.length === 1 ? '' : 's'}. Only internal page destinations are available.`;
    } catch (_) {
      if (!disposed) outlineStatus.textContent = 'The table of contents could not be loaded. Page navigation and document search remain available.';
    }
  };
  outlinePanel?.addEventListener('toggle', () => { if (!outlinePanel.open) cancelOutlineSelection(); else loadOutline(); });
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
  // One bounded compositor reads the already rendered PDF canvases. It does
  // not render another PDF page or retain a second document/texture cache.
  function startCurl(staged, forward) {
    if (manualMotionOff || reducedMotion.matches || !window.requestAnimationFrame || !book.getBoundingClientRect || !papers[0].animate) return null;
    const old = papers.filter(p => !p.hidden).map(p => p.querySelector('canvas'));
    if (!old.length || !old[0].getContext('2d')?.drawImage) return null;
    const surface = document.createElement('canvas'), ctx = surface.getContext('2d');
    if (!ctx) return null;
    const box = stage.getBoundingClientRect(), width = stage.clientWidth, height = stage.clientHeight;
    const oldRects = old.map(canvas => canvas.getBoundingClientRect());
    const nextSizes = staged.map(canvas => ({width:parseFloat(canvas.style.width), height:parseFloat(canvas.style.height)}));
    const single = old.length === 1 && staged.length === 1;
    const dir = single || forward ? 1 : -1;
    const frontIndex = forward ? old.length - 1 : 0, backIndex = forward ? 0 : staged.length - 1;
    const front = single && !forward ? staged[0] : old[frontIndex];
    const back = single && !forward ? old[0] : staged[backIndex];
    const frontRect = oldRects[frontIndex], {width:endWidth,height:endHeight} = nextSizes[backIndex];
    const startSpine = (dir === 1 ? frontRect.left : frontRect.right) - box.left - stage.clientLeft;
    const padding = getComputedStyle(stage);
    const leftPadding = parseFloat(padding.paddingLeft), rightPadding = parseFloat(padding.paddingRight);
    const paperWidths = nextSizes.map((size, index) => Math.max(size.width, parseFloat(getComputedStyle(papers[index]).minWidth) || 0));
    const bookWidth = paperWidths.reduce((sum, size) => sum + size, 0);
    const endLeft = leftPadding + Math.max(0, (width - leftPadding - rightPadding - bookWidth) / 2);
    // A forward curl lands to the left of its hinge, including on the final singleton.
    const endSpine = endLeft + (staged.length === 2 ? paperWidths[0] : forward && !single ? endWidth : 0);
    const top = frontRect.top - box.top - stage.clientTop;
    const scale = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(4_000_000 / (width * height)));
    surface.width = Math.max(1, Math.floor(width * scale)); surface.height = Math.max(1, Math.floor(height * scale));
    Object.assign(surface.style, {position:'absolute', pointerEvents:'none', zIndex:'2', width:`${width}px`, height:`${height}px`});
    surface.setAttribute('aria-hidden','true'); surface.dataset.cornerCurl = 'true';
    const position = stage.style.position, visibility = book.style.visibility;
    stage.style.position = 'relative'; book.style.visibility = 'hidden'; stage.append(surface);
    let closed = false;
    const owner = {
      close() {
        if (closed) return; closed = true;
        surface.remove(); releaseCanvas(surface);
        if (activeCurl === owner) { activeCurl = null; book.style.visibility = visibility; stage.style.position = position; }
      },
      paint(progress) {
        if (closed || activeCurl !== owner) return;
        const p = Math.max(0, Math.min(1, progress)), fold = single && !forward ? 1-p : p;
        const w = frontRect.width + (endWidth-frontRect.width)*p, h = frontRect.height + (endHeight-frontRect.height)*p;
        const spine = startSpine + (endSpine-startSpine)*p;
        surface.style.left = `${stage.scrollLeft}px`; surface.style.top = `${stage.scrollTop}px`;
        ctx.setTransform(surface.width/width,0,0,surface.height/height,0,0); ctx.clearRect(0,0,width,height);
        ctx.save();
        if (single) { ctx.beginPath(); ctx.rect(spine,top,w,h); ctx.clip(); }
        const pageImage = (image,x,drawWidth=w,drawHeight=h) => {
          if (image?.width && image.height) ctx.drawImage(image,x,top,drawWidth,drawHeight);
        };
        if (single) pageImage(forward ? staged[0] : old[0],spine);
        else {
          const left = forward ? oldRects[0] : nextSizes[0];
          const right = forward ? nextSizes[1] : oldRects[1];
          if (forward ? old.length === 2 && p < 1 : staged.length === 2) {
            pageImage(forward ? old[0] : staged[0],spine-left.width,left.width,left.height);
          }
          if (forward ? staged.length === 2 : old.length === 2 && p < 1) {
            pageImage(forward ? staged[1] : old[1],spine,right.width,right.height);
          }
        }
        if (fold === 0 || fold === 1) {
          pageImage(fold === 0 ? front : back, (fold === 0 ? dir === 1 : dir === -1) ? spine : spine-w);
        } else {
          const profile=cornerFoldFrame(fold,w,h),{nx,ny,mx,my,radius}=profile;
          const distance=q=>(q.x-mx)*nx+(q.y-my)*ny;
          const rectangle=[{x:0,y:0},{x:w,y:0},{x:w,y:h},{x:0,y:h}];
          // Clip in paper coordinates. Flat regions are one affine image each;
          // only the cylindrical band needs strips, with a shared crease edge.
          const cut=(polygon,limit,above)=>{
            const result=[];
            for(let i=0;i<polygon.length;i++){
              const a=polygon[i],b=polygon[(i+1)%polygon.length],da=distance(a)-limit,db=distance(b)-limit;
              const inside=above?da>=0:da<=0,next=above?db>=0:db<=0;
              if(inside)result.push(a);
              if(inside!==next){const t=da/(da-db);result.push({x:a.x+(b.x-a.x)*t,y:a.y+(b.y-a.y)*t});}
            }
            return result;
          };
          const mapped=q=>{const value=cornerFold(q.x,q.y,fold,w,h);return {x:spine+dir*value.x,y:top+value.y};};
          const path=polygon=>{ctx.beginPath();polygon.forEach((q,i)=>i?ctx.lineTo(q.x,q.y):ctx.moveTo(q.x,q.y));ctx.closePath();};
          const shape=polygon=>{
            const result=[];
            for(let i=0;i<polygon.length;i++){
              const a=polygon[i],b=polygon[(i+1)%polygon.length];
              for(let k=0;k<32;k++)result.push(mapped({x:a.x+(b.x-a.x)*k/32,y:a.y+(b.y-a.y)*k/32}));
            }
            return result;
          };
          const extent=rectangle.map(distance),minimum=Math.min(...extent),maximum=Math.max(...extent),arc=Math.PI*radius;
          // The two faces are painted separately, including their continuous
          // gradient. Hidden front shading cannot darken the visible reverse.
          for(const reverse of [false,true]){
            const face=cut(rectangle,arc/2,reverse);
            if(face.length<3)continue;
            const outline=shape(face),image=reverse?back:front;
            ctx.save();path(outline);ctx.clip();
            const bounds=reverse?[arc/2,...Array.from({length:16},(_,i)=>arc*(17+i)/32),Math.max(arc,maximum)]:[Math.min(0,minimum),...Array.from({length:17},(_,i)=>arc*i/32)];
            for(let i=0;i<bounds.length-1;i++){
              const low=bounds[i],high=bounds[i+1];
              if(high-low<1e-7)continue;
              const polygon=cut(cut(rectangle,low,true),high,false);
              if(polygon.length<3)continue;
              const target=polygon.map(mapped),center=target.reduce((sum,q)=>({x:sum.x+q.x/target.length,y:sum.y+q.y/target.length}),{x:0,y:0});
              const area=target.reduce((sum,q,j)=>sum+q.x*target[(j+1)%target.length].y-q.y*target[(j+1)%target.length].x,0);
              if(Math.abs(area)<.01)continue;
              const expanded=target.map(q=>{const dx=q.x-center.x,dy=q.y-center.y,length=Math.hypot(dx,dy);return length?{x:q.x+2*dx/length,y:q.y+2*dy/length}:q;});
              // Approximate each curved distance interval with an affine image;
              // the flat regions are exact and no triangular tiles are used.
              const a={x:mx+nx*low,y:my+ny*low},b={x:mx+nx*high,y:my+ny*high},c={x:a.x-ny,y:a.y+nx};
              const mirror=reverse?dir===1:dir===-1;
              const source=q=>({x:(mirror?1-q.x/w:q.x/w)*image.width,y:q.y/h*image.height});
              const [A,B,C]=[a,b,c].map(mapped),[sa,sb,sc]=[a,b,c].map(source);
              const det=(sb.x-sa.x)*(sc.y-sa.y)-(sc.x-sa.x)*(sb.y-sa.y);
              const xx=((B.x-A.x)*(sc.y-sa.y)-(C.x-A.x)*(sb.y-sa.y))/det;
              const xy=((C.x-A.x)*(sb.x-sa.x)-(B.x-A.x)*(sc.x-sa.x))/det;
              const yx=((B.y-A.y)*(sc.y-sa.y)-(C.y-A.y)*(sb.y-sa.y))/det;
              const yy=((C.y-A.y)*(sb.x-sa.x)-(B.y-A.y)*(sc.x-sa.x))/det;
              ctx.save();path(expanded);ctx.clip();
              ctx.transform(xx,yx,xy,yy,A.x-xx*sa.x-xy*sa.y,A.y-yx*sa.x-yy*sa.y);ctx.drawImage(image,0,0);ctx.restore();
            }
            const origin={x:spine+dir*mx,y:top+my},normal={x:dir*nx,y:ny};
            const low=reverse?-radius*ny:0,high=radius*(1-.5*ny);
            const gradient=ctx.createLinearGradient(origin.x+normal.x*low,origin.y+normal.y*low,origin.x+normal.x*high,origin.y+normal.y*high);
            gradient.addColorStop(0,`rgba(18,16,12,${reverse?.12*Math.sin(Math.PI*fold):0})`);
            gradient.addColorStop(1,'rgba(18,16,12,.42)');ctx.fillStyle=gradient;path(outline);ctx.fill();ctx.restore();
          }
        }
        const shadow=ctx.createLinearGradient(spine-10,0,spine+10,0);
        shadow.addColorStop(0,'#0000');shadow.addColorStop(.5,'#0003');shadow.addColorStop(1,'#0000');
        ctx.fillStyle=shadow;ctx.fillRect(spine-10,top,20,h);ctx.restore();
        surface.dataset.progress = String(p);
      }
    };
    activeCurl = owner;
    try { owner.paint(0); } catch (error) { owner.close(); throw error; }
    return owner;
  }
  async function draw(requested, turn = false, updateHash = true) {
    if (!pdf || disposed) return;
    cancelOutlineSelection();
    const ownedCanvases = new Set(), retiredCanvases = new Set();
    let curl = null;
    let ownedPages = [];
    const oldPage = page, token = ++renderEpoch;
    renders.forEach(task => { try { task.cancel(); } catch (_) {} }); renders = []; stopAnimations();
    const targetPage = Math.max(1, Math.min(pdf.numPages, requested));
    // Automatic geometry redraws must retain an uncommitted destination, not
    // replace it with the last successfully displayed page or a stale hash policy.
    pendingDraw = {token, targetPage, updateHash};
    const selected = pagesFor(targetPage, pdf.numPages, spread());
    say(`Loading page${selected.length > 1 ? 's' : ''} ${selected.join('–')}…`);
    stage.setAttribute('aria-busy', 'true');
    try {
      const docs = await Promise.all(selected.map(n => pdf.getPage(n)));
      if (token !== renderEpoch) return;
      ownedPages = docs;
      ownedPages.forEach(proxy => pageResources.acquire(proxy));
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
        curl = startCurl(staged, targetPage > oldPage);
        await animate(leaf, [{'--manual-turn-progress':'0'}, {'--manual-turn-progress':'.5'}], {duration:190,easing:'ease-in',fill:'none'}, curl ? p => curl.paint(p / 2) : null);
      }
      if (token !== renderEpoch) return;
      papers.forEach((paper, i) => {
        paper.hidden = !staged[i];
        const previous = paper.querySelector('canvas');
        if (staged[i]) { previous.replaceWith(staged[i]); ownedCanvases.delete(staged[i]); paper.querySelector('[data-page-label]').textContent = selected[i]; }
        if (curl) retiredCanvases.add(previous); else releaseCanvas(previous);
      });
      page = targetPage;
      visiblePages = selected;
      pageResources.setVisible(docs);
      ownedPages.forEach(proxy => pageResources.release(proxy));
      ownedPages = [];
      textPlaceholder(selected);
      book.dataset.spread = String(selected.length > 1);
      setControls(); stage.scrollLeft = 0;
      if (updateHash) history.replaceState(null, '', `#page=${page}`);
      say(`Page${selected.length > 1 ? 's' : ''} ${selected.join('–')} of ${pdf.numPages}`);
      stage.removeAttribute('aria-busy');
      if (pendingDraw?.token === token) pendingDraw = null;
      if (turn && oldPage !== page) {
        const leaf = page > oldPage ? papers[0] : papers[selected.length - 1];
        await animate(leaf, [{'--manual-turn-progress':'.5'},{'--manual-turn-progress':'1'}], {duration:210,easing:'ease-out',fill:'none'}, curl ? p => curl.paint(.5 + p / 2) : null);
      }
      curl?.close();
      retiredCanvases.forEach(releaseCanvas);
      retiredCanvases.clear();
      await displayText(selected, token);
    } catch (error) {
      if (token !== renderEpoch || error?.name === 'RenderingCancelledException') return;
      setControls();
      say(`Page ${targetPage} could not be rendered.${visiblePages.length ? ` Still showing ${visiblePages.join('–')}.` : ''} Try another page, or download the original PDF.`);
      stage.removeAttribute('aria-busy');
    } finally {
      // A failed/cancelled owner must not be retried by a later resize. An old
      // draw settling after supersession cannot clear the newer owner's intent.
      if (pendingDraw?.token === token) pendingDraw = null;
      // Uncommitted canvases include earlier pages when a later page fails or supersedes.
      curl?.close();
      retiredCanvases.forEach(releaseCanvas);
      ownedCanvases.forEach(releaseCanvas);
      ownedPages.forEach(proxy => pageResources.release(proxy));
    }
  }
  const redrawGeometry = () => draw(pendingDraw?.targetPage ?? page, false, pendingDraw?.updateHash ?? true);
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
  const observer=new ResizeObserver(()=>{const width=stage.clientWidth;if(Math.abs(width-lastWidth)<2)return;lastWidth=width;clearTimeout(resizeTimer);resizeTimer=setTimeout(redrawGeometry,120);});observer.observe(stage);
  const full=$('[data-fullscreen]');
  if(document.fullscreenEnabled&&root.requestFullscreen){full.hidden=false;full.addEventListener('click',async()=>{try{if(document.fullscreenElement===root)await document.exitFullscreen();else await root.requestFullscreen();}catch(_){say('Full screen is unavailable in this browser.');}});document.addEventListener('fullscreenchange',()=>{full.textContent=document.fullscreenElement===root?'Exit full screen':'Full screen';redrawGeometry();});}
  const searchCancel = $('[data-search-cancel]'), searchStatus = $('[data-search-status]');
  const finishSearch = () => {
    const ownedFocus = !disposed && document.activeElement === searchCancel;
    searchCancel.hidden = true;
    if (ownedFocus) searchStatus.focus?.();
  };
  searchCancel.addEventListener('click',()=>{
    if (disposed || searchCancel.hidden) return;
    searchEpoch++;
    searchStatus.textContent='Search stopped. Results found so far are below.';
    finishSearch();
  });
  $('[data-search-form]').addEventListener('submit',async event=>{
    event.preventDefault();if(!pdf||disposed)return;
    const term=$('[data-search-input]').value.trim().toLocaleLowerCase(), token=++searchEpoch;
    $('[data-search-results]').replaceChildren();
    if(!term){searchStatus.textContent='Enter a word or part number.';finishSearch();return;}
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
    finally{if(token===searchEpoch)finishSearch();}
  });
  // Register before awaiting the document: a large pending load is still owned
  // by this view and must be released on permanent departure.
  const disposeReader = () => {
    if (disposed) return;
    disposed=true; pendingDraw=null; finishLoading(); renderEpoch++; searchEpoch++; finishSearch(); clearTimeout(resizeTimer);
    renders.forEach(task=>{try{task.cancel();}catch(_){}});
    papers.forEach(p=>releaseCanvas(p.querySelector('canvas')));
    observer.disconnect(); stopAnimations(); textCache.close(); pendingText.clear(); pageResources.close();
    // Teardown owns late outcomes; a rejected destroy must not revive the view.
    rangeController?.abort();
    destroyLoadingTask();
  };
  window.addEventListener('pagehide',event=>{touch=null;if(event.persisted)return;disposeReader();});
  loadCancel?.addEventListener('click',()=>{
    if (disposed || !loadPending || (!loadingTask && !rangeController)) return;
    disposeReader();
    say('Reader loading stopped. Reload this page to try again, or download the original PDF.');
    status.focus?.();
  });
  try{
    const url=new URL(root.dataset.pdfUrl,location.href);
    if(url.origin!==location.origin)throw new Error('Only this site’s manual files can be read.');
    // Large originals use range delivery without background prefetch so the
    // first page need not wait for the whole PDF. Small files are unchanged.
    const rangeLoading=root.dataset.pdfRanges==='on';
    if (loadCancel) loadCancel.hidden = false;
    const source = rangeLoading ? {range: await openManualRange(url.href, rangeController = new AbortController(), () => {
      finishLoading();
      disposeReader();
      controls.forEach(selector=>{$(selector).disabled=true;});
      say('The connection stopped supplying the manual. Reload to try again, or download the original PDF.');
    })} : {url:url.href};
    if (disposed) return;
    const task=loadingTask=pdfjs.getDocument({...source,cMapUrl:`${vendor}cmaps/`,cMapPacked:true,standardFontDataUrl:`${vendor}standard_fonts/`,wasmUrl:`${vendor}wasm/`,isEvalSupported:false,enableXfa:false,disableAutoFetch:true,disableStream:true,disableRange:!rangeLoading,rangeChunkSize:manualRangeChunkSize,disableWorkerRendering:true,maxImageSize:16000000,canvasMaxAreaInBytes:32000000});
    task.onProgress=progress=>{
      if (disposed || !loadPending || loadError || !progress || !Number.isSafeInteger(progress.loaded) || progress.loaded < 0) return;
      const total = progress.total;
      let message;
      if (rangeLoading) {
        message = 'Opening the manual…';
      } else if (Number.isSafeInteger(total) && total > 0) {
        const percent = Math.min(100, Math.floor(progress.loaded / total * 20) * 5);
        message = percent >= 100 ? 'Download received. Preparing the manual…' : `Downloading manual… ${percent}%`;
      } else {
        const mib = Math.floor(progress.loaded / (5 * 1024 * 1024)) * 5;
        message = mib ? `Downloading manual… at least ${mib} MiB received.` : 'Downloading manual…';
      }
      if (message !== progressMessage) { progressMessage = message; say(message); }
    };
    task.onPassword=()=>{if(disposed||!loadPending)return;finishLoading();loadError='This PDF requires a password. Download the original or ask for an unlocked, authorized copy.';destroyLoadingTask();say(loadError);};
    const loaded=await task.promise;
    if(disposed)return;
    finishLoading();
    pdf=loaded;
    if(pdf.numPages>5000){await loadingTask.destroy();pdf=null;throw new Error('This PDF exceeds the 5,000-page reader limit.');}
    loadOutline();
    controls.forEach(selector=>{$(selector).disabled=false;});
    await draw(queryPage(location.hash,pdf.numPages),false,false);
  }catch(error){if(disposed)return;rangeController?.abort();destroyLoadingTask();finishLoading();say(loadError || 'The book reader could not open this PDF. Download the original to continue, or try again later.');}
}
