let products=[];
let view=[];
let page=1;
const PAGE=24;
let cart=readStoredArray('ps-cart');
let favorites=new Set(readStoredArray('ps-favorites').map(String));
let customerState={logged:false,csrf:''};
let restoredFacetValues=null;
let selectedSubcategory="";

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
function productText(p){return plain([p.name,p.brand,p.model,p.cat,p.rawCat,p.departmentLabel,p.pathText,p.description,Object.entries(p.specs||{}).flat().join(' ')].join(' '))}

const accessoryStems=['чехол','сумк','багажник','крыл','фонар','звонок','замок','покрыш','камер','насос','держател','креплен','корзин','зеркал','седл','сиден','педал','грипс','трос','цеп','кассет','звезд','переключ','тормоз','обод','вилк','рам','втулк','спиц','поднож','подстав','крепеж','адаптер','палк','ботин','маск','очк','перчат','защит','колес','подшип','ось','амортиз','ремкомплект','запчаст'];
const typeTerms={
  bicycle:['электровелосипед','велосипед'], scooter:['электросамокат','самокат'], skis:['лыж'], snowboard:['сноуборд'], skates:['коньк'], rollers:['роликов','ролик'], skateboard:['скейтборд'], longboard:['лонгборд'], sled:['санк'], snow_scooter:['снегокат'], tubing:['тюбинг'], helmet:['шлем'], backpack:['рюкзак'], tent:['палатк'], sleeping_bag:['спальник','спальный мешок'], trampoline:['батут'], treadmill:['беговая дорожк'], exercise_bike:['велотренажер'], elliptical:['эллипс','эллиптическ'], dumbbell:['гантел'], barbell:['штанг'], kettlebell:['гиря','гири','гирь'], racket:['ракетк'], ball:['мяч'], hockey_stick:['клюшк'], pool:['бассейн'], sup:['сап','sup'], kayak:['каяк'], boat:['лодк']
};
function containsAccessory(s){return accessoryStems.some(st=>s.includes(st))}
function intentFor(raw){
  const qv=canonicalQuery(raw);
  if(!qv)return'';
  const pairs=[['bicycle',['велосипед','электровелосипед']],['scooter',['самокат','электросамокат']],['skis',['лыж']],['snowboard',['сноуборд']],['skates',['коньк']],['rollers',['ролик']],['skateboard',['скейтборд']],['longboard',['лонгборд']],['sled',['санк']],['snow_scooter',['снегокат']],['tubing',['тюбинг']],['helmet',['шлем']],['backpack',['рюкзак']],['tent',['палатк']],['sleeping_bag',['спальник','спальный мешок']],['trampoline',['батут']],['treadmill',['беговая дорожк']],['exercise_bike',['велотренажер']],['elliptical',['эллипс','эллиптическ']],['dumbbell',['гантел']],['barbell',['штанг']],['kettlebell',['гиря','гири','гирь']],['racket',['ракетк']],['ball',['мяч']],['hockey_stick',['клюшк']],['pool',['бассейн']],['sup',['сап','sup']],['kayak',['каяк']],['boat',['лодк']]];
  for(const [id,terms] of pairs){
    if(terms.some(t=>qv.startsWith(t))){
      if(!['helmet','backpack'].includes(id)&&containsAccessory(qv))return'';
      return id;
    }
  }
  return'';
}
function isPrimaryProduct(p,intent){
  if(!intent)return true;
  if(p.productType)return p.productType===intent;
  const n=plain(p.name||''),terms=typeTerms[intent]||[];
  let pos=Infinity;
  for(const t of terms){const i=n.indexOf(t);if(i>=0)pos=Math.min(pos,i)}
  if(!Number.isFinite(pos))return false;
  const before=n.slice(0,pos);
  if(containsAccessory(before)||/(?:^|\s)для\s*$/.test(before))return false;
  return pos<=48;
}
function relevanceScore(p,qv,intent){
  const c=canonicalQuery(qv),n=plain(p.name||''),brand=plain(p.brand||''),model=plain(p.model||''),cat=plain(p.cat||''),path=plain(p.pathText||'');
  let score=0;
  if(intent&&isPrimaryProduct(p,intent))score+=1200;
  if(n===c)score+=1000;else if(n.startsWith(c))score+=650;else if(n.includes(c))score+=420;
  if(brand===c||model===c)score+=500;else if(brand.startsWith(c)||model.startsWith(c))score+=280;
  if(cat.includes(c))score+=120;if(path.includes(c))score+=80;
  return score;
}
function bestQuery(raw){
  const vars=queryVariants(raw);if(!vars.length)return'';
  for(const qv of vars){const intent=intentFor(qv);if(products.some(p=>(!intent||isPrimaryProduct(p,intent))&&productText(p).includes(qv)))return qv}
  return vars[0];
}

