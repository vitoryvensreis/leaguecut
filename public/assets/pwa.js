'use strict';
(() => {
  const button = document.querySelector('[data-pwa-install]');
  const dialog = document.getElementById('lc-pwa-install-help');
  const instructions = dialog?.querySelector('[data-pwa-instructions]');
  const status = dialog?.querySelector('[data-pwa-status]');
  let pendingPrompt = null;
  let registrationError = false;

  const isInstalled = () =>
    window.matchMedia?.('(display-mode: standalone)').matches ||
    window.navigator.standalone === true;
  const isIOS = /iPad|iPhone|iPod/i.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isAndroid = /Android/i.test(navigator.userAgent);

  const refresh = () => {
    if (button) button.hidden = isInstalled() || !window.isSecureContext;
  };
  const showHelp = () => {
    if (!dialog) return;
    if (instructions) {
      instructions.textContent = isIOS
        ? 'No Safari, toque em Compartilhar e escolha “Adicionar à Tela de Início”. Se estiver usando outro navegador, abra o LeagueCut no Safari.'
        : isAndroid
          ? 'No Chrome, abra o menu ⋮ e escolha “Instalar app” ou “Adicionar à tela inicial”.'
          : 'No Chrome ou Edge, abra o menu ⋮ e escolha “Instalar LeagueCut” ou “Instalar esta página como aplicativo”.';
    }
    if (status) {
      status.textContent = registrationError
        ? 'O navegador não conseguiu registrar o aplicativo. Atualize a página e confira se o site está liberado no bloqueador ou no Cloudflare.'
        : 'Se a opção não aparecer, atualize a página e verifique se este navegador permite instalar sites como aplicativos.';
    }
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else window.alert(instructions?.textContent || 'Abra o menu do navegador e procure Instalar aplicativo.');
  };

  // On iOS/unsupported browsers Chrome's beforeinstallprompt never fires:
  // keep a useful manual install action visible instead of a dead hidden button.
  refresh();
  dialog?.querySelector('[data-pwa-close]')?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('click', event => {
    if (event.target === dialog) dialog.close();
  });
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    pendingPrompt = event;
    refresh();
  });
  window.addEventListener('appinstalled', () => {
    pendingPrompt = null;
    refresh();
    if (dialog?.open) dialog.close();
  });

  button?.addEventListener('click', async () => {
    if (!pendingPrompt) return showHelp();
    const event = pendingPrompt;
    pendingPrompt = null;
    try {
      await event.prompt();
      const choice = await event.userChoice;
      if (choice?.outcome !== 'accepted') refresh();
    } catch (error) {
      console.warn('LeagueCut PWA: browser install prompt unavailable', error);
      showHelp();
    }
  });

  if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
  const register = async () => {
    try {
      await navigator.serviceWorker.register('/pwa/worker', {scope: '/', updateViaCache: 'none'});
    } catch (error) {
      registrationError = true;
      console.warn('LeagueCut PWA: registration failed', error);
    }
  };
  if (document.readyState === 'complete') void register();
  else window.addEventListener('load', register, {once: true});
})();
