(() => {
  const input = document.getElementById('campaign-cover-upload');
  const image = document.getElementById('campaign-cover-preview');
  if (!input || !image) return;
  let objectUrl = null;
  const original = image.getAttribute('src') || '';
  input.addEventListener('change', () => {
    if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
    const file = input.files?.[0];
    if (!file) {
      image.src = original; image.hidden = !original;
      input.setCustomValidity(''); return;
    }
    const supported = ['image/jpeg', 'image/png', 'image/webp'];
    if (!supported.includes(file.type) || file.size > 6 * 1024 * 1024) {
      input.setCustomValidity('Envie uma imagem JPG, PNG ou WebP de até 6 MB.');
      input.reportValidity(); image.hidden = true; return;
    }
    input.setCustomValidity('');
    objectUrl = URL.createObjectURL(file);
    image.src = objectUrl; image.hidden = false;
  });
  window.addEventListener('pagehide', () => { if (objectUrl) URL.revokeObjectURL(objectUrl); });
})();