const productsEl=$('#products'),catalogStatus=$('#catalogStatus'),category=$('#category'),brandFilter=$('#brandFilter'),stockOnly=$('#stockOnly'),saleOnly=$('#saleOnly'),minPrice=$('#minPrice'),maxPrice=$('#maxPrice'),sort=$('#sort');
const dynamicFilters=$('#dynamicFilters'),facet1=$('#facet1'),facet2=$('#facet2');
const q=$('#q'),mobileQ=$('#mobileQ'),pageInfo=$('#pageInfo'),prevPage=$('#prevPage'),nextPage=$('#nextPage');
const cartEl=$('#cart'),cartItems=$('#cartItems'),count=$('#count'),total=$('#total');

const FACET_SCHEMAS={
  bicycle:[['wheel','Диаметр колёс'],['frame','Размер рамы']],
  skiing:[['length','Ростовка']],
  snowboard:[['length','Ростовка']],
  skates:[['size','Размер']],
  rollers:[['size','Размер']]
};
const INTENT_DEPARTMENT={bicycle:'bicycle',scooter:'scooter',skis:'skiing',snowboard:'snowboard',skates:'skates',rollers:'rollers',skateboard:'boards',longboard:'boards',sled:'winter',snow_scooter:'winter',tubing:'winter',treadmill:'fitness',exercise_bike:'fitness',elliptical:'fitness',dumbbell:'fitness',barbell:'fitness',kettlebell:'fitness',racket:'team',ball:'team',hockey_stick:'team',tent:'tourism',sleeping_bag:'tourism',backpack:'tourism',pool:'water',sup:'water',kayak:'water',boat:'water'};
function sortFacetValues(values){return values.sort((a,b)=>{const na=parseFloat(a),nb=parseFloat(b);return Number.isFinite(na)&&Number.isFinite(nb)?na-nb:String(a).localeCompare(String(b),'ru')})}
function contextDepartment(raw,intent){return category?.value||INTENT_DEPARTMENT[intent||intentFor(raw)]||''}
function contextProducts(raw,intent){const dep=contextDepartment(raw,intent);let list=dep?products.filter(p=>p.department===dep):products;if(intent)list=list.filter(p=>isPrimaryProduct(p,intent));return list}
function fillFacetSelect(sel,def,list){
  if(!sel)return;
  if(!def){sel.hidden=true;sel.dataset.key='';sel.innerHTML='';return}
  const [key,label]=def,prev=sel.dataset.key===key?sel.value:'';
  const values=sortFacetValues([...new Set(list.map(p=>p.facets?.[key]).filter(Boolean))]);
  sel.dataset.key=key;sel.innerHTML=`<option value="">${esc(label)}: все</option>`+values.map(v=>`<option value="${esc(v)}">${esc(v)}</option>`).join('');sel.hidden=!values.length;
  if(prev&&values.includes(prev))sel.value=prev;
}
function refreshBrandOptions(list){
  if(!brandFilter)return;const prev=brandFilter.value;
  const values=[...new Set(list.map(p=>String(p.brand||'').trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'ru'));
  brandFilter.innerHTML='<option value="">Все бренды</option>'+values.map(v=>`<option value="${esc(v)}">${esc(v)}</option>`).join('');
  if(prev&&values.includes(prev))brandFilter.value=prev;
}
function configureContextFilters(raw='',intent=''){
  const dep=contextDepartment(raw,intent),list=contextProducts(raw,intent),schema=FACET_SCHEMAS[dep]||[];
  refreshBrandOptions(list);
  fillFacetSelect(facet1,schema[0],list);fillFacetSelect(facet2,schema[1],list);
  if(dynamicFilters)dynamicFilters.hidden=![facet1,facet2].some(x=>x&&!x.hidden);
  if(restoredFacetValues){
    const [a,b]=restoredFacetValues;if(a&&facet1?.querySelector(`option[value="${CSS.escape(a)}"]`))facet1.value=a;if(b&&facet2?.querySelector(`option[value="${CSS.escape(b)}"]`))facet2.value=b;restoredFacetValues=null;
  }
}

function imageCandidates(p){
  const raw=[p.image,...(Array.isArray(p.images)?p.images:[])].filter(Boolean);
  return [...new Set(raw.flatMap(src=>{src=String(src);const out=[src];if(src.startsWith('/import/'))out.push('api/product-image.php?p='+encodeURIComponent(src.slice(8)));if(src.startsWith('import/'))out.push('api/product-image.php?p='+encodeURIComponent(src.slice(7)));return out}))];
}
function bindProductImages(){
  $$('img.productImg').forEach(img=>{img.onerror=()=>{let a=[];try{a=JSON.parse(decodeURIComponent(img.dataset.fallbacks||'%5B%5D'))}catch(e){}const next=a.shift();if(next){img.dataset.fallbacks=encodeURIComponent(JSON.stringify(a));img.src=next;return}const ph=document.createElement('div');ph.className='imagePlaceholder';ph.textContent='Фото уточняется';img.replaceWith(ph)}});
}
function favoriteMarkup(id){const active=favorites.has(String(id));return `<button type="button" class="productFavorite${active?' active':''}" data-favorite-id="${esc(encodeURIComponent(id))}" aria-label="В избранное">${active?'♥':'♡'}</button>`}
function productHighlights(p){
  const values=[];
  const labels={wheel:'Колёса',frame:'Рама',length:'Ростовка',size:'Размер'};
  for(const [k,v] of Object.entries(p.facets||{}))if(v)values.push(`${labels[k]||k}: ${v}`);
  for(const [k,v] of catalogSpecEntries(p.specs||{})){
    if(values.length>=4)break;
    if(!v||typeof v==='object'||!/тормоз|скорост|вилка|материал|переключател|вес|мощност|емкость|напряжен/i.test(k))continue;
    const line=`${k}: ${v}`;if(line.length<=140)values.push(line);
  }
  return values.slice(0,4);
}
function highlightMarkup(p){const values=productHighlights(p);return values.length?`<ul class="productFacts">${values.map(v=>`<li>${esc(v)}</li>`).join('')}</ul>`:''}
function openQuickView(id){
  const p=products.find(p=>String(p.id)===String(id));if(!p)return;
  const dialog=$('#quickView'),imgs=imageCandidates(p),first=imgs.shift();
  $('#quickContent').innerHTML=`<div class="quickLayout"><div class="quickPhoto">${first?`<img class="productImg" src="${esc(first)}" data-fallbacks="${esc(encodeURIComponent(JSON.stringify(imgs)))}" alt="${esc(p.name)}">`:'<p>Фото уточняется</p>'}</div><div><small>${esc(p.cat)}</small><h2 id="quickTitle">${esc(p.name)}</h2>${highlightMarkup(p)}<span class="stock ${p.stockCode==='out'?'out':''}">${esc(p.stock)}</span><div class="price">${rub(p.price)}</div><button type="button" id="quickAdd" ${p.stockCode==='out'?'disabled':''}>В корзину</button><a class="quickDetail" href="product.html?id=${encodeURIComponent(p.id)}">Все характеристики и отзывы →</a></div></div>`;
  bindProductImages();$('#quickAdd').onclick=()=>{dialog.close();add(p.id)};dialog.showModal();syncBodyLock();
}
function render(list=view){
  view=list;
  const pages=Math.max(1,Math.ceil(view.length/PAGE));page=Math.min(Math.max(1,page),pages);
  const show=view.slice((page-1)*PAGE,page*PAGE);
  productsEl.dataset.catalogReady='1';
  productsEl.innerHTML=show.map(p=>{const imgs=imageCandidates(p),first=imgs.shift(),discount=p.oldPrice&&p.oldPrice>p.price?Math.round((1-p.price/p.oldPrice)*100):0;return `<article class="product">${favoriteMarkup(p.id)}<a href="product.html?id=${encodeURIComponent(p.id)}"><div class="photo">${first?`<img class="productImg" src="${esc(first)}" data-fallbacks="${esc(encodeURIComponent(JSON.stringify(imgs)))}" loading="lazy" decoding="async" width="320" height="240" alt="${esc(p.name)}">`:'<div class="imagePlaceholder">Фото уточняется</div>'}</div><small>${esc(p.cat)}</small><h3>${esc(p.name)}</h3></a>${highlightMarkup(p)}<button type="button" class="quickViewButton" data-quick-id="${esc(encodeURIComponent(p.id))}" aria-label="Быстрый просмотр: ${esc(p.name)}">Быстрый просмотр</button><span class="stock ${p.stockCode==='out'?'out':''}">${esc(p.stock)}</span>${p.oldPrice?`<del>${rub(p.oldPrice)}</del>`:''}<div class="price">${rub(p.price)}</div>${discount?`<span class="productDiscount">−${discount}%</span>`:''}<button class="addBtn" data-id="${esc(encodeURIComponent(p.id))}" ${p.stockCode==='out'?'disabled':''}>${p.stockCode==='out'?'Нет в наличии':'В корзину'}</button></article>`}).join('')||'<p>Ничего не найдено.</p>';
  bindProductImages();
  $$('.quickViewButton').forEach(b=>b.onclick=()=>openQuickView(decodeURIComponent(b.dataset.quickId)));
  $$('.addBtn:not([disabled])').forEach(b=>b.onclick=()=>add(decodeURIComponent(b.dataset.id)));
  $$('.productFavorite').forEach(b=>b.onclick=()=>toggleFavorite(decodeURIComponent(b.dataset.favoriteId),b));
  catalogStatus.textContent=`${view.length} товаров`;if($('#mobileSort'))$('#mobileSort').value=sort.value;if($('#filterDialog').open)$('#applyFilters').textContent=`Показать товары (${view.length})`;pageInfo.textContent=`${page} / ${pages}`;prevPage.disabled=page<=1;nextPage.disabled=page>=pages;
}

function syncState(){
  const u=new URL(location.href),qv=(q?.value||mobileQ?.value||'').trim();
  qv?u.searchParams.set('q',qv):u.searchParams.delete('q');
  category.value?u.searchParams.set('cat',category.value):u.searchParams.delete('cat');
  brandFilter.value?u.searchParams.set('brand',brandFilter.value):u.searchParams.delete('brand');
  stockOnly.checked?u.searchParams.set('stock','1'):u.searchParams.delete('stock');
  saleOnly.checked?u.searchParams.set('sale','1'):u.searchParams.delete('sale');
  minPrice.value?u.searchParams.set('min',minPrice.value):u.searchParams.delete('min');maxPrice.value?u.searchParams.set('max',maxPrice.value):u.searchParams.delete('max');
  sort.value!=='popular'?u.searchParams.set('sort',sort.value):u.searchParams.delete('sort');page>1?u.searchParams.set('page',page):u.searchParams.delete('page');
  facet1?.value?u.searchParams.set('f1',facet1.value):u.searchParams.delete('f1');facet2?.value?u.searchParams.set('f2',facet2.value):u.searchParams.delete('f2');
  selectedSubcategory?u.searchParams.set("sub",selectedSubcategory):u.searchParams.delete("sub");
  history.replaceState(null,'',u);
}
function apply(resetPage=true,sync=true){
  let list=[...products],c=category.value,min=+minPrice.value||0,max=+maxPrice.value||Infinity,raw=(q?.value||mobileQ?.value||'').trim();
  const qv=bestQuery(raw),intent=intentFor(qv||raw);
  if(q&&mobileQ){q.value=raw;mobileQ.value=raw}
  configureContextFilters(raw,intent);
  if(c)list=list.filter(p=>p.department===c);
  if(selectedSubcategory)list=list.filter(p=>(p.rawCat||p.cat)===selectedSubcategory);
  if(qv){const terms=qv.split(' ').filter(Boolean);list=list.filter(p=>(!intent||isPrimaryProduct(p,intent))&&terms.every(t=>productText(p).includes(t)));if(sort.value==='popular')list.sort((a,b)=>relevanceScore(b,qv,intent)-relevanceScore(a,qv,intent))}
  if(brandFilter.value)list=list.filter(p=>p.brand===brandFilter.value);
  if(stockOnly.checked)list=list.filter(p=>p.stockCode==='in');
  if(saleOnly.checked)list=list.filter(p=>Number(p.oldPrice)>Number(p.price)&&Number(p.price)>0);
  for(const sel of [facet1,facet2]){const key=sel?.dataset.key,val=sel?.value;if(key&&val)list=list.filter(p=>p.facets?.[key]===val)}
  list=list.filter(p=>p.price>=min&&p.price<=max);
  if(sort.value==='priceAsc')list.sort((a,b)=>a.price-b.price);if(sort.value==='priceDesc')list.sort((a,b)=>b.price-a.price);if(sort.value==='name')list.sort((a,b)=>a.name.localeCompare(b.name,'ru'));
  if(resetPage)page=1;render(list);renderFilterChips();renderCategoryShortcuts();if(sync)syncState();
}
window.apply=apply;

function add(id){cart.push(id);saveCart();toggleCart(true)}
function saveCart(){localStorage.setItem('ps-cart',JSON.stringify(cart));count.textContent=cart.length;renderCart()}
function renderCart(){const groups=new Map;cart.forEach(id=>groups.set(String(id),(groups.get(String(id))||0)+1));let sum=0;cartItems.innerHTML=[...groups].map(([id,qty])=>{const p=products.find(x=>String(x.id)===id);if(!p)return'';sum+=p.price*qty;return `<div class="cartrow"><span>${esc(p.name)}<br><b>${rub(p.price)}</b></span><div class="cartQty"><button onclick="changeQty('${encodeURIComponent(id)}',-1)">−</button><b>${qty}</b><button onclick="changeQty('${encodeURIComponent(id)}',1)">+</button></div></div>`}).join('')||'<p>Корзина пока пуста</p>';total.textContent=rub(sum)}
function changeQty(encoded,delta){const id=decodeURIComponent(encoded);if(delta>0)cart.push(id);else{const i=cart.findIndex(x=>String(x)===id);if(i>=0)cart.splice(i,1)}saveCart()}
function toggleCart(force){const open=force===undefined?!cartEl.open:force;cartEl.classList.toggle('open',open);if(open&&!cartEl.open)cartEl.showModal();if(!open&&cartEl.open)cartEl.close();syncBodyLock()}
window.changeQty=changeQty;window.toggleCart=toggleCart;

function syncBodyLock(){document.body.style.overflow=document.querySelector('dialog[open]')?'hidden':''}
function toggleMenu(force){const menu=$('#mobileMenu');const open=force===undefined?!menu.open:force;menu.classList.toggle('open',open);if(open&&!menu.open)menu.showModal();if(!open&&menu.open)menu.close();syncBodyLock()}


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
  if(!input||!box)return;
  const draw=()=>{
    const raw=input.value.trim(),found=suggestionItems(raw);
    if(raw.length<2){
      const deps=[...new Map(products.map(p=>[p.department,p.departmentLabel])).entries()].slice(0,8);
      box.innerHTML=`<div class="suggestHeading">Разделы каталога</div><div class="suggestCategories">${deps.map(([key,label])=>`<a href="?cat=${encodeURIComponent(key)}#catalogProducts">${esc(label)}</a>`).join('')}</div><a href="service.html"><span>Мастерская</span><b>Выбрать работы и записаться →</b></a>`;
    }else{
      box.innerHTML=`<div class="suggestHeading">Товары по вашему запросу</div>`+(found.length?found.map(p=>`<a href="product.html?id=${encodeURIComponent(p.id)}">${p.image?`<img src="${esc(p.image)}" alt="" loading="lazy">`:'<span class="suggestPh"></span>'}<span><b>${esc(p.name)}</b><small>${esc([p.brand,p.cat].filter(Boolean).join(' · '))}</small></span><strong>${rub(p.price)}</strong></a>`).join(''):'<p class="suggestHeading">Точных совпадений нет. Попробуйте название или модель.</p>')+`<a class="suggestAll" href="?q=${encodeURIComponent(raw)}#catalogProducts">Все результаты →</a>`;
    }
    box.classList.add('open');input.setAttribute('aria-expanded','true');
  };
  input.setAttribute('aria-controls',box.id);input.setAttribute('aria-expanded','false');input.setAttribute('aria-label','Поиск по каталогу');
  input.addEventListener('input',draw);input.addEventListener('focus',draw);
  input.addEventListener('keydown',e=>{if(e.key==='ArrowDown'){e.preventDefault();box.querySelector('a')?.focus()}if(e.key==='Escape'){box.classList.remove('open');input.setAttribute('aria-expanded','false')}});
  box.addEventListener('keydown',e=>{if(e.key==='Escape'){input.focus();box.classList.remove('open');input.setAttribute('aria-expanded','false')}});
  document.addEventListener('click',e=>{if(e.target!==input&&!box.contains(e.target)){box.classList.remove('open');input.setAttribute('aria-expanded','false')}});
}

