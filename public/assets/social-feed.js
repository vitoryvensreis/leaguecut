/* Instagram's official script sizes its own embed to the post content.
   Run it again when the endless timeline appends more posts. */
(() => {
  'use strict';
  let loaded = false;
  const refresh = () => {
    if (!document.querySelector('.lc-feed-embed-instagram .instagram-media')) return;
    if (window.instgrm?.Embeds?.process) {
      window.instgrm.Embeds.process();
      return;
    }
    if (loaded) return;
    loaded = true;
    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.instagram.com/embed.js';
    script.addEventListener('load', () => window.instgrm?.Embeds?.process?.());
    document.head.appendChild(script);
  };
  window.lcFeedRefreshEmbeds = refresh;
  refresh();
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
      window.lcFeedRefreshEmbeds?.();
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
