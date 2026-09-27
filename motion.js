(() => {
  'use strict';
  const preference = matchMedia('(prefers-reduced-motion: reduce)');
  // Hooks run only for visible, bounded UI. No per-product observers or RAF loop.
  window.profisportMotion = {
    catalog(container) {
      if (preference.matches) return;
      [...container.children].slice(0, 8).forEach((card, index) => {
        if (!card.animate || card.classList.contains('productSkeleton')) return;
        card.animate([{ opacity: .55, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
          { duration: 280, delay: index * 25, easing: 'cubic-bezier(.2,.7,.2,1)' });
      });
    },
    added() {
      document.querySelector('.psAddedToast')?.remove();
      const toast = document.createElement('div');
      toast.className = 'psAddedToast'; toast.setAttribute('role', 'status');
      toast.innerHTML = '<b aria-hidden="true">✓</b> Товар добавлен в корзину';
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 2200);
    },
    gallery(image) {
      const stage = image.closest('.galleryMain');
      if (!stage || stage.querySelector('.galleryHint')) return;
      image.draggable = false;
      const hint = document.createElement('div'); hint.className = 'galleryHint';
      hint.textContent = 'Интерактивное фото · увеличьте и перемещайте. Это не съёмка 360°.';
      const zoom = document.createElement('button'); zoom.type = 'button'; zoom.className = 'galleryZoom';
      zoom.textContent = 'Увеличить фото'; zoom.setAttribute('aria-pressed', 'false');
      stage.append(hint, zoom);
      let enlarged = false, drag = null, frame = 0, point;
      const reset = () => {
        cancelAnimationFrame(frame); frame = 0; drag = null;
        image.style.removeProperty('--view-x'); image.style.removeProperty('--view-y');
        image.style.removeProperty('--view-tilt');
        image.style.setProperty('--view-scale', enlarged ? '1.45' : '1');
      };
      zoom.onclick = () => { enlarged = !enlarged; zoom.textContent = enlarged ? 'Вернуть размер' : 'Увеличить фото';
        zoom.setAttribute('aria-pressed', String(enlarged)); reset();
        // Reduced motion still permits useful zoom; remove movement, not functionality.
      };
      image.addEventListener('pointerdown', event => {
        if (!enlarged || event.button !== 0) return;
        drag = event.pointerId; image.setPointerCapture(drag);
      });
      image.addEventListener('pointermove', event => {
        if ((preference.matches && drag !== event.pointerId) || (event.pointerType !== 'mouse' && drag !== event.pointerId)) return;
        point = { x: event.clientX, y: event.clientY };
        if (frame) return;
        frame = requestAnimationFrame(() => {
          frame = 0; const rect = stage.getBoundingClientRect();
          const x = Math.max(-.5, Math.min(.5, (point.x - rect.left) / rect.width - .5));
          const y = Math.max(-.5, Math.min(.5, (point.y - rect.top) / rect.height - .5));
          image.style.setProperty('--view-x', x * (enlarged ? -100 : 10) + 'px');
          image.style.setProperty('--view-y', y * (enlarged ? -65 : 6) + 'px');
          image.style.setProperty('--view-tilt', enlarged || preference.matches ? '0deg' : x * 4 + 'deg');
        });
      });
      const release = event => { if (image.hasPointerCapture(event.pointerId)) image.releasePointerCapture(event.pointerId); drag = null; };
      image.addEventListener('pointerup', release); image.addEventListener('pointercancel', release);
      image.addEventListener('pointerleave', () => { if (!drag) reset(); });
      image.addEventListener('load', reset);
      preference.addEventListener('change', () => { image.style.removeProperty('transform'); reset(); });
    }
  };
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
    const hero = document.querySelector?.('#heroSlider');
    if (hero) {
      if (!document.getElementById('psHeroReducedMotionStyle')) {
        const style = document.createElement('style');
        style.id = 'psHeroReducedMotionStyle';
        style.textContent = `
@keyframes ps-hero-reduced-ride-in{from{opacity:.66;transform:translate3d(86px,0,0) scale(1.045)}to{opacity:1;transform:none}}
@keyframes ps-hero-reduced-ride-out{to{opacity:0;transform:translate3d(-52px,0,0) scale(.99)}}
@keyframes ps-hero-reduced-copy{from{opacity:0;transform:translate3d(0,12px,0)}to{opacity:1;transform:none}}
@media(prefers-reduced-motion:reduce){
 .motion-page #heroSlider .heroSlide.is-leaving{display:grid!important;animation:energy-quiet-in 440ms ease-out reverse both!important}
 .motion-page #heroSlider .heroSlide.is-leaving .heroPhoto{animation:ps-hero-reduced-ride-out 440ms ease-out both!important}
 .motion-page #heroSlider .heroSlide.is-active .heroPhoto{animation:ps-hero-reduced-ride-in 820ms cubic-bezier(.2,.7,.2,1) 80ms both!important}
 .motion-page #heroSlider .heroSlide.is-active .heroCopy> :is(small,h1,p,a){animation:ps-hero-reduced-copy 420ms ease-out both!important}
 .motion-page #heroSlider .heroSlide.is-active .heroCopy small{animation-delay:260ms!important}
 .motion-page #heroSlider .heroSlide.is-active .heroCopy h1{animation-delay:340ms!important}
 .motion-page #heroSlider .heroSlide.is-active .heroCopy p{animation-delay:420ms!important}
 .motion-page #heroSlider .heroSlide.is-active .heroCopy a{animation-delay:500ms!important}
}`;
        document.head.append(style);
      }
      const slides = [...hero.querySelectorAll('.heroSlide')];
      let active = slides.find(slide => slide.getAttribute('aria-hidden') === 'false' || slide.classList.contains('is-active')) || slides[0];
      let exitTimer = 0;
      const syncHeroExit = () => {
        if (!preference.matches || !slides.length) return;
        const next = slides.find(slide => slide.getAttribute('aria-hidden') === 'false' || slide.classList.contains('is-active')) || active;
        if (!next || next === active) return;
        const previous = active;
        active = next;
        clearTimeout(exitTimer);
        previous.classList.add('is-leaving');
        exitTimer = setTimeout(() => previous.classList.remove('is-leaving'), 440);
      };
      const slideObserver = new MutationObserver(syncHeroExit);
      slides.forEach(slide => slideObserver.observe(slide, { attributes: true, attributeFilter: ['class', 'aria-hidden'] }));
      preference.addEventListener('change', () => {
        slides.forEach(slide => slide.classList.remove('is-leaving'));
        active = slides.find(slide => slide.getAttribute('aria-hidden') === 'false' || slide.classList.contains('is-active')) || slides[0];
      });
    }
    if (hero && matchMedia('(hover: hover) and (pointer: fine)').matches) {
      let frame = 0, pointer;
      hero.addEventListener('pointermove', event => {
        if (preference.matches || event.pointerType !== 'mouse') return;
        pointer = { x: event.clientX, y: event.clientY };
        if (frame) return;
        frame = requestAnimationFrame(() => {
          frame = 0; const rect = hero.getBoundingClientRect();
          hero.style.setProperty('--hero-x', ((pointer.x - rect.left) / rect.width - .5) * 10 + 'px');
          hero.style.setProperty('--hero-y', ((pointer.y - rect.top) / rect.height - .5) * 6 + 'px');
        });
      });
      const reset = () => { cancelAnimationFrame(frame); frame = 0; hero.style.removeProperty('--hero-x'); hero.style.removeProperty('--hero-y'); };
      hero.addEventListener('pointerleave', reset); preference.addEventListener('change', reset);
    }
    if (preference.matches || !('IntersectionObserver' in window)) return;
    try {
      observer = new IntersectionObserver(entries => {
        entries.forEach(entry => { if (entry.isIntersecting) reveal(entry.target); });
      }, { rootMargin: '0px 0px -64px 0px', threshold: 0 });
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
