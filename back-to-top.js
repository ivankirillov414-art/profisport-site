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
      dialog.customerRegister,
      dialog.storagePromo,
      dialog.partDialog,
      dialog.quickView,
      dialog.filterDialog {
        overflow: visible !important;
      }

      dialog.customerRegister {
        background: transparent !important;
        box-shadow: none !important;
      }

      dialog.customerRegister .customerRegisterInner {
        position: relative;
        max-height: min(92dvh, 780px);
        overflow: auto;
        border-radius: 24px;
        background: #fff;
        box-shadow: 0 28px 90px rgba(17, 24, 32, .25);
      }

      dialog.customerRegister .customerRegisterClose,
      dialog.storagePromo .storagePromoClose,
      dialog.partDialog .dialogClose,
      dialog.quickView .dialogClose,
      dialog.filterDialog .dialogClose {
        position: absolute !important;
        top: -38px !important;
        right: -8px !important;
        z-index: 10 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: auto !important;
        min-width: 0 !important;
        height: auto !important;
        min-height: 0 !important;
        padding: 0 4px !important;
        border: 0 !important;
        border-radius: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
        color: rgba(255, 255, 255, .72) !important;
        opacity: .72 !important;
        font: 400 36px/1 Arial, sans-serif !important;
        text-shadow: 0 2px 12px rgba(0, 0, 0, .36) !important;
      }

      dialog.customerRegister .customerRegisterClose:hover,
      dialog.customerRegister .customerRegisterClose:focus-visible,
      dialog.storagePromo .storagePromoClose:hover,
      dialog.storagePromo .storagePromoClose:focus-visible,
      dialog.partDialog .dialogClose:hover,
      dialog.partDialog .dialogClose:focus-visible,
      dialog.quickView .dialogClose:hover,
      dialog.quickView .dialogClose:focus-visible,
      dialog.filterDialog .dialogClose:hover,
      dialog.filterDialog .dialogClose:focus-visible {
        background: transparent !important;
        color: #fff !important;
        opacity: 1 !important;
        outline: 0 !important;
      }

      @media (max-width: 620px) {
        dialog.customerRegister .customerRegisterInner {
          min-height: 100dvh;
          max-height: 100dvh;
          border-radius: 0;
        }
        dialog.customerRegister .customerRegisterClose {
          top: calc(10px + env(safe-area-inset-top)) !important;
          right: 14px !important;
          color: rgba(23, 25, 28, .55) !important;
          text-shadow: none !important;
        }
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
