// Main JavaScript for Flip and Strip

// Cart management
class ShoppingCart {
    constructor() {
        this.cart = this.loadCart();
        this.updateCartCount();
    }

    loadCart() {
        const cart = localStorage.getItem('flipandstrip_cart');
        return cart ? JSON.parse(cart) : [];
    }

    saveCart() {
        localStorage.setItem('flipandstrip_cart', JSON.stringify(this.cart));
        this.updateCartCount();
    }

    getCartSummary() {
        return this.cart.reduce((summary, item) => {
            const quantity = Number(item.quantity || 0);
            const price = Number(item.price || 0);
            summary.cart_unique_items += 1;
            summary.cart_items_count += quantity;
            summary.cart_value += price * quantity;
            return summary;
        }, {
            cart_unique_items: 0,
            cart_items_count: 0,
            cart_value: 0
        });
    }

    getItemById(productId) {
        return this.cart.find(item => item.id === productId);
    }

    trackCartEvent(eventType, item, extra = {}) {
        if (window.fasAnalytics && typeof window.fasAnalytics.trackCartEvent === 'function') {
            window.fasAnalytics.trackCartEvent(eventType, item || {}, Object.assign({
                cart_summary: this.getCartSummary()
            }, extra));
        }
    }

    addItem(product) {
        const existingItem = this.cart.find(item => item.id === product.id);
        if (existingItem) {
            // Check stock limit before incrementing
            const stockLimit = existingItem.stock || 999;
            if (existingItem.quantity < stockLimit) {
                existingItem.quantity += 1;
                product.quantity = existingItem.quantity;
            } else {
                this.trackCartEvent('cart_stock_limit_hit', existingItem, {
                    requested_quantity: existingItem.quantity + 1,
                    stock_limit: stockLimit
                });
                if (window.showToast) {
                    window.showToast('Maximum available quantity reached', 'warning');
                }
                return;
            }
        } else {
            this.cart.push({
                ...product,
                quantity: 1
            });
        }
        this.saveCart();
        this.trackCartEvent('cart_item_added', this.getItemById(product.id) || product, {
            quantity_added: 1
        });
        // Use animation instead of notification
        if (window.showToast) {
            window.showToast('Added to cart!', 'success');
        }
    }

    removeItem(productId) {
        const removedItem = this.getItemById(productId);
        this.cart = this.cart.filter(item => item.id !== productId);
        this.saveCart();
        this.trackCartEvent('cart_item_removed', removedItem || { id: productId }, {
            removed_quantity: removedItem ? removedItem.quantity : 0
        });
    }

    updateQuantity(productId, quantity) {
        const item = this.cart.find(item => item.id === productId);
        if (item) {
            const newQuantity = parseInt(quantity);
            const stockLimit = item.stock || 999;
            const previousQuantity = item.quantity;

            if (newQuantity <= 0) {
                this.removeItem(productId);
            } else if (newQuantity > stockLimit) {
                // Don't allow exceeding stock limit
                item.quantity = stockLimit;
                this.saveCart();
                this.trackCartEvent('cart_stock_limit_hit', item, {
                    previous_quantity: previousQuantity,
                    requested_quantity: newQuantity,
                    stock_limit: stockLimit
                });
                if (window.showToast) {
                    window.showToast('Maximum available quantity reached', 'warning');
                }
            } else {
                item.quantity = newQuantity;
                this.saveCart();
                if (newQuantity !== previousQuantity) {
                    this.trackCartEvent('cart_quantity_changed', item, {
                        previous_quantity: previousQuantity,
                        new_quantity: newQuantity
                    });
                }
            }
        }
    }

    getTotal() {
        return this.cart.reduce((total, item) => total + (item.price * item.quantity), 0);
    }

    getItemCount() {
        return this.cart.reduce((count, item) => count + item.quantity, 0);
    }

    updateCartCount() {
        const badge = document.getElementById('cart-count');
        if (badge) {
            const count = this.getItemCount();
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline-block' : 'none';
        }
    }

    showNotification(message, type = 'info') {
        // Create a simple notification
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} position-fixed top-0 end-0 m-3`;
        notification.style.zIndex = '9999';
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    clearCart() {
        const previousSummary = this.getCartSummary();
        this.cart = [];
        this.saveCart();
        if (previousSummary.cart_items_count > 0 && window.fasAnalytics) {
            window.fasAnalytics.track('cart_cleared', previousSummary, { immediate: true });
        }
    }
}

// Initialize cart
const cart = new ShoppingCart();

// Add to cart buttons
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('add-to-cart') || e.target.closest('.add-to-cart')) {
        e.preventDefault();
        const button = e.target.classList.contains('add-to-cart') ? e.target : e.target.closest('.add-to-cart');
        const productData = {
            id: button.dataset.id,
            name: button.dataset.name,
            price: parseFloat(button.dataset.price),
            image: button.dataset.image || '',
            image_alt: button.dataset.imageAlt || button.dataset.name,
            sku: button.dataset.sku || '',
            category: button.dataset.category || '',
            manufacturer: button.dataset.manufacturer || '',
            source: button.dataset.source || '',
            weight: parseFloat(button.dataset.weight) || 1.0,
            length: parseFloat(button.dataset.length) || 10.0,
            width: parseFloat(button.dataset.width) || 10.0,
            height: parseFloat(button.dataset.height) || 10.0,
            stock: parseInt(button.dataset.stock) || 999
        };
        cart.addItem(productData);
        
        // Animate button instead of showing popup
        if (window.animateAddToCart) {
            window.animateAddToCart(button);
        }
    }
});

// Product image gallery
function setupImageGallery() {
    const thumbnails = document.querySelectorAll('.product-thumbnails img');
    const mainImage = document.querySelector('.product-detail-img');

    if (thumbnails.length > 0 && mainImage) {
        thumbnails.forEach(thumb => {
            thumb.addEventListener('click', () => {
                mainImage.src = thumb.dataset.full || thumb.src;
                mainImage.alt = thumb.alt || mainImage.alt;
                thumbnails.forEach(t => t.classList.remove('active'));
                thumb.classList.add('active');
            });
        });
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    setupImageGallery();
    setupSearch();
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (href !== '#' && document.querySelector(href)) {
                e.preventDefault();
                document.querySelector(href).scrollIntoView({
                    behavior: 'smooth'
                });
            }
        });
    });
});

// Search functionality
function setupSearch() {
    const searchInput = document.getElementById('product-search');
    const searchForm = document.getElementById('search-form');
    
    if (searchInput && searchForm) {
        // Remove auto-search on input to avoid conflicts with form submission
        // Users can now type and press Enter or click the Search button
        searchForm.addEventListener('submit', (e) => {
            const query = searchInput.value.trim();
            if (query.length === 0) {
                e.preventDefault();
                // If empty search, reload without search param
                const form = e.target;
                const action = form.action;
                window.location.href = action;
            }
        });
    }
}

// Debounce helper
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Export cart for use in other pages
window.cart = cart;
