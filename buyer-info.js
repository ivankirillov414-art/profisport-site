/* Native details works on click; add hover and keyboard dismissal on desktop. */
(() => {
  const menu = document.getElementById('buyerNav');
  if (!menu) return;
  const summary = menu.querySelector('summary');
  const close = () => { menu.open = false; };
  const closeCatalog = () => { if (typeof window.closeMega === 'function') window.closeMega(); };
  menu.addEventListener('mouseenter', () => {
    if (matchMedia('(hover:hover)').matches) { closeCatalog(); menu.open = true; }
  });
  menu.addEventListener('mouseleave', () => {
    if (!menu.contains(document.activeElement)) close();
  });
  summary.addEventListener('click', closeCatalog);
  menu.addEventListener('focusin', closeCatalog);
  menu.addEventListener('focusout', () => {
    setTimeout(() => { if (!menu.contains(document.activeElement)) close(); }, 0);
  });
  document.addEventListener('click', event => { if (!menu.contains(event.target)) close(); });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.open) { close(); summary.focus(); }
  });
  menu.parentElement.querySelectorAll(':scope > a').forEach(link => {
    link.addEventListener('mouseenter', close);
    link.addEventListener('focus', close);
  });
})();