function buildMega(){
  if(matchMedia('(max-width:850px)').matches)return;
  const panel=$('#megaCatalog');if(!panel)return;const groups=new Map();
  for(const p of products){const top=p.departmentLabel||'Каталог',sub=p.rawCat||p.cat||'';if(!groups.has(top))groups.set(top,new Map());if(sub)groups.get(top).set(sub,(groups.get(top).get(sub)||0)+1)}
  const ranked=[...groups.entries()].sort((a,b)=>[...b[1].values()].reduce((x,y)=>x+y,0)-[...a[1].values()].reduce((x,y)=>x+y,0)).slice(0,9);
  panel.innerHTML=ranked.map(([top,subs])=>`<section class="megaGroup"><h3>${esc(top)}</h3>${[...subs.entries()].sort((a,b)=>b[1]-a[1]).slice(0,6).map(([s,c])=>`<a href="#catalogProducts" data-mega-term="${esc(s)}">${esc(s)} <small>(${c})</small></a>`).join('')}</section>`).join('')+'<a class="megaService" href="service.html"><b>Мастерская ПрофиСпорт</b><span>Диагностика, настройка и ремонт →</span></a><a class="megaService" href="service.html#bikeGuide"><b>Как устроен велосипед</b><span>Узлы и помощь с выбором работ →</span></a><a class="megaAll" href="#catalogProducts" data-mega-all>Весь каталог →</a>';
  panel.querySelectorAll('[data-mega-term]').forEach(a=>a.onclick=e=>{e.preventDefault();selectedSubcategory=a.dataset.megaTerm||'';q.value='';mobileQ.value='';category.value='';apply(true,true);closeMega();$('#catalogProducts').scrollIntoView({behavior:'smooth'})});panel.querySelector('[data-mega-all]')?.addEventListener('click',()=>{$('#resetFilters').click();closeMega()});
}
function openMega(){if(matchMedia('(max-width:850px)').matches)return;$('#megaCatalog')?.classList.add('open');$('#megaBackdrop')?.classList.add('open')}
function closeMega(){$('#megaCatalog')?.classList.remove('open');$('#megaBackdrop')?.classList.remove('open')}

