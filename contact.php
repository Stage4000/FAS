<?php
$pageTitle = 'Contact Us';
require_once __DIR__ . '/includes/header.php';

// Load configuration for Turnstile
$configFile = __DIR__ . '/src/config/config.php';
if (!file_exists($configFile)) {
    // If config doesn't exist, disable Turnstile for safety
    $turnstileEnabled = false;
    $turnstileSiteKey = '';
} else {
    $config = require $configFile;
    $turnstileEnabled = !empty($config['turnstile']['enabled']);
    $turnstileSiteKey = $config['turnstile']['site_key'] ?? '';
}
?>

<div class="container my-5">
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <h1 class="mb-5 fw-bold text-center" data-aos="fade-down">Contact Us</h1>
            
            <div class="mb-5" data-aos="fade-up" data-aos-delay="100">
                <h3 class="mb-4">Send Us a Message</h3>
                <div id="contact-alert" class="alert" style="display: none;"></div>
                <form id="contact-form">
                    <div class="mb-3">
                        <label for="name" class="form-label">Name *</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email *</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="subject" class="form-label">Subject *</label>
                        <input type="text" class="form-control" id="subject" name="subject" required>
                    </div>
                    <div class="mb-3">
                        <label for="message" class="form-label">Message *</label>
                        <textarea class="form-control" id="message" name="message" rows="5" required></textarea>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> For specific part inquiries, please include the make, model, and year of your vehicle.
                    </div>
                    <?php if ($turnstileEnabled && !empty($turnstileSiteKey)): ?>
                    <div class="mb-3">
                        <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars($turnstileSiteKey); ?>" data-theme="auto"></div>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-danger btn-lg w-100" id="submit-btn">
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

<?php if ($turnstileEnabled && !empty($turnstileSiteKey)): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>

<script>
document.getElementById('contact-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = document.getElementById('submit-btn');
    const alertDiv = document.getElementById('contact-alert');
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    
    // Get form data
    const formData = new FormData(form);
    
    try {
        const response = await fetch('/api/contact-form.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        // Show alert
        alertDiv.style.display = 'block';
        if (result.success) {
            alertDiv.className = 'alert alert-success';
            alertDiv.innerHTML = '<i class="fas fa-check-circle me-2"></i>' + result.message;
            form.reset();
            // Reset Turnstile widget if it exists (pass container selector for proper reset)
            if (window.turnstile && window.turnstile.reset) {
                window.turnstile.reset('.cf-turnstile');
            }
        } else {
            alertDiv.className = 'alert alert-danger';
            alertDiv.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>' + result.message;
        }
        
        // Scroll to alert
        alertDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        
    } catch (error) {
        alertDiv.style.display = 'block';
        alertDiv.className = 'alert alert-danger';
        alertDiv.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>An error occurred. Please try again later.';
    } finally {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Message';
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
