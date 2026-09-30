/* Catalog URLs carry the same scope in ordinary navigation and AJAX requests. */
function fasCatalogParamsFromUrl(value) {
    const url = new URL(value, window.location.origin);
    const params = new URLSearchParams(url.search);
    const parts = url.pathname.split('/').filter(Boolean);
    const categories = ['motorcycle', 'atv', 'boat', 'automotive', 'gifts', 'other'];
    const collections = ['trending', 'best-sellers', 'recent-arrivals', 'free-shipping', 'sale'];
    if (parts[0] === 'products') {
        let index = 1;
        if (collections.includes(parts[index])) {
            params.set('collection', parts[index]);
        } else {
            if (categories.includes(parts[index])) params.set('category', parts[index++]);
            if (parts[index] === 'make') {
                if (parts[index + 1]) params.set('manufacturer_slug', parts[index + 1]);
                if (parts[index + 2]) params.set('model_slug', parts[index + 2]);
            }
        }
    }
    return params;
}

function fasCatalogNavigationUrl(input) {
    const params = new URLSearchParams(input);
    let path = '/products';
    const aliases = {best:'best-sellers', recent:'recent-arrivals', free_shipping:'free-shipping'};
    if (params.get('collection')) {
        const collection = params.get('collection');
        path += '/' + encodeURIComponent(aliases[collection] || collection);
    } else {
        if (params.get('category')) path += '/' + encodeURIComponent(params.get('category'));
        if (params.get('manufacturer_slug')) {
            path += '/make/' + encodeURIComponent(params.get('manufacturer_slug'));
            if (params.get('model_slug')) path += '/' + encodeURIComponent(params.get('model_slug'));
        }
    }
    ['category', 'collection', 'manufacturer_slug', 'model_slug'].forEach(key => params.delete(key));
    return path + (params.toString() ? '?' + params.toString() : '');
}

let fasCatalogPageTitle = '';
let fasCatalogTitleObserver = null;
function fasSetCatalogTitle(title) {
    fasCatalogPageTitle = title;
    document.title = title;
    const titleElement = document.querySelector('title');
    if (!fasCatalogTitleObserver && titleElement && typeof MutationObserver !== 'undefined') {
        // Chat tab notifications can restore a title cached before AJAX navigation.
        // Keep this catalog's title aligned with its current results and metadata.
        fasCatalogTitleObserver = new MutationObserver(() => {
            if (document.title !== fasCatalogPageTitle) document.title = fasCatalogPageTitle;
        });
        fasCatalogTitleObserver.observe(titleElement, {childList: true, characterData: true, subtree: true});
    }
}
if (typeof document !== 'undefined') fasSetCatalogTitle(document.title);

function fasApplyCatalogMetadata(metadata) {
    if (!metadata) return;
    fasSetCatalogTitle(metadata.title);
    const values = [
        ['meta[name="description"]', 'content', metadata.description],
        ['meta[name="robots"]', 'content', metadata.robots],
        ['link[rel="canonical"]', 'href', metadata.canonical],
        ['meta[property="og:title"]', 'content', metadata.title],
        ['meta[property="og:description"]', 'content', metadata.description],
        ['meta[property="og:url"]', 'content', metadata.canonical],
        ['meta[name="twitter:title"]', 'content', metadata.title],
        ['meta[name="twitter:url"]', 'content', metadata.canonical],
        ['meta[name="twitter:description"]', 'content', metadata.description],
    ];
    values.forEach(([selector, attribute, value]) => {
        document.querySelector(selector)?.setAttribute(attribute, value);
    });
    document.querySelectorAll('script[type="application/ld+json"]').forEach(script => {
        try {
            if (['BreadcrumbList', 'CollectionPage', 'ItemList'].includes(JSON.parse(script.textContent)['@type'])) script.remove();
        } catch (_) { /* Preserve unrelated structured data. */ }
    });
    (metadata.schemas || []).forEach(schema => {
        const script = document.createElement('script');
        script.type = 'application/ld+json';
        script.textContent = JSON.stringify(schema);
        document.head.appendChild(script);
    });
}
