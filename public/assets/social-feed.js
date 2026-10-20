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
