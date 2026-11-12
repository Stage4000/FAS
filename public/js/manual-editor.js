(() => {
  const form = document.querySelector('[data-manual-editor]');
  if (!form) return;
  const state = () => [...new FormData(form)].filter(([k]) => k !== 'password').map(([k,v]) => [k, v instanceof File ? `${v.name}:${v.size}:${v.lastModified}` : v]);
  const original = JSON.stringify(state());
  let submitting = false;
  window.addEventListener('beforeunload', event => {
    if (!submitting && (form.dataset.unsaved === 'true' || JSON.stringify(state()) !== original)) { event.preventDefault(); event.returnValue = ''; }
  });
  form.addEventListener('submit', () => { submitting = true; });
})();
