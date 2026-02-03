<?php
$pageTitle = 'Contact Us';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container my-5">
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <h1 class="mb-5 fw-bold text-center" data-aos="fade-down">Contact Us</h1>
            
            <div class="mb-5" data-aos="fade-up" data-aos-delay="100">
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
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> For specific part inquiries, please include the make, model, and year of your vehicle.
                    </div>
                    <button type="submit" class="btn btn-danger btn-lg w-100">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </form>
            </div>
            
            <div class="alert alert-secondary mt-5">
                <h5><i class="fas fa-clock me-2"></i>Response Time</h5>
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
