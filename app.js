let products=[];
let view=[];
let page=1;
const PAGE=24;
let cart=readStoredArray('ps-cart');
let favorites=new Set(readStoredArray('ps-favorites').map(String));
let customerState={logged:false,csrf:''};

const $=s=>document.querySelector(s);
const $$=s=>[...document.querySelectorAll(s)];
const rub=n=>new Intl.NumberFormat('ru-RU').format(Number(n)||0)+' ₽';
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
function readStoredArray(key){try{const v=JSON.parse(localStorage.getItem(key)||'[]');return Array.isArray(v)?v.filter(x=>['string','number'].includes(typeof x)):[]}catch(e){return[]}}
function plain(s){return String(s||'').toLowerCase().replace(/ё/g,'е').replace(/[.,;:()[\]{}"']/g,' ').replace(/\s+/g,' ').trim()}

const ruMap={'q':'й','w':'ц','e':'у','r':'к','t':'е','y':'н','u':'г','i':'ш','o':'щ','p':'з','[':'х',']':'ъ','a':'ф','s':'ы','d':'в','f':'а','g':'п','h':'р','j':'о','k':'л','l':'д',';':'ж',"'":'э','z':'я','x':'ч','c':'с','v':'м','b':'и','n':'т','m':'ь',',':'б','.':'ю'};
const enMap=Object.fromEntries(Object.entries(ruMap).map(([a,b])=>[b,a]));
const swap=(s,map)=>[...String(s||'').toLowerCase()].map(ch=>map[ch]||ch).join('');
function canonicalQuery(raw){
  let s=plain(raw).replace(/^велосипедосипед(?=\s|$)/,'велосипед').replace(/^велосипосипед(?=\s|$)/,'велосипед');
  const rules=[
    [/велик[а-я]*/g,'велосипед'],[/^вел$/g,'велосипед'],[/\bmtb\b/g,'велосипед'],[/горник[а-я]*/g,'горный велосипед'],
    [/запчасти?/g,'запчаст'],[/аксессуары?/g,'аксессуар'],[/лыжи?/g,'лыж'],[/сноуборды?/g,'сноуборд']
  ];
  for(const [re,to] of rules)s=s.replace(re,to);
  return s.replace(/\s+/g,' ').trim();
}
function queryVariants(raw){return[canonicalQuery(raw),canonicalQuery(swap(raw,ruMap)),canonicalQuery(swap(raw,enMap))].filter((x,i,a)=>x&&a.indexOf(x)===i)}
function productText(p){return plain([p.name,p.brand,p.model,p.cat,p.pathText,p.description,Object.entries(p.specs||{}).flat().join(' ')].join(' '))}

const accessoryStems=['чехол','сумк','багажник','крыл','фонар','звонок','замок','покрыш','камер','насос','держател','креплен','корзин','зеркал','седл','сиден','педал','грипс','трос','цеп','кассет','звезд','переключ','тормоз','обод','вилк','рам','втулк','спиц','поднож','подстав','крепеж','адаптер','палк','ботин','маск','очк','перчат','защит','колес','подшип','ось','амортиз','ремкомплект','запчаст'];
const typeTerms={
  bicycle:['электровелосипед','велосипед'], scooter:['электросамокат','самокат'], skis:['лыж'], snowboard:['сноуборд'], skates:['коньк'], rollers:['роликов','ролик'], skateboard:['скейтборд'], longboard:['лонгборд'], sled:['санк'], snow_scooter:['снегокат'], tubing:['тюбинг'], helmet:['шлем'], backpack:['рюкзак'], tent:['палатк'], sleeping_bag:['спальник','спальный мешок'], trampoline:['батут'], treadmill:['беговая дорожк'], exercise_bike:['велотренажер'], elliptical:['эллипс','эллиптическ'], dumbbell:['гантел'], barbell:['штанг'], kettlebell:['гиря','гири','гирь'], racket:['ракетк'], ball:['мяч'], hockey_stick:['клюшк'], pool:['бассейн'], sup:['сап','sup'], kayak:['каяк'], boat:['лодк']
};
function containsAccessory(s){return accessoryStems.some(st=>s.includes(st))}
function intentFor(raw){
  const q=canonicalQuery(raw);
  if(!q)return'';
  const pairs=[['bicycle',['велосипед','электровелосипед']],['scooter',['самокат','электросамокат']],['skis',['лыж']],['snowboard',['сноуборд']],['skates',['коньк']],['rollers',['ролик']],['skateboard',['скейтборд']],['longboard',['лонгборд']],['sled',['санк']],['snow_scooter',['снегокат']],['tubing',['тюбинг']],['helmet',['шлем']],['backpack',['рюкзак']],['tent',['палатк']],['sleeping_bag',['спальник','спальный мешок']],['trampoline',['батут']],['treadmill',['беговая дорожк']],['exercise_bike',['велотренажер']],['elliptical',['эллипс','эллиптическ']],['dumbbell',['гантел']],['barbell',['штанг']],['kettlebell',['гиря','гири','гирь']],['racket',['ракетк']],['ball',['мяч']],['hockey_stick',['клюшк']],['pool',['бассейн']],['sup',['сап','sup']],['kayak',['каяк']],['boat',['лодк']]];
  for(const [id,terms] of pairs){
    if(terms.some(t=>q.startsWith(t))){
      if(!['helmet','backpack'].includes(id)&&containsAccessory(q))return'';
      return id;
    }
  }
  return'';
}
function isPrimaryProduct(p,intent){
  if(!intent)return true;
  const n=plain(p.name||'');
  const terms=typeTerms[intent]||[];
  let pos=Infinity;
  for(const t of terms){const i=n.indexOf(t);if(i>=0)pos=Math.min(pos,i)}
  if(!Number.isFinite(pos))return false;
  const before=n.slice(0,pos);
  if(containsAccessory(before)||/(?:^|\s)для\s*$/.test(before))return false;
  return pos<=48;
}
function relevanceScore(p,q,intent){
  const c=canonicalQuery(q),n=plain(p.name||''),brand=plain(p.brand||''),model=plain(p.model||''),cat=plain(p.cat||''),path=plain(p.pathText||'');
  let score=0;
  if(intent&&isPrimaryProduct(p,intent))score+=1200;
  if(n===c)score+=1000;else if(n.startsWith(c))score+=650;else if(n.includes(c))score+=420;
  if(brand===c||model===c)score+=500;else if(brand.startsWith(c)||model.startsWith(c))score+=280;
  if(cat.includes(c))score+=120;if(path.includes(c))score+=80;
  return score;
}
function bestQuery(raw){
  const vars=queryVariants(raw);if(!vars.length)return'';
  for(const q of vars){const intent=intentFor(q);if(products.some(p=>(!intent||isPrimaryProduct(p,intent))&&productText(p).includes(q)))return q}
  return vars[0];
}

const productsEl=$('#products'),catalogStatus=$('#catalogStatus'),category=$('#category'),brandFilter=$('#brandFilter'),stockOnly=$('#stockOnly'),minPrice=$('#minPrice'),maxPrice=$('#maxPrice'),sort=$('#sort');
const q=$('#q'),mobileQ=$('#mobileQ'),pageInfo=$('#pageInfo'),prevPage=$('#prevPage'),nextPage=$('#nextPage');
const cartEl=$('#cart'),cartItems=$('#cartItems'),count=$('#count'),total=$('#total');

function imageCandidates(p){
  const raw=[p.image,...(Array.isArray(p.images)?p.images:[])].filter(Boolean);
  return [...new Set(raw.flatMap(src=>{src=String(src);const out=[src];if(src.startsWith('/import/'))out.push('api/product-image.php?p='+encodeURIComponent(src.slice(8)));if(src.startsWith('import/'))out.push('api/product-image.php?p='+encodeURIComponent(src.slice(7)));return out}))];
}
function bindProductImages(){
  $$('img.productImg').forEach(img=>{img.onerror=()=>{let a=[];try{a=JSON.parse(decodeURIComponent(img.dataset.fallbacks||'%5B%5D'))}catch(e){}const next=a.shift();if(next){img.dataset.fallbacks=encodeURIComponent(JSON.stringify(a));img.src=next;return}const ph=document.createElement('div');ph.className='imagePlaceholder';ph.textContent='Фото уточняется';img.replaceWith(ph)}});
}
function favoriteMarkup(id){const active=favorites.has(String(id));return `<button type="button" class="productFavorite${active?' active':''}" data-favorite-id="${esc(encodeURIComponent(id))}" aria-label="В избранное">${active?'♥':'♡'}</button>`}
function render(list=view){
  view=list;
  const pages=Math.max(1,Math.ceil(view.length/PAGE));page=Math.min(Math.max(1,page),pages);
  const show=view.slice((page-1)*PAGE,page*PAGE);
  productsEl.dataset.catalogReady='1';
  productsEl.innerHTML=show.map(p=>{const imgs=imageCandidates(p),first=imgs.shift(),discount=p.oldPrice&&p.oldPrice>p.price?Math.round((1-p.price/p.oldPrice)*100):0;return `<article class="product">${favoriteMarkup(p.id)}<a href="product.html?id=${encodeURIComponent(p.id)}"><div class="photo">${first?`<img class="productImg" src="${esc(first)}" data-fallbacks="${esc(encodeURIComponent(JSON.stringify(imgs)))}" loading="lazy" decoding="async" width="320" height="240" alt="${esc(p.name)}">`:'<div class="imagePlaceholder">Фото уточняется</div>'}</div><small>${esc(p.cat)}</small><h3>${esc(p.name)}</h3></a><span class="stock ${p.stockCode==='out'?'out':''}">${esc(p.stock)}</span>${p.oldPrice?`<del>${rub(p.oldPrice)}</del>`:''}<div class="price">${rub(p.price)}</div>${discount?`<span class="productDiscount">−${discount}%</span>`:''}<button class="addBtn" data-id="${esc(encodeURIComponent(p.id))}" ${p.stockCode==='out'?'disabled':''}>${p.stockCode==='out'?'Нет в наличии':'В корзину'}</button></article>`}).join('')||'<p>Ничего не найдено.</p>';
  bindProductImages();
  $$('.addBtn:not([disabled])').forEach(b=>b.onclick=()=>add(decodeURIComponent(b.dataset.id)));
  $$('.productFavorite').forEach(b=>b.onclick=()=>toggleFavorite(decodeURIComponent(b.dataset.favoriteId),b));
  catalogStatus.textContent=`Найдено: ${view.length}`;pageInfo.textContent=`${page} / ${pages}`;prevPage.disabled=page<=1;nextPage.disabled=page>=pages;
}

function syncState(){
  const u=new URL(location.href),qv=(q?.value||mobileQ?.value||'').trim();
  qv?u.searchParams.set('q',qv):u.searchParams.delete('q');
  category.value?u.searchParams.set('cat',category.value):u.searchParams.delete('cat');
  brandFilter.value?u.searchParams.set('brand',brandFilter.value):u.searchParams.delete('brand');
  stockOnly.checked?u.searchParams.set('stock','1'):u.searchParams.delete('stock');
  minPrice.value?u.searchParams.set('min',minPrice.value):u.searchParams.delete('min');maxPrice.value?u.searchParams.set('max',maxPrice.value):u.searchParams.delete('max');
  sort.value!=='popular'?u.searchParams.set('sort',sort.value):u.searchParams.delete('sort');page>1?u.searchParams.set('page',page):u.searchParams.delete('page');
  history.replaceState(null,'',u);
}
function apply(resetPage=true,sync=true){
  let list=[...products],c=category.value,min=+minPrice.value||0,max=+maxPrice.value||Infinity,raw=(q?.value||mobileQ?.value||'').trim();
  const qv=bestQuery(raw),intent=intentFor(qv||raw);
  if(q&&mobileQ){q.value=raw;mobileQ.value=raw}
  if(c)list=list.filter(p=>p.cat===c);
  if(qv){const terms=qv.split(' ').filter(Boolean);list=list.filter(p=>(!intent||isPrimaryProduct(p,intent))&&terms.every(t=>productText(p).includes(t)));if(sort.value==='popular')list.sort((a,b)=>relevanceScore(b,qv,intent)-relevanceScore(a,qv,intent))}
  if(brandFilter.value)list=list.filter(p=>p.brand===brandFilter.value);
  if(stockOnly.checked)list=list.filter(p=>p.stockCode==='in');
  list=list.filter(p=>p.price>=min&&p.price<=max);
  if(sort.value==='priceAsc')list.sort((a,b)=>a.price-b.price);if(sort.value==='priceDesc')list.sort((a,b)=>b.price-a.price);if(sort.value==='name')list.sort((a,b)=>a.name.localeCompare(b.name,'ru'));
  if(resetPage)page=1;render(list);if(sync)syncState();
}
window.apply=apply;

function add(id){cart.push(id);saveCart();toggleCart(true)}
function saveCart(){localStorage.setItem('ps-cart',JSON.stringify(cart));count.textContent=cart.length;renderCart()}
function renderCart(){const groups=new Map;cart.forEach(id=>groups.set(String(id),(groups.get(String(id))||0)+1));let sum=0;cartItems.innerHTML=[...groups].map(([id,qty])=>{const p=products.find(x=>String(x.id)===id);if(!p)return'';sum+=p.price*qty;return `<div class="cartrow"><span>${esc(p.name)}<br><b>${rub(p.price)}</b></span><div class="cartQty"><button onclick="changeQty('${encodeURIComponent(id)}',-1)">−</button><b>${qty}</b><button onclick="changeQty('${encodeURIComponent(id)}',1)">+</button></div></div>`}).join('')||'<p>Корзина пока пуста</p>';total.textContent=rub(sum)}
function changeQty(encoded,delta){const id=decodeURIComponent(encoded);if(delta>0)cart.push(id);else{const i=cart.findIndex(x=>String(x.id)===id);if(i>=0)cart.splice(i,1)}saveCart()}
function toggleCart(force){const open=force===undefined?!cartEl.classList.contains('open'):force;cartEl.classList.toggle('open',open);cartEl.setAttribute('aria-hidden',String(!open))}
window.changeQty=changeQty;window.toggleCart=toggleCart;

function toggleMenu(force){const menu=$('#mobileMenu'),back=$('#menuBackdrop');const open=force===undefined?!menu.classList.contains('open'):force;menu.classList.toggle('open',open);back.classList.toggle('open',open);menu.setAttribute('aria-hidden',String(!open));document.body.style.overflow=open?'hidden':''}

async function loadCustomerState(){
  try{const r=await fetch('api/customer.php?action=me',{cache:'no-store'}),j=await r.json();if(r.ok&&j.ok&&j.customer){customerState={logged:true,csrf:j.csrf||''};favorites=new Set((j.favorites||[]).map(String));localStorage.setItem('ps-favorites',JSON.stringify([...favorites]));refreshFavoriteButtons()}}catch(e){}
}
function refreshFavoriteButtons(){$$('.productFavorite').forEach(b=>{const id=decodeURIComponent(b.dataset.favoriteId||'');const active=favorites.has(String(id));b.classList.toggle('active',active);b.textContent=active?'♥':'♡'})}
async function toggleFavorite(id,button){
  const s=String(id);
  if(customerState.logged){
    try{const r=await fetch('api/customer.php?action=favorite',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':customerState.csrf},body:JSON.stringify({product_id:Number(id)})}),j=await r.json();if(!r.ok||!j.ok)throw Error();j.active?favorites.add(s):favorites.delete(s)}catch(e){location.href='profile.html';return}
  }else{favorites.has(s)?favorites.delete(s):favorites.add(s)}
  localStorage.setItem('ps-favorites',JSON.stringify([...favorites]));const active=favorites.has(s);button.classList.toggle('active',active);button.textContent=active?'♥':'♡';
}

function suggestionItems(raw){
  if(raw.trim().length<2)return[];const qv=bestQuery(raw),intent=intentFor(qv||raw),terms=qv.split(' ').filter(Boolean);
  return products.filter(p=>(!intent||isPrimaryProduct(p,intent))&&terms.every(t=>productText(p).includes(t))).sort((a,b)=>relevanceScore(b,qv,intent)-relevanceScore(a,qv,intent)).slice(0,7);
}
function bindSuggest(input,box){
  if(!input||!box)return;const draw=()=>{const found=suggestionItems(input.value);if(input.value.trim().length<2){box.classList.remove('open');box.innerHTML='';return}box.innerHTML=found.length?found.map(p=>`<a href="product.html?id=${encodeURIComponent(p.id)}">${p.image?`<img src="${esc(p.image)}" alt="">`:'<span class="suggestPh"></span>'}<span><b>${esc(p.name)}</b><small>${esc([p.brand,p.cat].filter(Boolean).join(' · '))}</small></span><strong>${rub(p.price)}</strong></a>`).join(''):'<a href="#catalogProducts"><span class="suggestPh"></span><span><b>Ничего точного не найдено</b><small>Показать результаты</small></span><strong>→</strong></a>';box.classList.add('open')};input.addEventListener('input',draw);input.addEventListener('focus',draw);document.addEventListener('click',e=>{if(e.target!==input&&!box.contains(e.target))box.classList.remove('open')})
}

function buildMega(){
  if(matchMedia('(max-width:850px)').matches)return;
  const panel=$('#megaCatalog');if(!panel)return;const groups=new Map();
  for(const p of products){const path=Array.isArray(p.path)?p.path.filter(Boolean):[],top=path[0]||p.cat||'Каталог',sub=path[1]||p.cat||'';if(!groups.has(top))groups.set(top,new Map());if(sub)groups.get(top).set(sub,(groups.get(top).get(sub)||0)+1)}
  const ranked=[...groups.entries()].sort((a,b)=>[...b[1].values()].reduce((x,y)=>x+y,0)-[...a[1].values()].reduce((x,y)=>x+y,0)).slice(0,9);
  panel.innerHTML=ranked.map(([top,subs])=>`<section class="megaGroup"><h3>${esc(top)}</h3>${[...subs.entries()].sort((a,b)=>b[1]-a[1]).slice(0,6).map(([s,c])=>`<a href="#catalogProducts" data-mega-term="${esc(s)}">${esc(s)} <small>(${c})</small></a>`).join('')}</section>`).join('')+'<a class="megaAll" href="#catalogProducts" data-mega-all>Весь каталог →</a>';
  panel.querySelectorAll('[data-mega-term]').forEach(a=>a.onclick=e=>{e.preventDefault();q.value=a.dataset.megaTerm||'';mobileQ.value=q.value;category.value='';apply(true,true);closeMega();$('#catalogProducts').scrollIntoView({behavior:'smooth'})});panel.querySelector('[data-mega-all]')?.addEventListener('click',closeMega);
}
function openMega(){if(matchMedia('(max-width:850px)').matches)return;$('#megaCatalog')?.classList.add('open');$('#megaBackdrop')?.classList.add('open')}
function closeMega(){$('#megaCatalog')?.classList.remove('open');$('#megaBackdrop')?.classList.remove('open')}

function setupHero(){
  const slider=$('#heroSlider'),track=slider?.querySelector('.heroTrack'),slides=$$('.heroSlide'),dots=$$('#heroSlider .dots button');if(!slider||!track||!slides.length)return;
  let current=0,startX=0,startY=0,dx=0,dy=0,timer;
  const setSlide=(n,restart=true)=>{current=(n+slides.length)%slides.length;const mobile=matchMedia('(max-width:850px)').matches;slides.forEach((s,i)=>s.classList.toggle('is-active',i===current));dots.forEach((d,i)=>d.classList.toggle('active',i===current));track.style.transform=mobile?'none':`translateX(${-current*100}%)`;if(restart){clearInterval(timer);timer=setInterval(()=>setSlide(current+1,false),5000)}};
  dots.forEach((d,i)=>d.onclick=()=>setSlide(i));slider.addEventListener('touchstart',e=>{startX=e.touches[0].clientX;startY=e.touches[0].clientY;dx=dy=0;clearInterval(timer)},{passive:true});slider.addEventListener('touchmove',e=>{dx=e.touches[0].clientX-startX;dy=e.touches[0].clientY-startY},{passive:true});slider.addEventListener('touchend',()=>{if(Math.abs(dx)>45&&Math.abs(dx)>Math.abs(dy)*1.2)setSlide(current+(dx<0?1:-1));else setSlide(current)},{passive:true});window.addEventListener('resize',()=>setSlide(current,false),{passive:true});setSlide(0);
}

function populateFilters(){
  [...new Set(products.map(p=>p.cat).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'ru')).forEach(c=>category.add(new Option(c,c)));
  [...new Set(products.map(p=>p.brand).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'ru')).forEach(b=>brandFilter.add(new Option(b,b)));
}
function restoreState(){const sp=new URLSearchParams(location.search),initialQ=sp.get('q')||'';q.value=initialQ;mobileQ.value=initialQ;category.value=sp.get('cat')||'';brandFilter.value=sp.get('brand')||'';stockOnly.checked=sp.get('stock')==='1';minPrice.value=sp.get('min')||'';maxPrice.value=sp.get('max')||'';sort.value=sp.get('sort')||'popular';page=Math.max(1,+sp.get('page')||1)}

$('#cartBtn')?.addEventListener('click',()=>toggleCart());$('#searchBtn')?.addEventListener('click',()=>{toggleMenu(false);$('#catalogProducts')?.scrollIntoView({behavior:'smooth',block:'start'});setTimeout(()=>mobileQ?.focus(),250)});$('#menuBtn')?.addEventListener('click',()=>toggleMenu(true));$('#menuClose')?.addEventListener('click',()=>toggleMenu(false));$('#menuBackdrop')?.addEventListener('click',()=>toggleMenu(false));$$('[data-menu-close]').forEach(a=>a.addEventListener('click',()=>toggleMenu(false)));document.addEventListener('keydown',e=>{if(e.key==='Escape'){toggleMenu(false);toggleCart(false);closeMega()}});$('.checkout')?.addEventListener('click',()=>{if(cart.length)location.href='checkout.html'});
$('#applyFilters')?.addEventListener('click',()=>apply(true,true));$('#resetFilters')?.addEventListener('click',()=>{category.value='';brandFilter.value='';stockOnly.checked=false;minPrice.value='';maxPrice.value='';sort.value='popular';q.value='';mobileQ.value='';page=1;render(products);syncState()});
[category,brandFilter,sort,stockOnly].forEach(el=>el?.addEventListener('change',()=>apply(true,true)));
prevPage?.addEventListener('click',()=>{if(page>1){page--;render(view);syncState();productsEl.scrollIntoView()}});nextPage?.addEventListener('click',()=>{if(page*PAGE<view.length){page++;render(view);syncState();productsEl.scrollIntoView()}});
$$('[data-category]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();const term=a.dataset.category||'';toggleMenu(false);category.value='';q.value=term;mobileQ.value=term;apply(true,true);productsEl.scrollIntoView({behavior:'smooth'})}));
$('#search')?.addEventListener('submit',e=>{e.preventDefault();mobileQ.value=q.value;apply(true,true);$('#desktopSuggest')?.classList.remove('open');productsEl.scrollIntoView({behavior:'smooth'})});$('#mobileSearch')?.addEventListener('submit',e=>{e.preventDefault();q.value=mobileQ.value;apply(true,true);$('#mobileSuggest')?.classList.remove('open');productsEl.scrollIntoView({behavior:'smooth'})});
$('#desktopCatalogLink')?.addEventListener('click',e=>{e.preventDefault();$('#megaCatalog')?.classList.contains('open')?closeMega():openMega()});$('#megaBackdrop')?.addEventListener('click',closeMega);
$('#pick')?.addEventListener('click',()=>{const h=+$('#height').value,b=+$('#budget').value;if(!h||!b){$('#pickResult').textContent='Укажите рост и бюджет.';return}const frame=h<165?'S':h<178?'M':h<188?'L':'XL',matches=products.filter(p=>intentFor(p.name)==='bicycle'&&p.price<=b&&p.stockCode!=='out');$('#pickResult').innerHTML=`Рекомендуемый размер рамы: <b>${frame}</b>. Подходящих по бюджету: <b>${matches.length}</b>.`;page=1;render(matches);productsEl.scrollIntoView({behavior:'smooth'})});

setupHero();
bindSuggest(q,$('#desktopSuggest'));bindSuggest(mobileQ,$('#mobileSuggest'));

(async()=>{
  try{
    catalogStatus.textContent='Загрузка каталога…';
    products=await loadRealCatalog();if(!products.length)throw Error('empty');
    populateFilters();restoreState();apply(false,false);saveCart();buildMega();loadCustomerState();
  }catch(e){catalogStatus.textContent='Каталог временно недоступен';productsEl.innerHTML='<p>Не удалось загрузить каталог. Попробуйте обновить страницу.</p>';console.error(e)}
})();
