(() => {
  'use strict';
  const range = document.querySelector('#home-overlay');
  const output = document.querySelector('#home-overlay-value');
  if (range && output) {
    range.addEventListener('input', () => { output.textContent = range.value + '%'; });
  }
  const select = document.querySelector('#home-media-type');
  const input = document.querySelector('#home-media-upload');
  if (select && input) {
    const sync = () => {
      input.disabled = select.value === 'default';
      input.accept = select.value === 'video'
        ? '.mp4,.webm,video/mp4,video/webm'
        : '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp';
      if (input.disabled) input.value = '';
    };
    select.addEventListener('change', sync);
    sync();
  }
})();
