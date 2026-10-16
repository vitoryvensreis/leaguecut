(() => {
  'use strict';
  const script = document.querySelector('script[src^="/assets/realtime.js"]');
  const csrf = document.querySelector('meta[name="lc-csrf"]')?.content;
  const feed = document.querySelector('[data-realtime-feed]');
  if (!script || !csrf || !feed) return;

  const liveFields = document.querySelectorAll('[data-live]');
  const badge = document.querySelector('[data-realtime-unread]');
  const stateLabels = document.querySelectorAll('[data-realtime-connection]');
  const opener = document.querySelector('#rt-notifications');
  const toast = document.querySelector('[data-realtime-toast]');
  const wsEnabled = script.dataset.rtEnabled === '1';
  const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  const pretty = new Intl.NumberFormat('pt-BR');
  const explanations = {
    'creator.registered': 'Novo cadastro de clipador',
    'creator.approved': 'Seu cadastro foi aprovado',
    'creator.reviewed': 'Cadastro analisado',
    'clip.submitted': 'Novo clipe enviado para análise',
    'clip.reviewed': 'Atualização na revisão de um clipe',
    'withdrawal.requested': 'Nova solicitação de saque',
    'withdrawal.updated': 'Status de saque atualizado',
    'campaign.updated': 'Campanha atualizada'
  };
  let lastCursor = 0;
  let initialized = false;
  let unread = 0;
  let socket = null;
  let retries = 0;
  let stopped = false;
  let busy = false;
  let refreshTimeout = 0;
  let toastTimeout = 0;
  const seen = new Set();
  const notifications = [];

  function setConnection(text) { for (const label of stateLabels) label.textContent = text; }
  function updateBadge() {
    if (!badge) return;
    badge.textContent = unread > 9 ? '9+' : String(unread);
    badge.hidden = !unread;
  }
  opener?.addEventListener('toggle', () => {
    if (opener.open) { unread = 0; updateBadge(); }
  });
  function renderFeed() {
    const fragment = document.createDocumentFragment();
    if (!notifications.length) {
      const empty = document.createElement('p');
      empty.textContent = 'Nenhuma atualização recente.';
      fragment.append(empty);
    } else {
      for (const entry of notifications.slice(0, 9)) {
        const el = document.createElement('div');
        el.className = 'rt-notification';
        const line = document.createElement('strong');
        line.textContent = explanations[entry.type] || 'Atualização da plataforma';
        const at = document.createElement('span');
        at.textContent = entry.created_at ? entry.created_at.replace('T', ' ').slice(0, 16) : 'Agora';
        el.append(line, at);
        fragment.append(el);
      }
    }
    feed.replaceChildren(fragment);
  }
  function toastMessage(message) {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('is-visible');
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => toast.classList.remove('is-visible'), 4500);
  }
  function formatField(key, value) {
    if (key === 'available_cents') return currency.format((Number(value) || 0) / 100);
    return pretty.format(Number(value) || 0);
  }
  function applyState(state) {
    if (!state || typeof state !== 'object') return;
    for (const node of liveFields) {
      const key = node.getAttribute('data-live');
      if (!Object.prototype.hasOwnProperty.call(state, key)) continue;
      const next = formatField(key, state[key]);
      if (node.textContent !== next) {
        node.textContent = next;
        node.classList.remove('rt-changed');
        void node.offsetWidth;
        node.classList.add('rt-changed');
      }
    }
  }
  function appendEvents(events, announce) {
    if (!Array.isArray(events)) return;
    for (const entry of events) {
      const id = Number(entry?.id) || 0;
      if (!id || seen.has(id)) continue;
      seen.add(id);
      notifications.unshift(entry);
      if (announce) {
        if (!opener?.open) unread++;
        toastMessage(explanations[entry.type] || 'Uma informação foi atualizada');
        window.dispatchEvent(new CustomEvent('leaguecut:live-event',{detail:{type:entry.type}}));
      }
    }
    if (seen.size > 250) {
      const recent = [...seen].slice(-150);
      seen.clear();
      for (const id of recent) seen.add(id);
    }
    notifications.splice(9);
    updateBadge();
    renderFeed();
  }
  async function refresh() {
    if (busy || stopped || document.hidden) return;
    busy = true;
    try {
      const response = await fetch('/api/realtime/snapshot?since=' + encodeURIComponent(lastCursor), {
        credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }
      });
      if (response.status === 401 || response.status === 403) {
        stopped = true;
        socket?.close();
        setConnection('Sessão encerrada');
        return;
      }
      if (!response.ok) throw new Error('Realtime indisponível');
      const payload = await response.json();
      applyState(payload.state);
      appendEvents(payload.events, initialized);
      lastCursor = Math.max(lastCursor, Number(payload.cursor) || 0);
      initialized = true;
      if (!socket || socket.readyState !== WebSocket.OPEN) setConnection('Atualização automática');
    } catch (_error) {
      setConnection('Reconectando');
    } finally {
      busy = false;
    }
  }
  function scheduleRefresh() {
    clearTimeout(refreshTimeout);
    refreshTimeout = setTimeout(refresh, 200);
  }
  async function connectWebSocket() {
    if (!wsEnabled || stopped || document.hidden) return;
    try {
      const response = await fetch('/api/realtime/token', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body: new URLSearchParams({ _csrf: csrf })
      });
      if (!response.ok) return;
      const auth = await response.json();
      if (stopped || document.hidden) return;
      const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
      const ws = new WebSocket(scheme + '//' + location.host + '/ws');
      socket = ws;
      ws.onopen = () => { ws.send(JSON.stringify({ type: 'auth', ticket: auth.ticket })); };
      ws.onmessage = (message) => {
        try {
          const data = JSON.parse(message.data);
          if (data.type === 'ready') { retries = 0; setConnection('Em tempo real'); scheduleRefresh(); }
          if (data.type === 'event') scheduleRefresh();
        } catch (_) {}
      };
      ws.onclose = () => {
        if (socket === ws) socket = null;
        if (stopped || document.hidden) return;
        setConnection('Atualização automática');
        retries = Math.min(retries + 1, 7);
        setTimeout(connectWebSocket, Math.min(60000, 1500 * (2 ** retries)));
      };
      ws.onerror = () => ws.close();
    } catch (_) {
      setConnection('Atualização automática');
      retries = Math.min(retries + 1, 7);
      setTimeout(connectWebSocket, Math.min(60000, 1500 * (2 ** retries)));
    }
  }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      socket?.close();
    } else {
      refresh();
      if (wsEnabled && (!socket || socket.readyState === WebSocket.CLOSED)) connectWebSocket();
    }
  });
  setConnection('Atualização automática');
  refresh();
  // Guaranteed fallback and reconciliation even when WS is active.
  setInterval(refresh, wsEnabled ? 25000 : 12000);
  if (wsEnabled) connectWebSocket();
})();