function selectDepartment(key){
  category.value=key;selectedSubcategory='';q.value='';mobileQ.value='';brandFilter.value='';facet1.value='';facet2.value='';apply();$('#catalogProducts').scrollIntoView({behavior:'smooth'});
}
function buildBrandShortcuts(){
  const counts=new Map();for(const p of products){if(p.brand)counts.set(p.brand,(counts.get(p.brand)||0)+1)}
  const brands=[...counts].sort((a,b)=>b[1]-a[1]).slice(0,12);
  $('#brandShowcase').hidden=!brands.length;
  $('#brandShortcuts').innerHTML=brands.map(([brand,count])=>`<a href="?brand=${encodeURIComponent(brand)}#catalogProducts" data-brand="${esc(brand)}"><b>${esc(brand)}</b><span>${count} товаров</span></a>`).join('');
  $$('#brandShortcuts a').forEach(a=>a.onclick=e=>{e.preventDefault();$('#resetFilters').click();brandFilter.value=a.dataset.brand;apply();$('#catalogProducts').scrollIntoView({behavior:'smooth'})});
}
$('#saleShortcut')?.addEventListener('click',e=>{e.preventDefault();$('#resetFilters').click();saleOnly.checked=true;apply();$('#catalogProducts').scrollIntoView({behavior:'smooth'})});
function buildCategoryTiles(){
  const defs=[['bicycle','Велосипеды','Город, прогулки и бездорожье'],['scooter','Самокаты','Для дороги и трюков'],['skiing','Лыжный спорт','Лыжи, ботинки и палки'],['cycling','Запчасти и аксессуары','Обслуживание и ремонт'],['fitness','Фитнес','Тренировки в вашем ритме'],['tourism','Туризм','Всё для новых маршрутов']];
  const available=defs.map(([key,label,note])=>({key,label,note,items:products.filter(p=>p.department===key)})).filter(x=>x.items.length);
  $('#categoryTiles').innerHTML=available.map(({key,label,note,items},i)=>{const p=items.find(p=>p.image?.startsWith('/import/')||p.image?.startsWith('import/'))||items.find(p=>p.image);return `<a href="?cat=${encodeURIComponent(key)}#catalogProducts" class="categoryTile tile${i}" data-department="${esc(key)}"><div><small>${items.length} товаров</small><h3>${esc(label)}</h3><span>${esc(note)}</span></div>${p?`<img src="${esc(p.image)}" alt="" loading="lazy" onerror="this.hidden=true">`:''}<b class="tileArrow" aria-hidden="true">↗</b></a>`}).join('');
  $$('#categoryTiles [data-department]').forEach(a=>a.onclick=e=>{e.preventDefault();selectDepartment(a.dataset.department)});
}
function renderCategoryShortcuts(){
  const dep=category.value||contextDepartment(q.value,intentFor(bestQuery(q.value)));const box=$('#categoryShortcuts');
  if(!dep){box.innerHTML='';return}
  const counts=new Map();for(const p of products.filter(p=>p.department===dep)){const sub=p.rawCat||p.cat;if(sub)counts.set(sub,(counts.get(sub)||0)+1)}
  box.innerHTML=[...counts].sort((a,b)=>b[1]-a[1]).slice(0,10).map(([sub,count])=>`<button type="button" class="${sub===selectedSubcategory?'selected':''}" data-sub="${esc(sub)}">${esc(sub)} <small>${count}</small></button>`).join('');
  box.querySelectorAll('button').forEach(b=>b.onclick=()=>{selectedSubcategory=selectedSubcategory===b.dataset.sub?'':b.dataset.sub;apply()});
}
function renderFilterChips(){
  const chips=[];
  if(q.value)chips.push(['query','Поиск: '+q.value]);
  if(category.value)chips.push(['category',category.selectedOptions[0].text]);
  if(selectedSubcategory)chips.push(['subcategory',selectedSubcategory]);
  if(brandFilter.value)chips.push(['brand',brandFilter.value]);
  if(stockOnly.checked)chips.push(['stock','В наличии']);
  if(saleOnly.checked)chips.push(['sale','Со скидкой']);
  if(minPrice.value)chips.push(['min','От '+rub(minPrice.value)]);
  if(maxPrice.value)chips.push(['max','До '+rub(maxPrice.value)]);
  for(const [id,sel] of [['f1',facet1],['f2',facet2]])if(sel?.value)chips.push([id,sel.options[0].text.replace(': все','')+': '+sel.value]);
  $('#filterCount').textContent=chips.length?String(chips.length):'';
  $('#activeFilters').innerHTML=chips.map(([id,label])=>`<button type="button" class="selected" data-clear="${id}" aria-label="Убрать фильтр: ${esc(label)}">${esc(label)} <span aria-hidden="true">×</span></button>`).join('')+(chips.length?'<button type="button" data-clear="all">Очистить всё</button>':'');
  $$('#activeFilters button').forEach(b=>b.onclick=()=>{
    const id=b.dataset.clear;
    if(id==='all'){$('#resetFilters').click();return}
    if(id==='query'){q.value='';mobileQ.value=''}if(id==='category'){category.value='';selectedSubcategory=''}if(id==='subcategory')selectedSubcategory='';if(id==='stock')stockOnly.checked=false;if(id==='sale')saleOnly.checked=false;
    const input={brand:brandFilter,min:minPrice,max:maxPrice,f1:facet1,f2:facet2}[id];if(input)input.value='';apply();
  });
}
$('#quickView .dialogClose')?.addEventListener('click',()=>$('#quickView').close());
$('#quickView')?.addEventListener('click',e=>{if(e.target===$('#quickView')){const r=e.target.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)e.target.close()}});
$$('[data-budget]').forEach(b=>b.onclick=()=>{minPrice.value='';maxPrice.value=b.dataset.budget;apply()});

