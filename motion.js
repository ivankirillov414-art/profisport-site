(() => {
  'use strict';
  const preference = matchMedia('(prefers-reduced-motion: reduce)');
  const pending = new Set();
  let observer;
  const reveal = (element, animate = true) => {
    observer?.unobserve(element);
    pending.delete(element);
    element.classList.remove('motion-pending');
    if (animate && !preference.matches) {
      element.classList.add('motion-enter');
      const finish = event => {
        if (event.target !== element) return;
        element.classList.remove('motion-enter');
        element.removeEventListener('animationend', finish);
      };
      element.addEventListener('animationend', finish);
    }
  };
  const revealAll = () => {
    pending.forEach(element => reveal(element, false));
    document.querySelectorAll('.motion-enter').forEach(element => element.classList.remove('motion-enter'));
    observer?.disconnect();
  };
  const revealTarget = () => {
    let target;
    try { target = document.getElementById(decodeURIComponent(location.hash.slice(1))); } catch { return; }
    if (target) pending.forEach(element => {
      if (element === target || element.contains(target) || target.contains(element)) reveal(element, false);
    });
  };
  const init = () => {
    if (preference.matches || !('IntersectionObserver' in window)) return;
    try {
      observer = new IntersectionObserver(entries => {
        entries.forEach(entry => { if (entry.isIntersecting) reveal(entry.target); });
      }, { rootMargin: '0px 0px 40px 0px', threshold: 0 });
      // Explicitly bounded marketing sections only: never observe the catalog,
      // its replacement cards, map iframe, dialogs or an entire document subtree.
      const targets = document.querySelectorAll('[data-motion], .fade-up, .fade-in, .scale-in, .slide-in, .categorySection > .sectionHead, #categoryTiles, #picker, #service, .locationCard, .footerSection, .shopM2Card, .photoHubCard, .hubContact');
      targets.forEach(element => {
        if (element.hidden || !element.getClientRects().length || element.contains(document.activeElement)) return;
        const rect = element.getBoundingClientRect();
        // Keep first paint/LCP and previously passed content visible.
        if (rect.top < innerHeight) return;
        element.classList.add('motion-pending');
        pending.add(element);
        observer.observe(element);
      });
      revealTarget();
    } catch { revealAll(); }
  };
  // Focus and anchor destinations must be available immediately.
  document.addEventListener('focusin', event => {
    pending.forEach(element => { if (element.contains(event.target)) reveal(element, false); });
  });
  window.addEventListener('hashchange', revealTarget);
  window.addEventListener('pageshow', event => { if (event.persisted) revealAll(); });
  preference.addEventListener('change', () => { if (preference.matches) revealAll(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
