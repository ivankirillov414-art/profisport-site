'use strict';
(() => {
  const config = window.KOSMOSFERA_CONFIG;
  const money = value => new Intl.NumberFormat('ru-RU').format(value) + ' ₽';
  const menuToggle = document.querySelector('.menu-toggle');
  const mobileMenu = document.querySelector('#mobile-menu');
  const closeMenu = () => { mobileMenu.hidden = true; menuToggle.setAttribute('aria-expanded', 'false'); menuToggle.setAttribute('aria-label', 'Открыть меню'); };
  menuToggle.addEventListener('click', () => {
    const expanded = menuToggle.getAttribute('aria-expanded') === 'true';
    mobileMenu.hidden = expanded;
    menuToggle.setAttribute('aria-expanded', String(!expanded));
    menuToggle.setAttribute('aria-label', expanded ? 'Открыть меню' : 'Закрыть меню');
  });
  mobileMenu.querySelectorAll('a').forEach(a => a.addEventListener('click', closeMenu));
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });
  window.matchMedia('(min-width:901px)').addEventListener('change', event => { if(event.matches) closeMenu(); });
  document.querySelectorAll('[data-open]').forEach(button => button.addEventListener('click', event => {
    event.preventDefault();
    const dialog = document.getElementById(button.dataset.open);
    if (!dialog) return;
    if (button.dataset.ticketCount) { count = Math.min(20, Math.max(1, Number(button.dataset.ticketCount))); update(); }
    dialog.showModal();
    document.body.classList.add('modal-open');
  }));
  document.querySelectorAll('dialog').forEach(dialog => {
    dialog.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('close', () => document.body.classList.remove('modal-open'));
    dialog.addEventListener('click', event => {
      if(event.target !== dialog) return;
      const box = dialog.getBoundingClientRect();
      if(event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
    });
  });
  let count = 2;
  const minus = document.querySelector('#minus');
  const plus = document.querySelector('#plus');
  const update = () => {
    document.querySelector('#quantity').textContent = count;
    document.querySelector('#total').textContent = money(count * config.ticketPrice);
    minus.disabled = count <= 1;
    plus.disabled = count >= 20;
  };
  minus.addEventListener('click', () => { count = Math.max(1, count - 1); update(); });
  plus.addEventListener('click', () => { count = Math.min(20, count + 1); update(); });
  update();
  function downloadFile(text, filename, type) {
    const url = URL.createObjectURL(new Blob([text], {type}));
    const link = document.createElement('a');
    link.href = url; link.download = filename;
    document.body.append(link); link.click(); link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 5000);
  }
  const escapeICS = text => String(text).replace(/\\/g,'\\\\').replace(/\r?\n/g,'\\n').replace(/;/g,'\\;').replace(/,/g,'\\,');
  function calendar(date) {
    const next = new Date(date + 'T12:00:00Z');
    next.setUTCDate(next.getUTCDate() + 1);
    const endDate = next.toISOString().slice(0,10).replace(/-/g,'');
    const stamp = new Date().toISOString().replace(/[-:]/g,'').replace(/\.\d{3}/,'');
    const uid = globalThis.crypto?.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const lines = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Kosmosfera//Visit planner//RU','CALSCALE:GREGORIAN','BEGIN:VEVENT',`UID:${uid}@kosmosfera`,`DTSTAMP:${stamp}`,`DTSTART;VALUE=DATE:${date.replace(/-/g,'')}`,`DTEND;VALUE=DATE:${endDate}`,`SUMMARY:${escapeICS('Космосфера — запланировать посещение')}`,`LOCATION:${escapeICS(config.venue)}`,`DESCRIPTION:${escapeICS('План посещения выставки. Это не билет и не бронирование. Перед визитом проверьте часы работы и купите билеты. Выставка: 24 ноября 2026 — 15 января 2027.')}`,'STATUS:TENTATIVE','TRANSP:TRANSPARENT','END:VEVENT','END:VCALENDAR'];
    // RFC 5545 folding uses UTF-8 octets, not JavaScript character counts.
    const encoder = new TextEncoder();
    const folded = lines.map(line => {
      const parts = []; let part = ''; let bytes = 0;
      for (const char of line) {
        const size = encoder.encode(char).length;
        if (bytes + size > 73) { parts.push(part); part = ' '; bytes = 1; }
        part += char; bytes += size;
      }
      parts.push(part); return parts.join('\r\n');
    });
    downloadFile(folded.join('\r\n') + '\r\n', `kosmosfera-${date}.ics`, 'text/calendar;charset=utf-8');
  }
  document.querySelectorAll('.calendar-button').forEach(button => button.addEventListener('click', () => {
    const inTicketDialog = button.closest('#ticket-dialog');
    const input = document.querySelector('#visit-date');
    const status = document.querySelector('#date-status');
    if (inTicketDialog && (!input.value || input.value < config.openingDate || input.value > config.closingDate || !input.checkValidity())) {
      status.textContent = 'Выберите дату с 24 ноября 2026 по 15 января 2027.';
      input.reportValidity(); input.focus(); return;
    }
    calendar(inTicketDialog ? input.value : config.openingDate);
    if(inTicketDialog) status.textContent = 'Файл календаря подготовлен. Откройте его, чтобы сохранить дату.';
  }));
  document.querySelector('#visit-date').addEventListener('change', () => { document.querySelector('#date-status').textContent = ''; });
  document.querySelector('#group-form').addEventListener('submit', event => {
    event.preventDefault();
    const form = event.currentTarget;
    if(!form.reportValidity()) return;
    const data = new FormData(form);
    const text = ['КОСМОСФЕРА — ПЛАН ГРУППОВОГО ПОСЕЩЕНИЯ', '', 'Это черновик для согласования. Не является заявкой или бронированием.', '', 'Место: ' + config.venue, 'Группа: ' + String(data.get('org')).trim(), 'Гостей: ' + data.get('size'), 'Желаемая дата: ' + data.get('date'), 'Пожелания: ' + (String(data.get('message')).trim() || 'Не указаны'), '', 'Вопросы для организатора:', '— Доступны ли нужная дата и время?', '— Какая программа подходит для возраста детей?', '— Какова стоимость группового посещения и сопровождающих?', '— Какие дополнительные активности оплачиваются отдельно?', '', 'План создан на сайте Космосферы. Организатору не отправлен.'].join('\n');
    downloadFile('\uFEFF' + text, 'kosmosfera-group-plan.txt', 'text/plain;charset=utf-8');
    document.querySelector('#group-status').textContent = 'План подготовлен для скачивания. Сохраните его для согласования визита.';
  });
  const adventures = [
    { title: 'ТВОЯ ПЕРВАЯ КОСМИЧЕСКАЯ ЭКСПЕДИЦИЯ', label: 'МИССИЯ 01 / ИССЛЕДУЙ', description: 'Отправляйся навстречу открытиям: знакомься с космосом, пробуй новое и почувствуй себя настоящим исследователем Вселенной.', note: 'Большое приключение начинается с любопытства.', image: 'assets/hero-brand.webp', alt: 'Нарисованный ребёнок в скафандре на фоне Земли', position: '78% center' },
    { title: 'ПОГРУЖЕНИЕ В ДРУГОЙ МИР', label: 'МИССИЯ 02 / УДИВЛЯЙСЯ', description: 'Примерь VR-очки и открой для себя виртуальные миры. Ещё один способ добавить космических впечатлений в семейный день.', note: 'Дополнительная активность, оплачивается отдельно.', image: 'assets/vr.webp', alt: 'Девочка в VR-очках — иллюстрация', position: 'center' },
    { title: 'КОСМИЧЕСКИЕ ВПЕЧАТЛЕНИЯ ВМЕСТЕ', label: 'МИССИЯ 03 / МЕЧТАЙ', description: 'Удивляйтесь вместе с детьми, обсуждайте открытия и собирайте воспоминания о вашем маленьком путешествии в большую Вселенную.', note: 'Приключение для всей семьи.', image: 'assets/family-brand.webp', alt: 'Нарисованная семья в космической выставочной среде', position: '100% center' }
  ];
  let adventureIndex = 0;
  const slideButtons = document.querySelectorAll('[data-slide]');
  const showAdventure = index => {
    adventureIndex = (index + adventures.length) % adventures.length;
    const slide = adventures[adventureIndex];
    document.querySelector('#adventure-title').textContent = slide.title;
    document.querySelector('#mission-label').textContent = slide.label;
    document.querySelector('#adventure-description').textContent = slide.description;
    document.querySelector('#adventure-note').textContent = slide.note;
    document.querySelector('#adventure-index').textContent = adventureIndex + 1;
    const picture = document.querySelector('#adventure-image');
    picture.src = slide.image; picture.alt = slide.alt; picture.style.objectPosition = slide.position;
    slideButtons.forEach(button => button.setAttribute('aria-pressed', String(Number(button.dataset.slide) === adventureIndex)));
  };
  document.querySelector('#adventure-prev').addEventListener('click', () => showAdventure(adventureIndex - 1));
  document.querySelector('#adventure-next').addEventListener('click', () => showAdventure(adventureIndex + 1));
  slideButtons.forEach(button => button.addEventListener('click', () => showAdventure(Number(button.dataset.slide))));
  document.querySelector('.adventure-stage').addEventListener('keydown', event => {
    if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    event.preventDefault(); showAdventure(adventureIndex + (event.key === 'ArrowRight' ? 1 : -1));
  });
  const galleryDialog = document.querySelector('#gallery-dialog');
  document.querySelectorAll('[data-gallery]').forEach(button => button.addEventListener('click', () => {
    const picture = document.querySelector('#gallery-full-image');
    const current = button.querySelector('img');
    picture.src = current?.getAttribute('src') || button.dataset.gallery; picture.alt = current?.getAttribute('alt') || button.dataset.galleryCaption;
    document.querySelector('#gallery-caption').textContent = picture.alt;
    galleryDialog.showModal(); document.body.classList.add('modal-open');
  }));
  // Checkout stays closed unless the organizer supplies a real HTTPS URL.
  if (config.salesOpen && config.checkoutUrl) {
    try {
      const url = new URL(config.checkoutUrl);
      if(url.protocol !== 'https:') throw new Error('Checkout must use HTTPS');
      const link = document.querySelector('#checkout-link');
      link.href = url.href; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.hidden = false;
      document.querySelector('.sales-status').hidden = true;
      document.querySelector('.ticket-footnote').textContent = 'Билеты доступны онлайн';
    } catch { /* Keep the accurate presale state if configuration is invalid. */ }
  }
})();