function setupHero(){
  const slider=$('#heroSlider'),track=slider?.querySelector('.heroTrack'),slides=$$('.heroSlide'),dots=$$('#heroSlider .dots button');if(!slider||!track||!slides.length)return;
  let current=0,startX=0,startY=0,dx=0,dy=0,timer;
  const setSlide=(n,restart=true)=>{const mobile=matchMedia('(max-width:850px)').matches;current=mobile?0:(n+slides.length)%slides.length;slides.forEach((s,i)=>s.classList.toggle('is-active',i===current));dots.forEach((d,i)=>d.classList.toggle('active',i===current));track.style.transform=mobile?'none':`translateX(${-current*100}%)`;if(restart){clearInterval(timer);if(!mobile&&!matchMedia('(prefers-reduced-motion:reduce)').matches)timer=setInterval(()=>setSlide(current+1,false),5000)}};
  dots.forEach((d,i)=>d.onclick=()=>setSlide(i));slider.addEventListener('touchstart',e=>{startX=e.touches[0].clientX;startY=e.touches[0].clientY;dx=dy=0;clearInterval(timer)},{passive:true});slider.addEventListener('touchmove',e=>{dx=e.touches[0].clientX-startX;dy=e.touches[0].clientY-startY},{passive:true});slider.addEventListener('touchend',()=>{if(Math.abs(dx)>45&&Math.abs(dx)>Math.abs(dy)*1.2)setSlide(current+(dx<0?1:-1));else setSlide(current)},{passive:true});window.addEventListener('resize',()=>setSlide(current,true),{passive:true});setSlide(0);
}

