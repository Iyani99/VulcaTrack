/* Landing-only, once-per-element reveals. Content is visible without JavaScript. */
(() => {
  const rescueCta = document.querySelector('.lp-btn--rescue');
  if (rescueCta) {
    rescueCta.addEventListener('pointerdown', () => rescueCta.classList.add('is-pressed'));
    ['pointerup', 'pointercancel', 'pointerleave', 'blur'].forEach(type => {
      rescueCta.addEventListener(type, () => rescueCta.classList.remove('is-pressed'));
    });
  }

  const elements = [...document.querySelectorAll('[data-lp-reveal]')];
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (!elements.length || reducedMotion.matches || !('IntersectionObserver' in window)) return;

  let observer;
  const showAll = () => {
    if (observer) observer.disconnect();
    elements.forEach(element => {
      element.classList.remove('lp-reveal-pending');
      element.classList.add('is-visible');
    });
  };

  try {
    observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
    elements.forEach(element => {
      element.classList.add('lp-reveal-pending');
      observer.observe(element);
    });
    reducedMotion.addEventListener('change', event => {
      if (event.matches) showAll();
    });
  } catch (error) {
    showAll();
  }
})();
