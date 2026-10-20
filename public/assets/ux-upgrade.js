/* LeagueCut: progressive-enhanced auth and Brazilian form masks */
(() => {
  'use strict';
  const routes = {'/entrar': 'lc-auth-login', '/cadastrar': 'lc-auth-signup', '/seguranca/verificar': 'lc-auth-mfa'};
  const dialogs = Object.values(routes).map(id => document.getElementById(id)).filter(Boolean);
  let modalNavigation = false;
  function activeDialog() { return dialogs.find(d => d.open); }
  function openAuth(path, push = false) {
    const id = routes[path], dialog = id && document.getElementById(id);
    if (!dialog) return false;
    const opened = activeDialog();
    if (opened && opened !== dialog) opened.close();
    if (!dialog.open) {
      if (typeof dialog.showModal === 'function') dialog.showModal();
      else dialog.setAttribute('open', '');
    }
    if (push && window.location.pathname !== path) {
      history.pushState({lcAuth: true}, '', path);
      modalNavigation = true;
    }
    const focus = dialog.querySelector('input[name="email"], input[name="mfa_code"]');
    if (focus) focus.focus({preventScroll: true});
    return true;
  }
  for (const dialog of dialogs) {
    if (dialog.hasAttribute('open')) { dialog.removeAttribute('open'); openAuth(dialog.id === 'lc-auth-login' ? '/entrar' : dialog.id === 'lc-auth-signup' ? '/cadastrar' : '/seguranca/verificar'); }
    dialog.querySelector('[data-auth-close]')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (e) => {
      if (e.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', () => {
      if (!activeDialog() && modalNavigation && routes[location.pathname]) {
        modalNavigation = false;
        history.back();
      }
    });
  }
  document.addEventListener('click', e => {
    const anchor = e.target.closest('a[href]');
    if (!anchor || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || anchor.target === '_blank') return;
    const url = new URL(anchor.href, location.href);
    if (url.origin === location.origin && routes[url.pathname] && !url.search) {
      e.preventDefault();
      openAuth(url.pathname, true);
    }
  });
  document.addEventListener('lc:auth:open', e=>{if(e.detail && routes[e.detail.path])openAuth(e.detail.path,Boolean(e.detail.push));});
  window.addEventListener('popstate', () => {
    const dialog = activeDialog();
    if (routes[location.pathname]) openAuth(location.pathname);
    else if (dialog) { modalNavigation = false; dialog.close(); }
  });
  const moneyNames = new Set(['payment_per_video', 'milestone_bonus', 'monthly_leader_bonus', 'monthly_price']);
  function applyPhone(el) {
    const digits = el.value.replace(/\D/g, '').slice(0, 11);
    if (digits.length <= 2) el.value = digits.length ? '(' + digits : '';
    else if (digits.length <= 6) el.value = '(' + digits.slice(0,2) + ') ' + digits.slice(2);
    else {
      const mid = digits.length > 10 ? 7 : 6;
      el.value = '(' + digits.slice(0,2) + ') ' + digits.slice(2,mid) + '-' + digits.slice(mid);
    }
  }
  function applyMoney(el) {
    const digits = el.value.replace(/\D/g, '').slice(0, 10);
    if (!digits) { el.value = ''; return; }
    const cents = digits.padStart(3, '0');
    el.value = String(Number(cents.slice(0,-2))) + ',' + cents.slice(-2);
  }
  for (const el of document.querySelectorAll('input')) {
    const name = el.name.toLowerCase();
    if (name === 'whatsapp' || name === 'telefone' || name === 'phone') {
      el.setAttribute('inputmode', 'tel');
      el.setAttribute('autocomplete', 'tel');
      el.setAttribute('maxlength', '16');
      el.dataset.mask = 'phone';
    } else if (moneyNames.has(name)) {
      el.setAttribute('inputmode', 'numeric');
      el.dataset.mask = 'money';
    }
  }
  document.addEventListener('input', e => {
    const el = e.target;
    if (!(el instanceof HTMLInputElement)) return;
    if (el.dataset.mask === 'phone') applyPhone(el);
    else if (el.dataset.mask === 'money') applyMoney(el);
  });
})();
