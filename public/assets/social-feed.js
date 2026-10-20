/* Native embedding only after a visitor opts in to an external player. */
(() => {
  'use strict';
  const valid = {
    instagram: /^https:\/\/www\.instagram\.com\/(?:reel|p|tv)\/[A-Za-z0-9_-]{5,80}\/embed\/$/,
    tiktok: /^https:\/\/www\.tiktok\.com\/embed\/v2\/[0-9]{10,25}$/
  };
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-feed-play]');
    if (!button) return;
    const parent = button.closest('[data-feed-player]');
    if (!parent || parent.dataset.loaded === '1') return;
    const network = parent.dataset.network;
    const url = parent.dataset.embed || '';
    if (!valid[network]?.test(url)) return;
    parent.dataset.loaded = '1';
    const frame = document.createElement('iframe');
    frame.className = 'lc-feed-iframe';
    frame.title = 'Publicação do ' + (network === 'instagram' ? 'Instagram' : 'TikTok');
    frame.src = url;
    frame.loading = 'lazy';
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.allow = 'encrypted-media; fullscreen; picture-in-picture';
    frame.allowFullscreen = true;
    parent.replaceChildren(frame);
  });
})();

/* Progressive infinite scroll: regular pagination remains usable without JS. */
(() => {
  'use strict';
  const timeline = document.querySelector('#lc-feed-timeline');
  const pages = document.querySelector('[data-feed-pagination]');
  const sentinel = document.querySelector('[data-feed-sentinel]');
  const loading = document.querySelector('[data-feed-loading]');
  if (!timeline || !pages || !sentinel || !('IntersectionObserver' in window)) return;
  let busy = false;
  let next = pages.querySelector('a:last-of-type');
  // On the last page the only anchor may be "Anteriores".
  const getNext = parent => [...parent.querySelectorAll('a')].find(a => a.textContent.includes('Próximas'));
  next = getNext(pages);
  const observer = new IntersectionObserver(async entries => {
    if (!entries.some(entry => entry.isIntersecting) || busy || !next) return;
    busy = true;
    loading.textContent = 'Carregando publicações…';
    const url = next.href;
    try {
      const response = await fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
      const incoming = doc.querySelector('#lc-feed-timeline');
      const nav = doc.querySelector('[data-feed-pagination]');
      if (!incoming || !nav) throw new Error('Página inválida');
      const cards = [...incoming.querySelectorAll('.lc-feed-card')];
      cards.forEach(card => timeline.appendChild(document.importNode(card, true)));
      next = getNext(nav);
      pages.replaceChildren(...[...nav.childNodes].map(n => document.importNode(n, true)));
      if (!next || cards.length === 0) {
        next = null;
        observer.disconnect();
        loading.textContent = 'Você chegou ao fim do feed.';
      } else loading.textContent = '';
    } catch (error) {
      loading.textContent = 'Não foi possível carregar mais publicações.';
      // Preserve and show the native pagination as a fallback.
      pages.hidden = false;
      observer.disconnect();
    } finally { busy = false; }
  }, {rootMargin:'650px 0px'});
  pages.hidden = true;
  if (next) observer.observe(sentinel);
  else loading.textContent = '';
})();
