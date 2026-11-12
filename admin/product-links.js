(() => {
 'use strict';
 const $ = id => document.getElementById(id);
 let page = 1, pages = 1, request = null, timer = null;
 const rub = n => Number(n || 0).toLocaleString('ru-RU') + ' ₽';

 function productUrl(id, tracked = false) {
  const url = new URL('../product.html', window.location.href);
  url.searchParams.set('id', String(id));
  if (tracked) {
   url.searchParams.set('utm_source', '2gis');
   url.searchParams.set('utm_medium', 'referral');
   url.searchParams.set('utm_campaign', 'products');
   url.searchParams.set('utm_content', 'product_' + id);
  }
  return url.href;
 }

 async function copyLink(input, button) {
  const value = input.value;
  let copied = false;
  try {
   if (navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(value);
    copied = true;
   }
  } catch (_) { /* Older browsers and blocked clipboard permissions use selection. */ }
  if (!copied) {
   input.focus();
   input.select();
   input.setSelectionRange(0, value.length);
   try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
  }
  $('copyStatus').textContent = copied ? 'Ссылка скопирована.' : 'Не удалось скопировать автоматически. Ссылка выделена — выберите «Скопировать».';
  if (copied) {
   button.textContent = 'Скопировано ✓';
   setTimeout(() => { button.textContent = 'Скопировать'; }, 1800);
  }
 }

 function linkRow(product, tracked) {
  const row = document.createElement('div');
  row.className = 'productLinkRow';
  const label = document.createElement('label');
  const input = document.createElement('input');
  input.id = 'product-link-' + product.id + (tracked ? '-utm' : '-direct');
  input.type = 'url'; input.readOnly = true;
  input.value = productUrl(product.id, tracked);
  label.htmlFor = input.id;
  label.textContent = tracked ? 'UTM-ссылка для 2ГИС' : 'Прямая ссылка на товар';
  const button = document.createElement('button');
  button.type = 'button'; button.textContent = 'Скопировать';
  button.setAttribute('aria-label', 'Скопировать ' + (tracked ? 'UTM-ссылку' : 'прямую ссылку') + ': ' + product.name);
  button.addEventListener('click', () => copyLink(input, button));
  row.append(label, input, button);
  return row;
 }

 function card(product) {
  const box = document.createElement('article'); box.className = 'productLinkCard';
  const title = document.createElement('h2'); title.textContent = product.name;
  const meta = document.createElement('p'); meta.className = 'productLinkMeta';
  meta.textContent = 'ID: ' + product.id + ' · ' + rub(product.price_rub) + ' · Остаток: ' + (product.stock_qty ?? 'неизвестен');
  box.append(title, meta);
  const publicProduct = Number(product.is_active) === 1 && Number(product.price_rub) > 0 && Number(product.stock_qty) > 0 && product.stock_status !== 'out_of_stock' && product.availability !== 'out_of_stock';
  if (!publicProduct) {
   const warning = document.createElement('p'); warning.className = 'productLinkWarning';
   warning.textContent = 'Карточка недоступна покупателям: проверьте видимость, цену и остаток перед размещением.';
   box.append(warning);
  }
  box.append(linkRow(product, false), linkRow(product, true));
  const open = document.createElement('a'); open.href = productUrl(product.id); open.target = '_blank'; open.rel = 'noopener'; open.textContent = 'Открыть карточку ↗';
  box.append(open);
  return box;
 }

 function controls(busy) {
  $('linkPrev').disabled = busy || page <= 1;
  $('linkNext').disabled = busy || page >= pages;
 }

 async function load() {
  if (request) request.abort();
  const activeRequest = new AbortController(); request = activeRequest;
  $('linkList').replaceChildren(); $('linkList').setAttribute('aria-busy', 'true');
  $('linkStatus').textContent = 'Ищем товары…'; $('linkPageInfo').textContent = ''; $('copyStatus').textContent = '';
  controls(true);
  try {
   const params = new URLSearchParams({ q: $('linkQuery').value.trim(), page: String(page) });
   const response = await fetch('../api/product-admin.php?' + params, { cache: 'no-store', signal: activeRequest.signal });
   if (activeRequest !== request || activeRequest.signal.aborted) return;
   if (response.status === 401 || response.status === 403) {
    $('linkStatus').textContent = 'Войдите в админку заново, чтобы посмотреть товары.'; return;
   }
   if (!response.ok) throw new Error('request_failed');
   const data = await response.json();
   if (activeRequest !== request || activeRequest.signal.aborted) return;
   if (!data.ok || !Array.isArray(data.items)) throw new Error('invalid_response');
   pages = Math.max(1, Number(data.pages) || 1);
   if (page > pages) { page = pages; load(); return; }
   const products = data.items.filter(p => /^\d+$/.test(String(p.id)) && Number(p.id) > 0);
   $('linkList').replaceChildren(...products.map(card));
   $('linkStatus').textContent = products.length ? 'Найдено товаров: ' + data.total : 'Товаров не найдено. Попробуйте другое название или артикул.';
   $('linkPageInfo').textContent = 'Страница ' + page + ' из ' + pages;
   controls(false);
  } catch (error) {
   if (error.name !== 'AbortError' && activeRequest === request) {
    $('linkStatus').textContent = 'Не удалось загрузить товары. Нажмите «Найти», чтобы повторить.';
   }
  } finally {
   if (activeRequest === request) $('linkList').setAttribute('aria-busy', 'false');
  }
 }
 $('linkSearch').addEventListener('submit', event => { event.preventDefault(); clearTimeout(timer); page = 1; load(); });
 $('linkQuery').addEventListener('input', () => {
  clearTimeout(timer); if (request) request.abort();
  // Remove links from the previous query immediately, before the debounce delay.
  $('linkList').replaceChildren(); controls(true); $('linkPageInfo').textContent = ''; $('linkStatus').textContent = 'Ищем товары…';
  timer = setTimeout(() => { page = 1; load(); }, 300);
 });
 $('linkPrev').addEventListener('click', () => { if (page > 1) { page--; load(); } });
 $('linkNext').addEventListener('click', () => { if (page < pages) { page++; load(); } });
 load();
})();
