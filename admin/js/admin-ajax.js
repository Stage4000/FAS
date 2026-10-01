/* Same-page progressive enhancement. PHP remains responsible for validation and rendering. */
(() => {
    'use strict';
    if (!window.fetch || !window.FormData || !window.DOMParser || !window.AbortController) return;
    const root = document.getElementById('admin-content');
    if (!root) return;
    function includeModals(scope, content) {
        // Several legacy templates render their modal forms after the content column.
        scope.querySelectorAll('.modal').forEach(modal => {
            if (!content.contains(modal)) content.append(modal);
        });
    }
    includeModals(document, root);
    const page = root.dataset.adminPage;
    let busy = false;
    let pendingHistory = false;
    const notice = document.createElement('div');
    notice.className = 'position-fixed bottom-0 end-0 p-3';
    notice.style.cssText = 'z-index:1090;max-width:100%;width:440px;pointer-events:none';
    notice.setAttribute('aria-live', 'polite');
    notice.setAttribute('aria-atomic', 'true');
    document.body.append(notice);

    function announce(message, error = false, login = false) {
        notice.replaceChildren();
        const alert = document.createElement('div');
        alert.className = `alert alert-${error ? 'danger' : 'success'} admin-ajax-notice shadow mb-0`;
        alert.style.pointerEvents = 'auto';
        alert.setAttribute('role', error ? 'alert' : 'status');
        alert.append(document.createTextNode(message));
        if (login) {
            const link = document.createElement('a');
            link.href = 'login.php';
            link.target = '_blank';
            link.rel = 'noopener';
            link.className = 'alert-link d-block mt-2';
            link.textContent = 'Sign in in a new tab';
            alert.append(link);
        }
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close ms-2';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.addEventListener('click', () => notice.replaceChildren());
        alert.append(close);
        notice.append(alert);
    }

    function samePage(url) {
        return url.origin === location.origin && url.pathname === location.pathname;
    }

    // Keep independently edited forms (for example other security rules) intact.
    function formKey(form) {
        return form.getAttribute('id') || JSON.stringify(Array.from(form.elements)
            .filter(el => el.name && el.type === 'hidden' && el.name !== 'csrf_token')
            .map(el => [el.name, el.value]));
    }

    function dirtyForms(submitted) {
        return Array.from(root.querySelectorAll('form')).filter(form => form !== submitted &&
            Array.from(form.elements).some(el => {
                if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return false;
                if (el.type === 'checkbox' || el.type === 'radio') return el.checked !== el.defaultChecked;
                if (el.tagName === 'SELECT') {
                    const options = Array.from(el.options);
                    const hasDefault = options.some(option => option.defaultSelected);
                    return options.some((option, i) => option.selected !==
                        (option.defaultSelected || (!el.multiple && i === 0 && !hasDefault)));
                }
                return 'defaultValue' in el && el.value !== el.defaultValue;
            })).map(form => ({ key: formKey(form), form }));
    }

    async function closeModals() {
        await Promise.all(Array.from(root.querySelectorAll('.modal.show')).map(modal => new Promise(resolve => {
            const instance = window.bootstrap?.Modal.getInstance(modal);
            if (!instance) return resolve();
            modal.addEventListener('hidden.bs.modal', resolve, { once: true });
            instance.hide();
        })));
        root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => window.bootstrap?.Tooltip.getInstance(el)?.dispose());
        root.querySelectorAll('.modal').forEach(el => window.bootstrap?.Modal.getInstance(el)?.dispose());
    }

    async function update(url, options = {}, submitted = null, historyMode = 'push') {
        if (busy) return;
        busy = true;
        const isPost = options.method === 'POST';
        const scroll = [window.scrollX, window.scrollY];
        const details = Array.from(root.querySelectorAll('details[id][open]')).map(el => el.id);
        const edits = isPost ? dirtyForms(submitted) : [];
        const buttons = submitted ? Array.from(submitted.querySelectorAll('button, input[type="submit"]')).filter(el => !el.disabled) : [];
        const active = document.activeElement;
        root.setAttribute('aria-busy', 'true');
        // Prevent edits and competing actions until this response has been applied.
        root.inert = true;
        buttons.forEach(el => { el.disabled = true; });
        announce(isPost ? 'Saving changes…' : 'Loading…');
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 90000);
        try {
            const response = await fetch(url, {
                ...options, credentials: 'same-origin', cache: 'no-store',
                signal: controller.signal,
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }
            });
            let finalUrl = new URL(response.url);
            if (!samePage(finalUrl)) {
                if (finalUrl.pathname.endsWith('/login.php')) {
                    announce('Your session has expired. Sign in before trying again. Your entries are still here.', true, true);
                    return;
                }
                throw new Error('The server returned a different page. Check the current state before trying again.');
            }
            const html = await response.text();
            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const next = parsed.getElementById('admin-content');
            if (!next || next.dataset.adminPage !== page) {
                throw new Error(response.status === 403 ? 'Access was denied. Sign in again or check your permissions.' : 'The page could not be updated. Check the current state before trying again.');
            }
            includeModals(parsed, next);
            if (next.dataset.adminError || !response.ok) {
                // Preserve all entered values on validation, CSRF, and server failures.
                const token = next.querySelector('input[name="csrf_token"]');
                if (token) root.querySelectorAll('input[name="csrf_token"]').forEach(el => { el.value = token.value; });
                throw new Error(next.dataset.adminError || `The request failed (${response.status}). Check the current state before trying again.`);
            }
            if (next.dataset.adminUrl) {
                const canonical = new URL(next.dataset.adminUrl, finalUrl);
                if (samePage(canonical)) finalUrl = canonical;
            }
            await closeModals();
            // Page scripts are initialized explicitly, never evaluated from fetched HTML.
            next.querySelectorAll('script').forEach(el => el.remove());
            root.replaceChildren(...next.childNodes);
            root.dataset.adminError = '';
            root.dataset.adminNotice = next.dataset.adminNotice;
            edits.forEach(({ key, form }) => {
                const replacement = Array.from(root.querySelectorAll('form')).find(el => formKey(el) === key);
                if (replacement) {
                    const original = Array.from(form.elements).filter(el => el.name && el.type !== 'hidden');
                    const fresh = Array.from(replacement.elements).filter(el => el.name && el.type !== 'hidden');
                    original.forEach((el, i) => {
                        const target = fresh[i];
                        if (!target || target.name !== el.name || target.type !== el.type) return;
                        if (el.type === 'file') target.files = el.files;
                        else if (el.type === 'checkbox' || el.type === 'radio') target.checked = el.checked;
                        else if (el.tagName === 'SELECT') Array.from(target.options).forEach((o, j) => { o.selected = el.options[j]?.selected || false; });
                        else target.value = el.value;
                    });
                }
            });
            details.forEach(id => { const el = document.getElementById(id); if (el) el.open = true; });
            document.title = parsed.title;
            if (!pendingHistory && historyMode !== 'none' && finalUrl.href !== location.href) {
                history[historyMode === 'replace' || isPost ? 'replaceState' : 'pushState']({ adminAjax: page }, '', finalUrl);
            }
            document.dispatchEvent(new CustomEvent('admin:updated', { detail: { root, page } }));
            window.FASTimezone?.convertAll();
            window.AOS?.refreshHard();
            root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => window.bootstrap?.Tooltip.getOrCreateInstance(el));
            // Do not replay entry animations when updating an existing screen.
            root.querySelectorAll('[data-aos]').forEach(el => el.classList.add('aos-animate'));
            root.inert = false;
            const focus = active?.id ? document.getElementById(active.id) : null;
            if (focus) focus.focus({ preventScroll: true });
            else { root.setAttribute('tabindex', '-1'); root.focus({ preventScroll: true }); }
            window.scrollTo(...scroll);
            announce(next.dataset.adminNotice || (isPost ? 'Changes saved.' : 'View updated.'));
        } catch (error) {
            announce(error instanceof TypeError || error.name === 'AbortError'
                ? 'Connection interrupted. Your entries are still here. Check whether the change was saved before submitting again.'
                : error.message, true);
        } finally {
            window.clearTimeout(timeout);
            busy = false;
            root.inert = false;
            root.removeAttribute('aria-busy');
            buttons.forEach(el => { el.disabled = false; });
            if (pendingHistory) {
                pendingHistory = false;
                update(new URL(location.href), {}, null, 'none');
            }
        }
    }

    // Bubble after existing form validation and confirmation handlers.
    document.addEventListener('submit', event => {
        const form = event.target;
        if (event.defaultPrevented || !root.contains(form) || form.matches('[data-no-ajax]')) return;
        const submitter = event.submitter;
        // Hidden inputs named "action" shadow form.action on HTMLFormElement.
        const url = new URL(submitter?.getAttribute('formaction') || form.getAttribute('action') || location.href, location.href);
        const method = (submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'get').toUpperCase();
        const target = submitter?.getAttribute('formtarget') || form.getAttribute('target');
        if (!samePage(url) || (target && target !== '_self') || !['GET', 'POST'].includes(method)) return;
        event.preventDefault();
        if (busy) return;
        const data = new FormData(form);
        if (submitter?.name) data.append(submitter.name, submitter.value);
        if (method === 'GET') {
            url.search = new URLSearchParams(data).toString();
            update(url);
        } else {
            update(url, { method, body: data }, form);
        }
    });

    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (event.defaultPrevented || !link || !root.contains(link) || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (link.hasAttribute('download') || link.hasAttribute('data-bs-toggle') || link.hasAttribute('data-no-ajax') || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href, location.href);
        if (!samePage(url) || url.hash || link.getAttribute('href').startsWith('#')) return;
        event.preventDefault();
        update(url);
    });

    window.addEventListener('popstate', () => {
        // GET only: history navigation must never replay a mutation.
        if (busy) { pendingHistory = true; return; }
        update(new URL(location.href), {}, null, 'none');
    });
})();
