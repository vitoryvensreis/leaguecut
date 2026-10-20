(() => {
  'use strict';
  const root = document.documentElement;
  const main = document.getElementById('conteudo');
  if (!main || !main.querySelector('[data-lc-private], input[data-lc-private-input]')) {
    root.classList.remove('lc-privacy-ready');
    return;
  }
  const toolbar = document.createElement('div');
  toolbar.className = 'lc-privacy-toolbar';
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'lc-privacy-toggle btn btn-ghost btn-sm';
  button.setAttribute('aria-pressed', 'false');
  button.setAttribute('aria-label', 'Mostrar dados ocultos nesta página');
  const icon = document.createElementNS('http://www.w3.org/2000/svg','svg');
  icon.setAttribute('class', 'lc-icon');
  icon.setAttribute('aria-hidden', 'true');
  const use = document.createElementNS('http://www.w3.org/2000/svg','use');
  icon.append(use);
  const label = document.createElement('span');
  button.append(icon,label);
  toolbar.append(button);
  main.prepend(toolbar);
  function update(revealed) {
    root.classList.toggle('lc-privacy-visible',revealed);
    use.setAttribute('href',revealed?'/assets/icons.svg#eye-off':'/assets/icons.svg#eye');
    label.textContent=revealed?'Ocultar dados':'Mostrar dados';
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
