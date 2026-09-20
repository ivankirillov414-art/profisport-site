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
    window.scrollTo({ top: 0, left: window.scrollX, behavior: 'instant' });
    update();
  });
  window.addEventListener('scroll', update, { passive: true });
  window.addEventListener('pageshow', update);
  update();
})();
