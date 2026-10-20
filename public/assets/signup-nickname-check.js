/* Debounced UX hint only. PHP+MySQL UNIQUE constraint remain the final authority. */
(() => {
  'use strict';
  const input = document.getElementById('lc-signup-nickname');
  const status = document.getElementById('lc-nickname-status');
  if (!input || !status || !window.fetch) return;

  const forbidden = new Set(['admin','leaguecut','suporte','support','moderador','system','null','root','api','security']);
  let timer, controller, sequence = 0;
  function feedback(message, kind) {
    status.textContent = message;
    status.dataset.state = kind;
    input.setAttribute('aria-invalid', kind === 'invalid' || kind === 'taken' ? 'true' : 'false');
  }
  function validate() {
    const current = ++sequence;
    clearTimeout(timer);
    if (controller) controller.abort();
    const nickname = input.value.trim().toLowerCase();

    if (!nickname) {
      input.setCustomValidity('');
      feedback('O nickname é obrigatório.', 'invalid');
      return;
    }
    if (!/^[a-z][a-z0-9_]{2,23}$/.test(nickname) ||
        nickname.startsWith('lc_') || forbidden.has(nickname)) {
      input.setCustomValidity('Use de 3 a 24 caracteres, começando por letra, sem nomes reservados.');
      feedback(input.validationMessage, 'invalid');
      return;
    }
    input.setCustomValidity('');
    feedback('Verificando disponibilidade…', 'checking');
    timer = window.setTimeout(async () => {
      controller = new AbortController();
      try {
        const response = await fetch('/api/nicknames/disponibilidade?nickname=' + encodeURIComponent(nickname), {
          signal: controller.signal, credentials: 'same-origin', cache: 'no-store',
          headers: { 'Accept': 'application/json' }
        });
        if (!response.ok) throw new Error('Falha de rede');
        const data = await response.json();
        if (current !== sequence || input.value.trim().toLowerCase() !== nickname) return;
        if (!data.valid || !data.available) {
          const message = data.message || 'Esse nickname não está disponível.';
          input.setCustomValidity(message);
          feedback(message, data.valid ? 'taken' : 'invalid');
        } else {
          input.setCustomValidity('');
          feedback('@' + data.nickname + ' disponível!', 'available');
        }
      } catch (err) {
        if (current !== sequence || err.name === 'AbortError') return;
        input.setCustomValidity('');
        feedback('Não foi possível verificar agora. O cadastro fará a validação ao enviar.', 'offline');
      }
    }, 350);
  }
  input.addEventListener('input', validate);
  input.addEventListener('blur', () => {
    const normalized = input.value.trim().toLowerCase();
    if (normalized !== input.value) input.value = normalized;
    validate();
  });
})();
