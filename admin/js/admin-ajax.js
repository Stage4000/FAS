/* Same-page progressive enhancement. PHP remains responsible for validation and rendering. */
(() => {
    'use strict';
    if (!window.fetch || !window.FormData || !window.DOMParser || !window.AbortController) return;
    const root = document.getElementById('admin-content');
    if (!root) return;
    function includeModals(scope, content) {
        // Several legacy templates render their modal forms after the content column.
        scope.querySelectorAll('.modal').forEach(modal => {
            // Analytics keeps its session modal alive across report refreshes so a
            // Bootstrap hide transition cannot be interrupted by DOM replacement.
            if (page === 'analytics.php' && modal.id === 'sessionDetailsModal') return;
            if (!content.contains(modal)) content.append(modal);
        });
    }
    const page = root.dataset.adminPage;
    includeModals(document, root);
    let busy = false;
    let pendingHistory = false;
    const closingModals = new WeakSet();
    document.addEventListener('hide.bs.modal', event => closingModals.add(event.target));
    document.addEventListener('hidden.bs.modal', event => closingModals.delete(event.target));
    const notice = document.createElement('div');
    notice.className = 'admin-notification-host';
    notice.setAttribute('aria-live', 'polite');
    notice.setAttribute('aria-atomic', 'true');
    document.body.append(notice);
    let dismissTimer = null;

    function clearNotice() {
        if (dismissTimer !== null) window.clearTimeout(dismissTimer);
        dismissTimer = null;
        notice.replaceChildren();
    }

    function announce(message, error = false, login = false, pending = false) {
        clearNotice();
        const alert = document.createElement('div');
        alert.className = `alert alert-${error ? 'danger' : 'success'} admin-ajax-notice shadow mb-0`;
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
        close.className = 'btn-close';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.addEventListener('click', clearNotice);
        alert.append(close);
        notice.append(alert);
        if (!pending) {
            dismissTimer = window.setTimeout(() => {
                if (notice.contains(alert)) clearNotice();
            }, error ? 8000 : 5000);
        }
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
        await Promise.all(Array.from(root.querySelectorAll('.modal')).map(modal => new Promise(resolve => {
            const instance = window.bootstrap?.Modal.getInstance(modal);
            if (!instance) return resolve();
            if (!instance._isShown && !instance._isTransitioning && !closingModals.has(modal)) return resolve();
            modal.addEventListener('hidden.bs.modal', resolve, { once: true });
            if (instance._isShown && instance._isTransitioning) {
                modal.addEventListener('shown.bs.modal', () => instance.hide(), { once: true });
            } else if (instance._isShown) {
                instance.hide();
            }
        })));
        root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => window.bootstrap?.Tooltip.getInstance(el)?.dispose());
        root.querySelectorAll('.modal').forEach(el => window.bootstrap?.Modal.getInstance(el)?.dispose());
    }

    async function update(url, options = {}, submitted = null, historyMode = 'push') {
        if (busy) return false;
        busy = true;
        const isPost = options.method === 'POST';
        const fragmentSelectors = isPost && submitted?.dataset.adminFragments
            ? submitted.dataset.adminFragments.split(',').map(selector => selector.trim()).filter(Boolean)
            : [];
        const scroll = [window.scrollX, window.scrollY];
        const details = Array.from(root.querySelectorAll('details[id][open]')).map(el => el.id);
        const edits = isPost ? dirtyForms(submitted) : [];
        const buttons = submitted ? Array.from(submitted.querySelectorAll('button, input[type="submit"]')).filter(el => !el.disabled) : [];
        const active = document.activeElement;
        const focusGroup = active?.dataset?.ajaxFocusGroup;
        const focusIndex = focusGroup ? Array.from(root.querySelectorAll('[data-ajax-focus-group]'))
            .filter(el => el.dataset.ajaxFocusGroup === focusGroup).indexOf(active) : -1;
        root.setAttribute('aria-busy', 'true');
        // Prevent edits and competing actions until this response has been applied.
        root.inert = true;
        buttons.forEach(el => { el.disabled = true; });
        announce(isPost ? 'Saving changes…' : 'Loading…', false, false, true);
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
                    return false;
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
            if (!isPost && url.hash) finalUrl.hash = url.hash;
            await closeModals();
            // Page scripts are initialized explicitly, never evaluated from fetched HTML.
            next.querySelectorAll('script').forEach(el => el.remove());
            const fragments = fragmentSelectors.map(selector => ({
                current: root.querySelector(selector), fresh: next.querySelector(selector)
            }));
            const patch = fragments.length > 0 && fragments.every(({ current, fresh }) => current && fresh);
            if (patch) {
                fragments.forEach(({ current, fresh }) => current.replaceWith(fresh));
                if (submitted?.hasAttribute('data-admin-reset-on-success')) submitted.reset();
            } else {
                root.replaceChildren(...next.childNodes);
            }
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
                        // Never carry credentials into a newly rendered form.
                        if (el.type === 'password') return;
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
            document.dispatchEvent(new CustomEvent(patch ? 'admin:patched' : 'admin:updated', { detail: { root, page } }));
            window.FASTimezone?.convertAll();
            window.AOS?.refreshHard();
            root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => window.bootstrap?.Tooltip.getOrCreateInstance(el));
            // Do not replay entry animations when updating an existing screen.
            root.querySelectorAll('[data-aos]').forEach(el => el.classList.add('aos-animate'));
            root.inert = false;
            const focus = active?.id ? document.getElementById(active.id) : null;
            if (focus) focus.focus({ preventScroll: true });
            else if (focusGroup) {
                const candidates = Array.from(root.querySelectorAll('[data-ajax-focus-group]'))
                    .filter(el => el.dataset.ajaxFocusGroup === focusGroup);
                const nextAction = candidates[Math.min(Math.max(focusIndex, 0), candidates.length - 1)];
                if (nextAction) nextAction.focus({ preventScroll: true });
                else { root.setAttribute('tabindex', '-1'); root.focus({ preventScroll: true }); }
            }
            else { root.setAttribute('tabindex', '-1'); root.focus({ preventScroll: true }); }
            window.scrollTo(...scroll);
            if (finalUrl.hash) document.getElementById(decodeURIComponent(finalUrl.hash.slice(1)))?.scrollIntoView();
            announce(next.dataset.adminNotice || (isPost ? 'Changes saved.' : 'View updated.'));
            return true;
        } catch (error) {
            announce(error instanceof TypeError || error.name === 'AbortError'
                ? 'Connection interrupted. Your entries are still here. Check whether the change was saved before submitting again.'
                : error.message, true);
            return false;
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

    window.FASAdminAjax = Object.freeze({
        refresh: () => update(new URL(location.href), {}, null, 'none')
    });

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-admin-refresh]');
        if (!button || !root.contains(button)) return;
        event.preventDefault();
        window.FASAdminAjax.refresh();
    });

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
        if (!samePage(url) || link.getAttribute('href').startsWith('#') || (url.hash && url.search === location.search)) return;
        event.preventDefault();
        update(url);
    });

    window.addEventListener('popstate', () => {
        // GET only: history navigation must never replay a mutation.
        if (busy) { pendingHistory = true; return; }
        update(new URL(location.href), {}, null, 'none');
    });
})();
