/* LeagueCut form system: inline validation, password helpers, Pix by type, OTP cells,
   submit states. Progressive enhancement only — the PHP backend re-validates everything. */
(() => {
'use strict';

/* ---------- pure helpers (also exported for tests) ---------- */
const DDDS = new Set([11,12,13,14,15,16,17,18,19,21,22,24,27,28,31,32,33,34,35,37,38,41,42,43,44,45,46,47,48,49,
  51,53,54,55,61,62,63,64,65,66,67,68,69,71,73,74,75,77,79,81,82,83,84,85,86,87,88,89,91,92,93,94,95,96,97,98,99]);
const digits = v => String(v || '').replace(/\D+/g, '');
function cpfValid(v){
  const d = digits(v);
  if (d.length !== 11 || /^(\d)\1{10}$/.test(d)) return false;
  for (let t = 9; t < 11; t++) {
    let sum = 0;
    for (let i = 0; i < t; i++) sum += Number(d[i]) * ((t + 1) - i);
    if (Number(d[t]) !== ((10 * sum) % 11) % 10) return false;
  }
  return true;
}
function cnpjValid(v){
  const d = digits(v);
  if (d.length !== 14 || /^(\d)\1{13}$/.test(d)) return false;
  const weights = [[5,4,3,2,9,8,7,6,5,4,3,2],[6,5,4,3,2,9,8,7,6,5,4,3,2]];
  return weights.every((w, round) => {
    const sum = w.reduce((acc, weight, i) => acc + Number(d[i]) * weight, 0);
    const rest = sum % 11;
    return Number(d[12 + round]) === (rest < 2 ? 0 : 11 - rest);
  });
}
/** National phone digits or null (mirrors lc_phone_br_normalize). */
function phoneNormalize(v, mobileOnly){
  let d = digits(v);
  if (d.length >= 12 && d.startsWith('55')) d = d.slice(2);
  if (d.length === 12 && d[0] === '0') d = d.slice(1);
  if (d.length !== 10 && d.length !== 11) return null;
  if (!DDDS.has(Number(d.slice(0, 2)))) return null;
  const local = d.slice(2);
  if (local.length === 9) return local[0] === '9' ? d : null;
  if (mobileOnly) return null;
  return /^[2-5]/.test(local) ? d : null;
}
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const DOMAINS = ['gmail.com','hotmail.com','outlook.com','live.com','yahoo.com','yahoo.com.br','icloud.com','uol.com.br','bol.com.br','terra.com.br','proton.me','protonmail.com'];
function distance(a, b){
  const m = a.length, n = b.length, row = Array.from({length: n + 1}, (_, j) => j);
  for (let i = 1; i <= m; i++) {
    let prev = row[0]; row[0] = i;
    for (let j = 1; j <= n; j++) {
      const tmp = row[j];
      row[j] = Math.min(row[j] + 1, row[j - 1] + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
      prev = tmp;
    }
  }
  return row[n];
}
/** "joao@gmial.con" -> "joao@gmail.com"; null when no likely typo. */
function emailSuggestion(v){
  const value = String(v || '').trim().toLowerCase();
  const at = value.lastIndexOf('@');
  if (at < 1) return null;
  const domain = value.slice(at + 1);
  if (!domain || DOMAINS.includes(domain)) return null;
  let best = null, bestScore = 3;
  for (const candidate of DOMAINS) {
    const score = distance(domain, candidate);
    if (score > 0 && score < bestScore) { best = candidate; bestScore = score; }
  }
  return best ? value.slice(0, at + 1) + best : null;
}
function instagramNormalize(v){
  let s = String(v || '').trim();
  const m = s.match(/instagram\.com\/([A-Za-z0-9._]{1,30})/i);
  if (m) s = m[1];
  s = s.replace(/^@+/, '').toLowerCase();
  return s;
}
function httpsNormalize(v){
  const s = String(v || '').trim();
  if (!s) return '';
  return /^[a-z][a-z0-9+.-]*:\/\//i.test(s) ? s : 'https://' + s;
}
function formatCpf(d){ d = d.slice(0, 11); return d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2'); }
function formatCnpj(d){ d = d.slice(0, 14); return d.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d{1,2})$/, '$1-$2'); }
function formatPhone(d){
  if (d.startsWith('55') && d.length > 11) d = d.slice(2);
  d = d.slice(0, 11);
  if (d.length < 3) return d;
  const n = d.slice(2);
  return '(' + d.slice(0, 2) + ') ' + (n.length > 8 ? n.slice(0, 5) + (n.length > 5 ? '-' + n.slice(5) : '') : n.slice(0, 4) + (n.length > 4 ? '-' + n.slice(4) : ''));
}
function pixDetect(v){
  const s = String(v || '').trim();
  if (!s) return '';
  if (EMAIL_RE.test(s)) return 'EMAIL';
  if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(s)) return 'EVP';
  if (/^\+|\(/.test(s)) return 'PHONE';
  const d = digits(s);
  if (d.length === 14 && cnpjValid(d)) return 'CNPJ';
  if (d.length === 11 && cpfValid(d)) return 'CPF';
  if (phoneNormalize(s, true)) return 'PHONE';
  return '';
}
function pixError(type, v){
  const s = String(v || '').trim();
  if (!s) return '';
  switch (type) {
    case 'CPF': return cpfValid(s) ? '' : 'CPF inválido. Confira os 11 dígitos.';
    case 'CNPJ': return cnpjValid(s) ? '' : 'CNPJ inválido. Confira os 14 dígitos.';
    case 'EMAIL': return EMAIL_RE.test(s) && s.length <= 77 ? '' : 'E-mail inválido.';
    case 'PHONE': return phoneNormalize(s, true) ? '' : 'Celular inválido: DDD + número com 9 dígitos.';
    case 'EVP': return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(s) ? '' : 'Chave aleatória inválida (formato 0000aaaa-0000-...).';
    default: return 'Escolha o tipo da chave Pix.';
  }
}
function passwordScore(v){
  const s = String(v || '');
  if (!s) return 0;
  let score = 0;
  if (s.length >= 10) score++;
  if (s.length >= 14) score++;
  if (/[a-z]/.test(s) && /[A-Z]/.test(s)) score++;
  if (/\d/.test(s)) score++;
  if (/[^A-Za-z0-9]/.test(s)) score++;
  if (/^(.)\1+$/.test(s) || /^(?:123456|password|senha|qwerty|abc123|leaguecut)/i.test(s)) score = 1;
  if (s.length < 10) score = Math.min(score, 1);
  return Math.max(1, Math.min(4, score));
}
const api = {cpfValid, cnpjValid, phoneNormalize, emailSuggestion, instagramNormalize, httpsNormalize, pixDetect, pixError, passwordScore, formatCpf, formatCnpj, formatPhone};
if (typeof module === 'object' && module.exports) { module.exports = api; return; }
window.LCForm = api;

/* ---------- DOM ---------- */
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const fieldOf = input => input.closest('[data-ff]') || input.closest('.field');
function messageEl(input){
  const field = fieldOf(input);
  if (!field) return null;
  let el = field.querySelector('.lc-ff-msg');
  if (!el) {
    el = document.createElement('small');
    el.className = 'lc-ff-msg';
    el.id = (input.id || input.name || 'f') + '-msg-' + Math.random().toString(36).slice(2, 7);
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    field.appendChild(el);
    input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + el.id).trim());
  }
  return el;
}
function show(input, message, tone){
  const el = messageEl(input);
  const field = fieldOf(input);
  if (el && !el.hasAttribute('data-nickname-feedback')) {
    el.textContent = message || '';
    el.dataset.tone = message ? (tone || 'error') : '';
  }
  if (field) {
    field.classList.toggle('is-invalid', Boolean(message) && tone !== 'hint');
    field.classList.toggle('is-valid', !message && input.value.trim() !== '' && input.dataset.touched === '1');
  }
  if (message && tone !== 'hint') input.setAttribute('aria-invalid', 'true');
  else if (!input.hasAttribute('data-nickname-check')) input.removeAttribute('aria-invalid');
}
function nativeMessage(input){
  const v = input.validity;
  if (!v || v.valid) return '';
  if (v.valueMissing) return input.type === 'checkbox' ? 'Marque esta opção para continuar.' : 'Campo obrigatório.';
  if (v.typeMismatch && input.type === 'email') return 'E-mail inválido. Confira o endereço.';
  if (v.typeMismatch && input.type === 'url') return 'Link inválido.';
  if (v.tooShort) return 'Use pelo menos ' + input.minLength + ' caracteres.';
  if (v.tooLong) return 'Use no máximo ' + input.maxLength + ' caracteres.';
  if (v.rangeUnderflow) return 'O mínimo é ' + input.min + '.';
  if (v.rangeOverflow) return 'O máximo é ' + input.max + '.';
  if (v.stepMismatch || v.badInput) return 'Informe um número válido.';
  if (v.patternMismatch) return input.title || 'Formato inválido.';
  return 'Confira este campo.';
}
/** Returns an error string ('' when valid). Hints (e-mail typo) are shown but do not block. */
function check(input, final){
  if (input.disabled || input.type === 'hidden' || input.closest('[hidden]')) return '';
  const value = input.value.trim();
  const name = input.name || '';
  const rule = input.dataset.rule || '';
  if (rule === 'instagram' && value) input.value = instagramNormalize(value);
  if (rule === 'https' && value && final) input.value = httpsNormalize(value);
  let error = '';
  if (input.matches('[data-pix-input]')) {
    const group = input.closest('[data-pix-group]') || input.form;
    const type = group?.querySelector('[data-pix-type]')?.value || '';
    if (!value) error = input.required ? 'Informe a chave Pix.' : '';
    else error = pixError(type, value);
  } else if (input.dataset.lcMask === 'phone' || /^(whatsapp|telefone|phone)$/i.test(name)) {
    if (value && !phoneNormalize(value, false)) error = 'Número inválido. Use DDD + número, ex.: (85) 99999-9999.';
  } else if (input.dataset.lcMask === 'cpf' || /^cpf$/i.test(name)) {
    if (value && !cpfValid(value)) error = 'CPF inválido. Confira os dígitos.';
  } else if (input.dataset.lcMask === 'cnpj') {
    if (value && !cnpjValid(value)) error = 'CNPJ inválido. Confira os dígitos.';
  } else if (input.dataset.lcMask === 'document' || name === 'cpf_cnpj') {
    const d = digits(value);
    if (value && !((d.length === 11 && cpfValid(d)) || (d.length === 14 && cnpjValid(d)))) error = 'CPF ou CNPJ inválido.';
  } else if (rule === 'fullname') {
    if (value && !/\S+\s+\S+/.test(value)) error = 'Informe nome e sobrenome.';
  } else if (rule === 'instagram') {
    if (value && !/^[a-z0-9._]{1,30}$/.test(input.value)) error = 'Use só o @ do perfil: letras, números, ponto e _.';
  } else if (rule === 'https') {
    if (value) { try { const u = new URL(httpsNormalize(value)); if (u.protocol !== 'https:' || !u.hostname.includes('.')) error = 'Link inválido.'; } catch { error = 'Link inválido.'; } }
  }
  if (!error && input.dataset.maxCents && value) {
    const cents = Number(digits(value));
    if (cents > Number(input.dataset.maxCents)) error = 'Valor acima do disponível.';
  }
  if (!error && !input.hasAttribute('data-nickname-check')) error = nativeMessage(input);
  if (!error && input.type === 'email' && value && input.autocomplete !== 'username') {
    const suggestion = emailSuggestion(value);
    if (suggestion) { suggest(input, suggestion); return ''; }
  }
  if (input.hasAttribute('data-nickname-check')) { fieldOf(input)?.classList.toggle('is-invalid', input.getAttribute('aria-invalid') === 'true'); return input.getAttribute('aria-invalid') === 'true' ? 'nick' : (value ? '' : (input.required ? 'nick' : '')); }
  show(input, error);
  return error;
}
function suggest(input, suggestion){
  const el = messageEl(input);
  if (!el) return;
  el.textContent = 'Você quis dizer ';
  const b = document.createElement('button');
  b.type = 'button'; b.className = 'lc-ff-suggest'; b.textContent = suggestion + '?';
  b.addEventListener('click', () => { input.value = suggestion; show(input, ''); input.focus(); });
  el.appendChild(b);
  el.dataset.tone = 'hint';
  fieldOf(input)?.classList.remove('is-invalid');
}
document.addEventListener('focusout', e => {
  const t = e.target;
  if (!(t instanceof HTMLInputElement || t instanceof HTMLSelectElement || t instanceof HTMLTextAreaElement)) return;
  if (!t.form || t.type === 'password' && t.autocomplete === 'current-password') return;
  if (t.value.trim() === '' && !t.dataset.touched) return; // do not shout at untouched fields
  t.dataset.touched = '1';
  if (t instanceof HTMLInputElement) check(t, true);
  else if (t.required && !t.value) show(t, 'Selecione uma opção.'); else show(t, '');
});
document.addEventListener('input', e => {
  const t = e.target;
  if (!(t instanceof HTMLInputElement) || t.dataset.touched !== '1') return;
  // Re-check while correcting, but only clear errors (never add new ones mid-typing).
  const field = fieldOf(t);
  if (field?.classList.contains('is-invalid') && !check(t, false)) show(t, '');
});

