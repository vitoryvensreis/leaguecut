(() => {
  'use strict';
  const video = document.querySelector('.lp-hero-media video');
  if (!video) return;
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const sync = () => {
    if (motion.matches || document.hidden) {
      video.pause();
    } else {
      const attempt = video.play();
      if (attempt && typeof attempt.catch === 'function') attempt.catch(() => {});
    }
  };
  if (motion.addEventListener) motion.addEventListener('change', sync);
  document.addEventListener('visibilitychange', sync);
  sync();
})();
