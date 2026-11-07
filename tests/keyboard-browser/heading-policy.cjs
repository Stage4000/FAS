'use strict';
const SEARCH_EMPTY = 'zz-heading-no-match-9f61';
const SEARCH_FULL = 'Synthetic';
const documentPaths = new Set(['/', '/products', '/product/1/synthetic-test-part', '/cart']);
const queries = new Set(['', `?search=${SEARCH_EMPTY}`, `?search=${SEARCH_FULL}`]);
function allowedCatalogFetch(url, origin, method, type) {
    return url.origin === origin && url.pathname === '/api/products.php' && method === 'GET' && type === 'fetch' &&
        [`search=${SEARCH_EMPTY}&include_sidebar=1`, `search=${SEARCH_FULL}&include_sidebar=1`].includes(url.search.slice(1));
}
function allowedDocument(url, origin, method, type) {
    return method === 'GET' && type === 'document' && url.origin === origin &&
        documentPaths.has(url.pathname) && (url.pathname === '/products' ? queries.has(url.search) : url.search === '');
}
function allowedLocalAsset(url,origin,method,type) {
    if(url.origin!==origin || method!=='GET')return false;
    const patterns={script:/^\/public\/.*\.js$/, stylesheet:/^\/public\/.*\.css$/,
        image:/^\/(?:public|gallery)\/.*\.(?:png|jpe?g|gif|webp|svg|ico)$/i,
        font:/^\/public\/.*\.(?:woff2?|ttf|otf)$/i};
    return !!patterns[type]?.test(url.pathname);
}
function allowedCachedType(type) {return ['script','stylesheet','image','font'].includes(type);}
module.exports = {SEARCH_EMPTY, SEARCH_FULL, allowedCatalogFetch, allowedDocument, allowedLocalAsset, allowedCachedType};
