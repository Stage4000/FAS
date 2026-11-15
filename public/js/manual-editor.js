(() => {
  const form = document.querySelector('[data-manual-editor]');
  if (!form) return;
  const state = () => [...new FormData(form)].filter(([k]) => k !== 'password').map(([k,v]) => [k, v instanceof File ? `${v.name}:${v.size}:${v.lastModified}` : v]);
  const original = JSON.stringify(state());
  let submitting = false;
  const upload = form.querySelector('input[type="file"][name="pdf"]');
  const maximum = form.querySelector('[name="MAX_FILE_SIZE"]');
  const feedback = form.querySelector('[data-upload-status]');
  const validateUpload = () => {
    if (!upload) return true;
    const file = upload.files && upload.files[0];
    const limit = Number(maximum && maximum.value);
    let message = '';
    if (file) {
      if (!Number.isSafeInteger(limit) || limit <= 0) message = 'Uploads are unavailable at the current server limit. Keep your text and ask the site operator to check it.';
      else if (file.size === 0) message = 'This file is empty. Choose a PDF with content; your text will stay here.';
      else if (file.size > limit) message = `This file exceeds the current ${(limit / 1048576).toFixed(2)} MiB upload limit. Choose a smaller PDF; your text will stay here.`;
    }
    // Advisory preflight only. The server independently validates all uploaded bytes.
    upload.setCustomValidity(message);
    if (feedback) feedback.textContent = message;
    return !message;
  };
  if (upload) upload.addEventListener('change', validateUpload);
  window.addEventListener('pageshow', () => { submitting = false; validateUpload(); });
  validateUpload();
  window.addEventListener('beforeunload', event => {
    if (!submitting && (form.dataset.unsaved === 'true' || JSON.stringify(state()) !== original)) { event.preventDefault(); event.returnValue = ''; }
  });
  form.addEventListener('submit', event => {
    if (!validateUpload()) { event.preventDefault(); upload.reportValidity(); return; }
    if (!event.defaultPrevented) submitting = true;
  });
})();