function populateFilters(){
  const deps=new Map();for(const p of products){if(!deps.has(p.department))deps.set(p.department,{label:p.departmentLabel||p.department,order:p.departmentOrder||999})}
  [...deps.entries()].sort((a,b)=>a[1].order-b[1].order||a[1].label.localeCompare(b[1].label,'ru')).forEach(([key,v])=>category.add(new Option(v.label,key)));
  refreshBrandOptions(products);
}
function restoreState(){selectedSubcategory=new URLSearchParams(location.search).get('sub')||'';const sp=new URLSearchParams(location.search),initialQ=sp.get('q')||'';q.value=initialQ;mobileQ.value=initialQ;category.value=sp.get('cat')||'';brandFilter.value=sp.get('brand')||'';stockOnly.checked=sp.get('stock')==='1';saleOnly.checked=sp.get('sale')==='1';minPrice.value=sp.get('min')||'';maxPrice.value=sp.get('max')||'';sort.value=sp.get('sort')||'popular';page=Math.max(1,+sp.get('page')||1);restoredFacetValues=[sp.get('f1')||'',sp.get('f2')||'']}

$('#cartBtn')?.addEventListener('click',()=>toggleCart());$('#searchBtn')?.addEventListener('click',()=>{toggleMenu(false);mobileQ?.scrollIntoView({behavior:'smooth',block:'center'});setTimeout(()=>mobileQ?.focus({preventScroll:true}),250)});$('#menuBtn')?.addEventListener('click',()=>toggleMenu(true));$('#menuClose')?.addEventListener('click',()=>toggleMenu(false));$('#menuBackdrop')?.addEventListener('click',()=>toggleMenu(false));$$('[data-menu-close]').forEach(a=>a.addEventListener('click',()=>toggleMenu(false)));document.addEventListener('keydown',e=>{if(e.key==='Escape'){toggleMenu(false);toggleCart(false);closeMega()}});$('.checkout')?.addEventListener('click',()=>{if(cart.length)location.href='checkout.html'});
$('#applyFilters')?.addEventListener('click',e=>{e?.preventDefault();apply(true,true);$('#filterDialog').close()});$('#resetFilters')?.addEventListener('click',e=>{e?.preventDefault();selectedSubcategory='';category.value='';brandFilter.value='';stockOnly.checked=false;saleOnly.checked=false;minPrice.value='';maxPrice.value='';sort.value='popular';q.value='';mobileQ.value='';if(facet1)facet1.value='';if(facet2)facet2.value='';page=1;configureContextFilters('', '');render(products);renderFilterChips();renderCategoryShortcuts();syncState()});
[category,brandFilter,sort,stockOnly,saleOnly,facet1,facet2].forEach(el=>el?.addEventListener('change',()=>{if(el===category)selectedSubcategory='';apply(true,true)}));
prevPage?.addEventListener('click',()=>{if(page>1){page--;render(view);syncState();productsEl.scrollIntoView()}});nextPage?.addEventListener('click',()=>{if(page*PAGE<view.length){page++;render(view);syncState();productsEl.scrollIntoView()}});
$$('[data-category]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();const term=a.dataset.category||'';selectedSubcategory='';toggleMenu(false);category.value='';q.value=term;mobileQ.value=term;apply(true,true);productsEl.scrollIntoView({behavior:'smooth'})}));
$('#search')?.addEventListener('submit',e=>{e.preventDefault();mobileQ.value=q.value;selectedSubcategory='';apply(true,true);$('#desktopSuggest')?.classList.remove('open');productsEl.scrollIntoView({behavior:'smooth'})});$('#mobileSearch')?.addEventListener('submit',e=>{e.preventDefault();q.value=mobileQ.value;selectedSubcategory='';apply(true,true);$('#mobileSuggest')?.classList.remove('open');productsEl.scrollIntoView({behavior:'smooth'})});
$('#desktopCatalogLink')?.addEventListener('click',e=>{e.preventDefault();$('#megaCatalog')?.classList.contains('open')?closeMega():openMega()});$('#megaBackdrop')?.addEventListener('click',closeMega);
$('#pick')?.addEventListener('click',()=>{const h=+$('#height').value,b=+$('#budget').value;if(!h||!b){$('#pickResult').textContent='Укажите рост и бюджет.';return}const frame=h<165?'S':h<178?'M':h<188?'L':'XL',matches=products.filter(p=>p.productType==='bicycle'&&p.price<=b&&p.stockCode!=='out');$('#pickResult').innerHTML=`Рекомендуемый размер рамы: <b>${frame}</b>. Подходящих по бюджету: <b>${matches.length}</b>.`;$('#resetFilters').click();category.value='bicycle';maxPrice.value=String(b);stockOnly.checked=true;apply();$('#pickerDialog').close();$('#catalogProducts').scrollIntoView({behavior:'smooth'})});

// Move the same controls into native mobile dialogs; never clone IDs or filter state.
const mobileLayout=matchMedia('(max-width:850px)');
const pickerHome=document.createElement('div');pickerHome.id='pickerHome';
$('#picker').parentNode.insertBefore(pickerHome,$('#picker'));pickerHome.append($('#picker'));
function syncMobileLayout(){
  const filters=$('#filterControls'),picker=$('#picker');
  if(mobileLayout.matches){$('#filterDialogBody').append(filters);$('#filterDialogFooter').append($('#filterActions'));$('#pickerDialog').append(picker)}
  else{$('#filterDialog').close();$('#pickerDialog').close();$('#filterHome').append(filters);filters.querySelector('.filters').append($('#filterActions'));pickerHome.append(picker);$('#applyFilters').textContent='Показать'}
  syncBodyLock();
}
$('#openFilters').onclick=()=>{$('#applyFilters').textContent=`Показать товары (${view.length})`;$('#filterDialog').showModal();syncBodyLock()};
$('#closeFilters').onclick=()=>$('#filterDialog').close();
$('#mobileSort').onchange=()=>{sort.value=$('#mobileSort').value;apply()};
$('#mobileCartBtn').onclick=e=>{e.preventDefault();toggleCart(true)};
$$('a[href="#picker"]').forEach(a=>a.addEventListener('click',e=>{if(mobileLayout.matches){e.preventDefault();toggleMenu(false);$('#pickerDialog').showModal();syncBodyLock()}}));
$('#pickerDialog .dialogClose').onclick=()=>$('#pickerDialog').close();
$$('dialog').forEach(dialog=>dialog.addEventListener('close',()=>{dialog.classList.remove('open');syncBodyLock()}));
$('#quickView').addEventListener('close',syncBodyLock);
mobileLayout.addEventListener?.('change',syncMobileLayout);syncMobileLayout();
if(mobileLayout.matches&&location.hash==='#picker'){$('#pickerDialog').showModal();syncBodyLock()}

setupHero();
bindSuggest(q,$('#desktopSuggest'));bindSuggest(mobileQ,$('#mobileSuggest'));

(async()=>{
  try{
    catalogStatus.textContent='Загрузка каталога…';
    products=await loadRealCatalog();if(!products.length)throw Error('empty');
    populateFilters();restoreState();configureContextFilters(q.value,intentFor(q.value));apply(false,false);saveCart();buildMega();buildCategoryTiles();buildBrandShortcuts();loadCustomerState();
  }catch(e){catalogStatus.textContent='Каталог временно недоступен';productsEl.innerHTML='<p>Не удалось загрузить каталог. Попробуйте обновить страницу.</p>';console.error(e)}
})();
