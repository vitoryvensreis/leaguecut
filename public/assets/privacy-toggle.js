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
  update(false);
  button.addEventListener('click',()=>update(!root.classList.contains('lc-privacy-visible')));
  // Never persist visibility across navigation or reuse it on another account.
  document.addEventListener('visibilitychange',()=>{if(document.hidden)update(false);});
  window.addEventListener('pagehide',()=>update(false));
})();
