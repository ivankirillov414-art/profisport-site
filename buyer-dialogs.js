(() => {
  'use strict';
  if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return;
  const details = document.querySelector('.buyerDetails');
  if (!details) return;
  const dialogs = new Map();
  let opener = null;
  let syncing = false;
  for (const section of details.querySelectorAll('.hubCard[id]')) {
    const key = section.id;
    const dialog = document.createElement('dialog');
    dialog.className = 'buyerDialog';
    dialog.id = `dialog-${key}`;
    const title = section.querySelector('h2');
    title.id = `title-${key}`;
    dialog.setAttribute('aria-labelledby', title.id);
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'buyerDialogClose';
    close.setAttribute('aria-label', 'Закрыть окно');
    close.textContent = '×';
    close.addEventListener('click', () => dialog.close());
    dialog.append(close, section);
    document.body.append(dialog);
    dialog.addEventListener('click', event => {
      if (event.target !== dialog) return;
      const r = dialog.getBoundingClientRect();
      if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => {
      if (syncing || [...dialogs.values()].some(d => d.open)) return;
      document.documentElement.classList.remove('buyerDialogOpen');
      if (location.hash === `#${key}`) history.replaceState(null, '', location.pathname + location.search);
      if (opener?.isConnected) opener.focus({preventScroll:true});
    });
    dialogs.set(key, dialog);
  }
  details.hidden = true;
  function showFromHash() {
    const target = dialogs.get(location.hash.slice(1));
    syncing = true;
    for (const dialog of dialogs.values()) if (dialog.open && dialog !== target) dialog.close();
    if (target && !target.open) {
      target.showModal();
      target.scrollTop = 0;
    }
    document.documentElement.classList.toggle('buyerDialogOpen', !!target);
    syncing = false;
  }
  document.addEventListener('click', event => {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[href]');
    if (!link || (link.target && link.target !== '_self')) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || url.pathname !== location.pathname || !dialogs.has(url.hash.slice(1))) return;
    event.preventDefault();
    opener = link;
    if (location.hash !== url.hash) history.pushState(null, '', url.hash);
    showFromHash();
  });
  for (const link of document.querySelectorAll('.buyerP5 nav a')) {
    const key = new URL(link.href).hash.slice(1);
    if (dialogs.has(key)) { link.setAttribute('aria-haspopup', 'dialog'); link.setAttribute('aria-controls', `dialog-${key}`); }
  }
  window.addEventListener('hashchange', showFromHash);
  window.addEventListener('popstate', showFromHash);
  showFromHash();
})();
