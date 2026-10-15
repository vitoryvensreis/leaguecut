(() => {
  'use strict';
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce || !('IntersectionObserver' in window)) return;
  const targets = document.querySelectorAll('.lp-feature, .campaign-card, .ws-kpi, .ws-rank-panel, .creator-plan-card, .lc-auto-stat, .lc-auto-rule');
  if (!targets.length) return;
  const observer = new IntersectionObserver(entries => {
    for (const item of entries) {
      if (!item.isIntersecting) continue;
      item.target.classList.add('lc-inview');
      observer.unobserve(item.target);
    }
  }, { threshold: 0.07, rootMargin: '0px 0px 65px 0px' });
  for (const item of targets) {
    if (item.getBoundingClientRect().top < window.innerHeight * 1.1) continue;
    item.classList.add('lc-reveal');
    observer.observe(item);
  }
  document.documentElement.classList.add('lc-motion-ready');
})();
