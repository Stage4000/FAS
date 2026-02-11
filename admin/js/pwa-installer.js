/**
 * PWA Installer for Admin Panel
 * Handles service worker registration and PWA installation prompt
 */

let deferredPrompt;
let installButton;

// Register service worker
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/admin/service-worker.js', {
      scope: '/admin/'
    })
    .then(registration => {
      console.log('Admin SW registered:', registration.scope);
    })
    .catch(error => {
      console.log('Admin SW registration failed:', error);
    });
  });
}

// Listen for the beforeinstallprompt event
window.addEventListener('beforeinstallprompt', (e) => {
  // Prevent the mini-infobar from appearing on mobile
  e.preventDefault();
  
  // Store the event for later use
  deferredPrompt = e;
  
  // Show the install button if it exists
  installButton = document.getElementById('pwaInstallBtn');
  if (installButton) {
    installButton.style.display = 'block';
    
    // Add click handler for the install button
    installButton.addEventListener('click', async () => {
      if (!deferredPrompt) {
        return;
      }
      
      // Show the install prompt
      deferredPrompt.prompt();
      
      // Wait for the user to respond to the prompt
      const { outcome } = await deferredPrompt.userChoice;
      console.log(`User response to the install prompt: ${outcome}`);
      
      // Clear the deferredPrompt variable
      deferredPrompt = null;
      
      // Hide the install button
      installButton.style.display = 'none';
    });
  }
});

// Detect when the PWA has been successfully installed
window.addEventListener('appinstalled', () => {
  console.log('Admin PWA installed successfully');
  
  // Hide install button
  if (installButton) {
    installButton.style.display = 'none';
  }
  
  // Clear the deferredPrompt
  deferredPrompt = null;
  
  // Optional: Show a success message
  const successMsg = document.createElement('div');
  successMsg.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
  successMsg.style.zIndex = '9999';
  successMsg.innerHTML = `
    <i class="fas fa-check-circle me-2"></i>Admin app installed successfully!
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  `;
  document.body.appendChild(successMsg);
  
  // Auto-dismiss after 5 seconds
  setTimeout(() => {
    successMsg.remove();
  }, 5000);
});
