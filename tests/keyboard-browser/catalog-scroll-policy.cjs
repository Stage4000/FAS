'use strict';
// Additive predicates belong only to the catalog-scroll suite. Legacy policy is unchanged.
const DOCUMENTS = new Set(['/products', '/products?category=motorcycle']);
const FETCH_QUERIES = new Set([
    'page=2&category=motorcycle&include_sidebar=1',
    'category=motorcycle&search=Synthetic&include_sidebar=1',
    '&include_sidebar=1',
]);
function allowedScrollDocument(url, origin, method, type) {
    return method === 'GET' && type === 'document' && url.origin === origin && !url.hash &&
        DOCUMENTS.has(url.pathname + url.search);
}
function allowedScrollFetch(url, origin, method, type) {
    return method === 'GET' && type === 'fetch' && url.origin === origin &&
        url.pathname === '/api/products.php' && !url.hash && FETCH_QUERIES.has(url.search.slice(1));
}
module.exports = {allowedScrollDocument, allowedScrollFetch};
