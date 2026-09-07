const API='../server/api.php';
let csrf='';
const $=id=>document.getElementById(id);
async function api(action,opts={}){opts.headers={...(opts.headers||{}),'Content-Type':'application/json'};if(csrf)opts.headers['X-CSRF-Token']=csrf;const r=await fetch(`${API}?action=${action}`,opts);const j=await r.json().catch(()=>({error:'bad_json'}));if(!r.ok)throw new Error(j.error||`HTTP ${r.status}`);return j}
function format(n){return Number(n||0).toLocaleString('ru-RU')}
function setText(id,value){const el=$(id);if(el)el.textContent=value}
async function refreshAuth(){const m=await api('me');csrf=m.csrf||'';return m}
async function dashboard(){const s=await api('stats');setText('statProducts',format(s.products));setText('statProductsToday',`+${format(s.products_today)} сегодня`);setText('statOrders',format(s.new_orders));setText('statOrdersToday',`+${format(s.orders_today)} сегодня`);return s}
async function loadProducts(){const box=$('products');if(!box)return;try{const r=await api('products');box.innerHTML='';for(const p of (r.items||[])){const d=document.createElement('div');d.className='product';d.textContent=p.title||p.name||'Без названия';box.appendChild(d)}}catch(e){box.textContent='Не удалось загрузить товары.'}}
async function loadImportFiles(){const box=$('importFiles');if(!box)return;try{const r=await fetch('../api/import-files.php',{cache:'no-store'});const j=await r.json();if(!r.ok)throw new Error(j.error||r.status);const files=Array.isArray(j.files)?j.files:[];box.textContent=files.length?`Найдено файлов: ${format(files.length)}`:'В /import/ пока нет файлов.'}catch(e){box.textContent='Не удалось прочитать входящие файлы.'}}
window.getProfisportCsrf=()=>csrf;
window.refreshProfisportAuth=refreshAuth;
window.refreshProfisportDashboard=async()=>{try{await dashboard()}catch(e){console.error('dashboard refresh',e)}};
const refresh=$('refreshImports');if(refresh)refresh.addEventListener('click',loadImportFiles);
(async()=>{try{await refreshAuth();await Promise.allSettled([dashboard(),loadProducts(),loadImportFiles()])}catch(e){console.error('admin boot',e);setText('statProducts','—');setText('statOrders','—')}})();