/* Validate whole form before the mask script strips formatting (capture phase, registered first). */
document.addEventListener('submit', e => {
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-validate')) return;
  let first = null;
  $$('input,select,textarea', form).forEach(el => {
    el.dataset.touched = '1';
    let error = '';
    if (el instanceof HTMLInputElement) error = check(el, true);
    else if (el.required && !el.value) { error = 'Selecione uma opção.'; show(el, error); }
    if (error && !first) first = el;
  });
  if (first) {
    e.preventDefault();
    e.stopImmediatePropagation();
    first.focus({preventScroll: true});
    first.scrollIntoView({block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
  }
}, true);

/* ---------- password helpers ---------- */
const EYE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.9 10.9 0 0 1 12 5c6.4 0 10 7 10 7a17.4 17.4 0 0 1-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
function enhancePassword(input){
  if (input.dataset.pwReady) return;
  input.dataset.pwReady = '1';
  let control = input.parentElement;
  if (!control.classList.contains('lc-ff-control')) {
    const wrap = document.createElement('span');
    wrap.className = 'lc-ff-control lc-pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    control = wrap;
  }
  control.classList.add('has-toggle');
  const btn = document.createElement('button');
  btn.type = 'button'; btn.className = 'lc-pw-toggle';
  btn.setAttribute('aria-label', 'Mostrar senha'); btn.setAttribute('aria-pressed', 'false');
  btn.innerHTML = EYE;
  btn.addEventListener('click', () => {
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    btn.setAttribute('aria-pressed', String(visible));
    btn.setAttribute('aria-label', visible ? 'Ocultar senha' : 'Mostrar senha');
    btn.innerHTML = visible ? EYE_OFF : EYE;
    input.focus({preventScroll: true});
  });
  control.appendChild(btn);
  const field = fieldOf(input);
  const caps = document.createElement('small');
  caps.className = 'lc-caps'; caps.hidden = true; caps.textContent = 'Caps Lock ativado';
  control.after(caps);
  const capsCheck = e => { if (e.getModifierState) caps.hidden = !e.getModifierState('CapsLock'); };
  input.addEventListener('keydown', capsCheck); input.addEventListener('keyup', capsCheck);
  input.addEventListener('blur', () => { caps.hidden = true; });
  if (input.hasAttribute('data-strength') || (input.autocomplete === 'new-password' && field)) {
    const meter = document.createElement('div');
    meter.className = 'lc-strength'; meter.dataset.level = '0';
    meter.innerHTML = '<span></span><span></span><span></span><span></span><em aria-live="polite"></em>';
    caps.after(meter);
    const labels = ['', 'Fraca', 'Razoável', 'Boa', 'Forte'];
    input.addEventListener('input', () => {
      const level = passwordScore(input.value);
      meter.dataset.level = String(level);
      meter.querySelector('em').textContent = input.value ? 'Senha ' + labels[level].toLowerCase() : '';
    });
  }
}

/* ---------- Pix by type ---------- */
const PIX_UI = {
  CPF: {placeholder: '000.000.000-00', mode: 'numeric'},
  CNPJ: {placeholder: '00.000.000/0000-00', mode: 'numeric'},
  EMAIL: {placeholder: 'voce@exemplo.com', mode: 'email'},
  PHONE: {placeholder: '(85) 99999-9999', mode: 'tel'},
  EVP: {placeholder: '0000aaaa-00aa-00aa-00aa-000000aaaaaa', mode: 'text'},
};
function pixFormat(type, value){
  const d = digits(value);
  if (type === 'CPF') return formatCpf(d);
  if (type === 'CNPJ') return formatCnpj(d);
  if (type === 'PHONE') return formatPhone(d);
  if (type === 'EVP') return value.toLowerCase().replace(/[^0-9a-f-]/g, '').slice(0, 36);
  if (type === 'EMAIL') return value.trim();
  return value;
}
function enhancePix(group){
  const select = group.querySelector('[data-pix-type]');
  const input = group.querySelector('[data-pix-input]');
  if (!select || !input || input.dataset.pixReady) return;
  input.dataset.pixReady = '1';
  const apply = () => {
    const ui = PIX_UI[select.value];
    if (input.hasAttribute('data-pix-lock')) input.disabled = !ui;
    if (ui) { input.placeholder = ui.placeholder; input.inputMode = ui.mode; }
    else input.placeholder = input.hasAttribute('data-pix-lock') ? 'Escolha o tipo da chave primeiro' : 'CPF, CNPJ, e-mail, celular ou chave aleatória';
    if (input.value) input.value = pixFormat(select.value, input.value);
    if (input.dataset.touched === '1') check(input, true);
  };
  select.addEventListener('change', apply);
  input.addEventListener('input', () => {
    if (!select.value) {
      const guess = pixDetect(input.value);
      if (guess && (guess !== 'PHONE' || /^\+|\(/.test(input.value))) { select.value = guess; apply(); return; }
    }
    if (select.value && select.value !== 'EMAIL') {
      const pos = input.value.length;
      input.value = pixFormat(select.value, input.value);
      if (document.activeElement === input && pos === input.value.length) input.setSelectionRange(pos, pos);
    }
  });
  apply();
}

/* ---------- OTP cells ---------- */
function enhanceOtp(box){
  const input = box.querySelector('[data-otp-input]');
  const cells = $$('[data-otp-cells] span', box);
  if (!input || input.dataset.otpReady) return;
  input.dataset.otpReady = '1';
  box.classList.add('is-enhanced');
  const form = input.form;
  const toggle = form?.querySelector('[data-otp-recovery]');
  const desc = input.closest('dialog,.panel,form')?.parentElement?.querySelector('[data-otp-desc]');
  let recovery = false;
  const paint = () => {
    const d = recovery ? '' : digits(input.value).slice(0, 6);
    if (!recovery && input.value !== d) input.value = d;
    cells.forEach((cell, i) => {
      cell.textContent = d[i] || '';
      cell.classList.toggle('is-filled', Boolean(d[i]));
      cell.classList.toggle('is-active', document.activeElement === input && i === Math.min(d.length, 5));
    });
    if (!recovery && d.length === 6 && form && !form.dataset.otpSent) {
      form.dataset.otpSent = '1';
      setTimeout(() => { form.requestSubmit ? form.requestSubmit() : form.submit(); }, 120);
    }
    if (d.length < 6 && form) delete form.dataset.otpSent;
  };
  input.addEventListener('input', paint);
  input.addEventListener('focus', paint);
  input.addEventListener('blur', paint);
  form?.addEventListener('reset', () => setTimeout(paint));
  toggle?.addEventListener('click', () => {
    recovery = !recovery;
    box.classList.toggle('is-recovery', recovery);
    toggle.setAttribute('aria-pressed', String(recovery));
    toggle.textContent = recovery ? 'Usar o código do aplicativo' : 'Usar um código de recuperação';
    input.value = '';
    input.inputMode = recovery ? 'text' : 'numeric';
    input.placeholder = recovery ? 'Código de recuperação' : '000000';
    if (desc) desc.textContent = recovery ? 'Digite um dos códigos de recuperação que você salvou ao ativar o 2FA.' : 'Digite o código de 6 dígitos do seu aplicativo autenticador.';
    paint();
    input.focus();
  });
  paint();
}

/* ---------- campaign limit toggles ---------- */
function enhanceLimit(field){
  const input = field.querySelector('[data-limit-input]');
  const box = field.querySelector('[data-limit-unlimited]');
  if (!input || !box || field.dataset.limitReady) return;
  field.dataset.limitReady = '1';
  const sync = () => { input.disabled = box.checked; field.classList.toggle('is-unlimited', box.checked); if (!box.checked && !input.value) input.focus(); };
  box.addEventListener('change', sync);
  input.disabled = box.checked; field.classList.toggle('is-unlimited', box.checked);
}

/* ---------- submit feedback + confirmations ---------- */
document.addEventListener('click', e => {
  const btn = e.target instanceof Element ? e.target.closest('[data-confirm]') : null;
  if (btn && !window.confirm(btn.getAttribute('data-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); }
}, true);
document.addEventListener('submit', e => {
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-auth-async')) return;
  const button = e.submitter instanceof HTMLButtonElement ? e.submitter : form.querySelector('button[type="submit"],button:not([type])');
  setTimeout(() => {
    if (e.defaultPrevented || !button) return;
    button.classList.add('is-loading');
    button.setAttribute('aria-busy', 'true');
    if (button.dataset.loadingText) { button.dataset.originalHtml = button.innerHTML; button.textContent = button.dataset.loadingText; }
    button.disabled = true; // after the submit event: the clicked button's name/value is already in the request
  }, 0);
});
window.addEventListener('pageshow', () => {
  $$('button.is-loading').forEach(b => {
    b.classList.remove('is-loading'); b.removeAttribute('aria-busy'); b.disabled = false;
    if (b.dataset.originalHtml) b.innerHTML = b.dataset.originalHtml;
  });
});

/* Consistent mobile keyboards and no autocorrect on identifiers, across every legacy form. */
function enhanceAttributes(root){
  $$('input[name="mfa_code"]:not([data-otp-input]),input[name="code"]', root).forEach(i => {
    if (!i.hasAttribute('inputmode')) i.inputMode = 'numeric';
    i.autocomplete = 'one-time-code'; i.spellcheck = false; i.setAttribute('autocapitalize', 'none');
    if (!i.placeholder) i.placeholder = i.name === 'mfa_code' ? '000000 ou código de recuperação' : '000000';
    if (!i.maxLength || i.maxLength < 0) i.maxLength = 60;
  });
  $$('input[type="email"],input[name="email"],input[name="identifier"],input[name="username"],input[name="nickname"],input[name="instagram"],input[type="url"]', root).forEach(i => {
    i.spellcheck = false; i.setAttribute('autocapitalize', 'none'); i.setAttribute('autocorrect', 'off');
    if (i.type === 'email' && !i.hasAttribute('inputmode')) i.inputMode = 'email';
  });
  $$('input[type="tel"],input[data-lc-mask="phone"]', root).forEach(i => { if (!i.hasAttribute('inputmode')) i.inputMode = 'tel'; });
}
function enhance(root = document){
  enhanceAttributes(root);
  $$('input[type="password"]', root).forEach(enhancePassword);
  $$('[data-pix-group],form[data-pix-form]', root).forEach(enhancePix);
  $$('[data-otp]', root).forEach(enhanceOtp);
  $$('[data-limit-field]', root).forEach(enhanceLimit);
}
enhance();
new MutationObserver(list => list.forEach(m => m.addedNodes.forEach(n => { if (n instanceof Element) enhance(n); })))
  .observe(document.body, {childList: true, subtree: true});
})();
