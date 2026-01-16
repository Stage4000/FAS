<?php
$pageTitle = 'Contact Us';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-5">
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <h1 class="mb-4 fw-bold text-center">Contact Us</h1>
            
            <div class="row g-4 mb-5">
                <div class="col-md-4 text-center">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <i class="bi bi-shop display-4 text-danger mb-3"></i>
                            <h5>eBay Store</h5>
                            <p class="text-muted">Visit our eBay store for more items</p>
                            <a href="https://www.ebay.com/str/moto800" target="_blank" rel="noopener" class="btn btn-outline-danger btn-sm">Visit Store</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <i class="bi bi-globe display-4 text-danger mb-3"></i>
                            <h5>Website</h5>
                            <p class="text-muted">Browse our online catalog</p>
                            <a href="https://flipandstrip.com" class="btn btn-outline-danger btn-sm">Visit Site</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <i class="bi bi-truck display-4 text-danger mb-3"></i>
                            <h5>Shipping</h5>
                            <p class="text-muted">Fast shipping via EasyShip</p>
                            <p class="small text-muted mb-0">Domestic & International</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card border-0 shadow-sm">
                <div class="card-body p-5">
                    <h3 class="mb-4">Send Us a Message</h3>
                    <form id="contact-form">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name *</label>
                            <input type="text" class="form-control" id="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="subject" class="form-label">Subject *</label>
                            <input type="text" class="form-control" id="subject" required>
                        </div>
                        <div class="mb-3">
                            <label for="message" class="form-label">Message *</label>
                            <textarea class="form-control" id="message" rows="5" required></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> For specific part inquiries, please include the make, model, and year of your vehicle.
                        </div>
                        <button type="submit" class="btn btn-danger btn-lg w-100">
                            <i class="bi bi-send"></i> Send Message
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="alert alert-secondary mt-4">
                <h5><i class="bi bi-clock me-2"></i>Response Time</h5>
                <p class="mb-0">We typically respond to all inquiries within 24 hours during business days.</p>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('contact-form').addEventListener('submit', function(e) {
    e.preventDefault();
    alert('Thank you for your message! We will get back to you soon.');
    this.reset();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
