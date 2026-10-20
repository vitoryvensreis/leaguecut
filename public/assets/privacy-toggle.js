(() => {
  'use strict';
  const root = document.documentElement;
  const main = document.getElementById('conteudo');
  const button=document.getElementById('lc-privacy-toggle');
  if(!button || !main) return;
  const use=button.querySelector('use');
  function update(revealed) {
    root.classList.toggle('lc-privacy-visible',revealed);
    if(use)use.setAttribute('href',revealed?'/assets/icons.svg#eye-off':'/assets/icons.svg#eye');
    button.title=revealed?'Ocultar dados':'Mostrar dados';
    button.setAttribute('aria-pressed',String(revealed));
    button.setAttribute('aria-label',revealed?'Ocultar dados desta página':'Mostrar dados ocultos nesta página');
    main.querySelectorAll('[data-lc-private]').forEach(el=>{
      el.setAttribute('aria-label',revealed?el.textContent.trim():'Dado oculto');
    });
  }
  // Persist only for this tab and user; never use a global cross-account key.
  const userId = root.getAttribute('data-lc-user-id') || 'guest';
  const storageKey = 'lc-private-visible-user-' + userId;
  let initiallyVisible = false;
  try { initiallyVisible = sessionStorage.getItem(storageKey) === '1'; } catch (_) {}
  update(initiallyVisible);
  button.addEventListener('click',()=>{
    const visible = !root.classList.contains('lc-privacy-visible');
    update(visible);
    try { sessionStorage.setItem(storageKey,visible?'1':'0'); } catch (_) {}
  });
})();
