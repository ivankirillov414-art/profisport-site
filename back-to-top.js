(() => {
  'use strict';
  if (document.getElementById('backToTop')) return;
  const button = document.createElement('button');
  button.id = 'backToTop';
  button.className = 'backToTop';
  button.type = 'button';
  button.hidden = true;
  button.setAttribute('aria-label', 'Наверх');
  button.title = 'Наверх';
  button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 12 7-7 7 7M12 5v14"/></svg>';
  document.body.appendChild(button);

  const update = () => { button.hidden = window.scrollY < 240; };
  button.addEventListener('click', () => {
    // Explicitly override the site's smooth anchor scrolling.
    window.scrollTo({ top: 0, left: window.scrollX, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
    update();
  });
  window.addEventListener('scroll', update, { passive: true });
  window.addEventListener('pageshow', update);
  update();
})();

(() => {
  'use strict';

  const directSectionLinks = new Map([
    ['shop.html#catalog', 'index.html#catalogProducts'],
    ['shop.html#picker', 'index.html#picker'],
    ['shop.html#account', 'profile.html'],
    ['shop.html#customer', 'index.html?register=qr'],
    ['workshop.html#works', 'service.html#serviceWorks'],
    ['workshop.html#guide', 'service.html#bikeGuide'],
    ['workshop.html#service', 'service.html']
  ]);

  const currentFile = () => {
    const file = location.pathname.split('/').pop();
    return file || 'index.html';
  };

  const normalizedHref = (value) => {
    if (!value || value.startsWith('http:') || value.startsWith('https:') || value.startsWith('tel:') || value.startsWith('mailto:')) return '';
    try {
      const url = new URL(value, location.href);
      if (url.origin !== location.origin) return '';
      const file = url.pathname.split('/').pop() || 'index.html';
      return `${file}${url.search}${url.hash}`;
    } catch {
      return '';
    }
  };

  const applyDirectSectionLinks = () => {
    document.querySelectorAll('a[href]').forEach((link) => {
      const direct = directSectionLinks.get(normalizedHref(link.getAttribute('href')));
      if (direct) link.setAttribute('href', direct);
    });
  };

  const injectPopupClosePolish = () => {
    if (document.getElementById('profisportPopupClosePolish')) return;
    const style = document.createElement('style');
    style.id = 'profisportPopupClosePolish';
    style.textContent = `
      dialog.customerRegister, dialog.storagePromo, dialog.partDialog,
      dialog.quickView, dialog.filterDialog {
        overflow: auto !important;
        max-height: calc(100dvh - 24px) !important;
        max-width: calc(100vw - 24px);
        overscroll-behavior: contain;
      }
      dialog.customerRegister {
        background: #fff !important;
        box-shadow: 0 28px 90px rgba(17, 24, 32, .25) !important;
      }
      dialog.customerRegister .customerRegisterInner {
        max-height: none !important;
        min-height: 0 !important;
        overflow: visible !important;
        padding-top: 68px !important;
        box-shadow: none !important;
      }
      dialog.storagePromo, dialog.partDialog, dialog.quickView { padding-top: 68px !important; }
      dialog.customerRegister .customerRegisterClose,
      dialog.storagePromo .storagePromoClose,
      dialog.partDialog .dialogClose,
      dialog.quickView .dialogClose,
      dialog.filterDialog .dialogClose {
        position: absolute !important;
        top: 12px !important;
        right: 12px !important;
        z-index: 10 !important;
        display: grid !important;
        place-items: center !important;
        width: 44px !important;
        height: 44px !important;
        min-width: 44px !important;
        min-height: 44px !important;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 50% !important;
        background: #f0f2f4 !important;
        box-shadow: none !important;
        color: #24282c !important;
        opacity: 1 !important;
        font: 400 28px/1 Arial, sans-serif !important;
        text-shadow: none !important;
      }
      dialog :is(.customerRegisterClose,.storagePromoClose,.dialogClose):focus-visible {
        outline: 3px solid #b18a00 !important;
        outline-offset: 3px !important;
      }
      @media (max-width: 620px) {
        dialog.customerRegister {
          width: 100% !important;
          max-width: 100% !important;
          max-height: 100dvh !important;
          border-radius: 0;
        }
        dialog.customerRegister .customerRegisterInner { padding-bottom: calc(28px + env(safe-area-inset-bottom)); }
      }
    `;
    document.head.appendChild(style);
  };

  const apply = () => {
    applyDirectSectionLinks();
    injectPopupClosePolish();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply);
  else apply();
  window.addEventListener('pageshow', apply);
})();
