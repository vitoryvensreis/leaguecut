'use strict';
(() => {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js', {scope: '/'}).catch(() => {}), {once:true});
  const install = document.querySelector('[data-pwa-install]');
  if (!install) return;
  let pending = null;
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    pending = event;
    install.hidden = false;
  });
  install.addEventListener('click', async () => {
    if (!pending) return;
    const event = pending;
    pending = null;
    install.hidden = true;
    await event.prompt();
  });
  window.addEventListener('appinstalled', () => {pending=null;install.hidden=true;});
})();
