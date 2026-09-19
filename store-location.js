(() => {
  'use strict';
  const section = document.getElementById('storeLocation');
  if (!section) return;
  const route = section.querySelector('.routeButton');
  const card = section.querySelector('.mapFallback');
  const choices = section.querySelectorAll('input[name="routeStore"]');
  function update() {
    const selected = section.querySelector('input[name="routeStore"]:checked');
    if (!selected || !route || !card) return;
    route.href = selected.dataset.route;
    card.href = selected.dataset.card;
    const address = selected.closest('label').querySelector('b').textContent;
    route.setAttribute('aria-label', 'Построить маршрут: ' + address);
    card.setAttribute('aria-label', 'Карточка магазина в 2ГИС: ' + address);
  }
  choices.forEach(input => input.addEventListener('change', update));
  update();
})();
