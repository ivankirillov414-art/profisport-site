const API='../server/api.php';
let csrf='';
const $=id=>document.getElementById(id);

// Единая тема админки: графит + кобальт.
// Оставляем зелёный/красный только для смысловых статусов успеха и ошибки.
(()=>{
  const style=document.createElement('style');
  style.id='profisport-cobalt-theme';
  style.textContent=`
    :root{
      --y:#0047AB!important;
      --cobalt:#0047AB;
      --cobalt-dark:#003B8F;
      --cobalt-soft:#EAF1FF;
      --graphite:#30353B;
      --graphite-2:#252A2F;
      --bg:#F2F3F5!important;
      --tx:#22272D!important;
      --muted:#737A82!important;
      --line:#DDE1E6!important;
    }
    .top{background:linear-gradient(135deg,var(--graphite),var(--graphite-2))!important}
    .brand i,.hero i{color:#5B8DEF!important}
    .logo{background:var(--cobalt)!important;color:#fff!important}
    .hero{background:var(--graphite-2)!important}
    .tile.primary{background:linear-gradient(145deg,#F5F8FF,#E5EEFF)!important;border-color:#AFC6F5!important}
    .primary .ico,.importIcon{background:var(--cobalt)!important;color:#fff!important}
    .primary .ico svg{stroke:#fff!important}
    .btn:not(.secondary),.importBtn{background:var(--cobalt)!important;color:#fff!important}
    .btn:not(.secondary):active,.importBtn:active{background:var(--cobalt-dark)!important}
    .secondary{background:#E5E8EC!important;color:#252A2F!important}
    .cand.selected{border-color:var(--cobalt)!important;background:var(--cobalt-soft)!important}
    .back{background:#FFFFFF12!important;border-color:#FFFFFF28!important}
    .healthCard{background:#F4F5F7!important}
    .summary,.product,.metric,.tile,.panel,.empty{border-color:var(--line)!important}
    .bottom a:first-child{background:var(--cobalt-soft)!important;color:var(--cobalt)!important}
    input:focus{outline:2px solid #7EA5E8!important;outline-offset:1px}
    a:focus-visible,button:focus-visible{outline:2px solid #7EA5E8!important;outline-offset:2px}
  `;
  document.head.appendChild(style);
})();

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
const photosTile=document.querySelector('a[href="#photos"]');if(photosTile)photosTile.href='photos.php';
(async()=>{try{await refreshAuth();await Promise.allSettled([dashboard(),loadProducts(),loadImportFiles()])}catch(e){console.error('admin boot',e);setText('statProducts','—');setText('statOrders','—')}})();