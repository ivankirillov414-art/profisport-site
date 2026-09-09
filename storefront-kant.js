(()=>{
const rub=n=>new Intl.NumberFormat('ru-RU').format(n||0)+' ₽';
const esc=s=>String(s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const readArray=k=>{try{const v=JSON.parse(localStorage.getItem(k)||'[]');return Array.isArray(v)?v:[]}catch(e){return[]}};
const ruMap={'q':'й','w':'ц','e':'у','r':'к','t':'е','y':'н','u':'г','i':'ш','o':'щ','p':'з','[':'х',']':'ъ','a':'ф','s':'ы','d':'в','f':'а','g':'п','h':'р','j':'о','k':'л','l':'д',';':'ж',"'":'э','z':'я','x':'ч','c':'с','v':'м','b':'и','n':'т','m':'ь',',':'б','.':'ю'};
const enMap=Object.fromEntries(Object.entries(ruMap).map(([a,b])=>[b,a]));
const swap=(s,map)=>[...String(s||'').toLowerCase()].map(ch=>map[ch]||ch).join('');
const plain=s=>String(s||'').toLowerCase().trim().replace(/ё/g,'е').replace(/[.,;:()[\]{}"']/g,' ').replace(/\s+/g,' ');
const synonyms=[[/велик[а-я]*/g,'велосип'],[/^вел$/g,'велосип'],[/mtb/g,'велосип'],[/горник[а-я]*/g,'горн'],[/запчасти?/g,'запчаст'],[/аксессуары?/g,'аксессуар'],[/лыжи?/g,'лыж'],[/сноуборды?/g,'сноуборд']];
const repair=s=>plain(s).replace(/^велосипедосипед(?=\s|$)/,'велосипед').replace(/^велосипосипед(?=\s|$)/,'велосипед');
const canon=s=>{let x=repair(s);for(const [r,v] of synonyms)x=x.replace(r,v);return x.replace(/\s+/g,' ')};
const variants=q=>[canon(q),canon(swap(q,ruMap)),canon(swap(q,enMap))].filter((v,i,a)=>v&&a.indexOf(v)===i);
const ptext=p=>canon([p.name,p.brand,p.model,p.cat,p.pathText,p.description,Object.entries(p.specs||{}).flat().join(' ')].join(' '));
const accessory=/^(?:чехол|сумк|багажник|крыл|фонар|звонок|замок|покрыш|камер|насос|держател|креплен|корзин|зеркал|седл|сиден|педал|грипс|трос|цеп|кассет|звезд|переключ|тормоз|обод|вилк|рам|втулк|спиц|подножк|подстав|крепеж|адаптер|палк|ботинк|маск|очк|перчат|защит|колес|подшип|ось|амортиз|ремкомплект|запчаст|шлем)/;
const rules=[
 ['bicycle',/^(?:электро)?велосип/,/(?:^|\s)(?:электро)?велосипед[а-я-]*(?:\s|$)/],
 ['scooter',/^(?:электро)?самокат/,/(?:^|\s)(?:электро)?самокат[а-я-]*(?:\s|$)/],
 ['skis',/^лыж/,/(?:^|\s)лыж(?:и|а)(?:\s|$)/],
 ['snowboard',/^сноуборд/,/(?:^|\s)сноуборд[а-я-]*(?:\s|$)/],
 ['skates',/^коньк/,/(?:^|\s)коньк[иа][а-я-]*(?:\s|$)/],
 ['rollers',/^(?:ролик|роликов)/,/(?:^|\s)(?:роликов[а-я-]*\s+коньк[иа][а-я-]*|ролик[а-я-]*)(?:\s|$)/],
 ['skateboard',/^скейтборд/,/(?:^|\s)скейтборд[а-я-]*(?:\s|$)/],
 ['longboard',/^лонгборд/,/(?:^|\s)лонгборд[а-я-]*(?:\s|$)/],
 ['sled',/^санк/,/(?:^|\s)санк[а-я-]*(?:\s|$)/],
 ['snow_scooter',/^снегокат/,/(?:^|\s)снегокат[а-я-]*(?:\s|$)/],
 ['tubing',/^тюбинг/,/(?:^|\s)тюбинг[а-я-]*(?:\s|$)/],
 ['helmet',/^шлем/,/(?:^|\s)шлем[а-я-]*(?:\s|$)/],
 ['backpack',/^рюкзак/,/(?:^|\s)рюкзак[а-я-]*(?:\s|$)/],
 ['tent',/^палатк/,/(?:^|\s)палатк[а-я-]*(?:\s|$)/],
 ['sleeping_bag',/^(?:спальник|спальн.*мешок)/,/(?:^|\s)(?:спальник[а-я-]*|спальн[а-я-]*\s+мешок[а-я-]*)(?:\s|$)/],
 ['trampoline',/^батут/,/(?:^|\s)батут[а-я-]*(?:\s|$)/],
 ['treadmill',/^бегов.*дорожк/,/(?:^|\s)бегов[а-я-]*\s+дорожк[а-я-]*(?:\s|$)/],
 ['exercise_bike',/^велотренажер/,/(?:^|\s)велотренажер[а-я-]*(?:\s|$)/],
 ['elliptical',/^(?:эллипс|эллиптическ)/,/(?:^|\s)(?:эллипс[а-я-]*|эллиптическ[а-я-]*\s+тренажер[а-я-]*)(?:\s|$)/],
 ['dumbbell',/^гантел/,/(?:^|\s)гантел[а-я-]*(?:\s|$)/],['barbell',/^штанг/,/(?:^|\s)штанг[а-я-]*(?:\s|$)/],['kettlebell',/^гир/,/(?:^|\s)гир[а-я-]*(?:\s|$)/],
 ['racket',/^ракетк/,/(?:^|\s)ракетк[а-я-]*(?:\s|$)/],['ball',/^мяч/,/(?:^|\s)мяч[а-я-]*(?:\s|$)/],['hockey_stick',/^клюшк/,/(?:^|\s)клюшк[а-я-]*(?:\s|$)/],
 ['pool',/^бассейн/,/(?:^|\s)бассейн[а-я-]*(?:\s|$)/],['sup',/^(?:сап|sup)(?:\s|$)/,/(?:^|\s)(?:сап|sup)(?:[- ]?борд[а-я-]*)?(?:\s|$)/],['kayak',/^каяк/,/(?:^|\s)каяк[а-я-]*(?:\s|$)/],['boat',/^лодк/,/(?:^|\s)лодк[а-я-]*(?:\s|$)/]
];
function intent(q){const c=canon(q);if(!c)return'';for(const [id,qr] of rules){if(!qr.test(c))continue;if(accessory.test(c)&&!['helmet','backpack'].includes(id))return'';return id}return''}
function isPrimary(p,id){if(!id)return true;const r=rules.find(x=>x[0]===id);if(!r)return true;const n=plain(p.name||'');const m=n.match(r[2]);if(!m)return false;const before=n.slice(0,m.index).trim();return !(accessory.test(before)||/(?:^|\s)(?:для|на|к|под)\s*$/.test(before))}
function score(p,q,id){const c=canon(q),n=canon(p.name||''),b=canon(p.brand||''),m=canon(p.model||''),cat=canon(p.cat||''),path=canon(p.pathText||'');let s=id&&isPrimary(p,id)?1200:0;if(n===c)s+=1000;if(n.startsWith(c))s+=650;else if(n.includes(c))s+=420;if(b===c||m===c)s+=500;else if(b.startsWith(c)||m.startsWith(c))s+=280;if(cat.includes(c))s+=120;if(path.includes(c))s+=80;return s}
function best(q){const vs=variants(q);if(!vs.length)return'';for(const v of vs){const id=intent(v);if(products.some(p=>(!id||isPrimary(p,id))&&ptext(p).includes(v)))return v}return vs[0]}
function imageOf(p){return p.image||((p.images||[])[0])||''}
function waitProducts(cb){let n=0;const t=setInterval(()=>{if(typeof products!=='undefined'&&products.length){clearInterval(t);cb()}else if(++n>120)clearInterval(t)},100)}

function buildSuggestions(input,form){
  if(!input||!form)return;const box=form.querySelector('.smartSuggest');if(!box)return;
  const draw=()=>{const raw=input.value.trim();if(raw.length<2){box.classList.remove('open');box.innerHTML='';return}const qv=best(raw),terms=qv.split(' ').filter(Boolean),id=intent(qv||raw);const found=products.map(p=>({p,t:ptext(p)})).filter(x=>(!id||isPrimary(x.p,id))&&terms.every(t=>x.t.includes(t))).sort((a,b)=>score(b.p,qv,id)-score(a.p,qv,id)).slice(0,7);box.innerHTML=found.length?found.map(({p})=>`<a href="product.html?id=${encodeURIComponent(p.id)}">${imageOf(p)?`<img src="${esc(imageOf(p))}" alt="">`:'<span class="suggestPh"></span>'}<span><b>${esc(p.name)}</b><small>${esc([p.brand,p.cat].filter(Boolean).join(' · '))}</small></span><strong>${rub(p.price)}</strong></a>`).join(''):'<a href="#catalogProducts"><span class="suggestPh"></span><span><b>Ничего точного не найдено</b><small>Показать результаты поиска</small></span><strong>→</strong></a>';box.classList.add('open')};
  input.addEventListener('input',draw);input.addEventListener('focus',draw);document.addEventListener('click',e=>{if(!form.contains(e.target))box.classList.remove('open')});form.addEventListener('submit',()=>box.classList.remove('open'));
}
function installFilters(){
  const brand=document.querySelector('#brandFilter'),stock=document.querySelector('#stockOnly');if(!brand||!stock)return;
  const selected=brand.value;brand.innerHTML='<option value="">Все бренды</option>'+[...new Set(products.map(p=>p.brand).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'ru')).map(b=>`<option value="${esc(b)}">${esc(b)}</option>`).join('');brand.value=selected;
  apply=function(resetPage=true){let list=[...products],c=category.value,min=+minPrice.value||0,max=+maxPrice.value||Infinity,raw=(document.querySelector('#q')?.value||document.querySelector('#mobileQ')?.value||'').trim(),qv=best(raw),id=intent(qv||raw);if(c)list=list.filter(p=>p.cat===c);if(qv){const ts=qv.split(' ').filter(Boolean);list=list.filter(p=>(!id||isPrimary(p,id))&&ts.every(t=>ptext(p).includes(t)));if(sort.value==='popular')list.sort((a,b)=>score(b,qv,id)-score(a,qv,id))}if(brand.value)list=list.filter(p=>p.brand===brand.value);if(stock.checked)list=list.filter(p=>p.stockCode==='in');list=list.filter(p=>p.price>=min&&p.price<=max);if(sort.value==='priceAsc')list.sort((a,b)=>a.price-b.price);if(sort.value==='priceDesc')list.sort((a,b)=>b.price-a.price);if(sort.value==='name')list.sort((a,b)=>a.name.localeCompare(b.name,'ru'));if(resetPage)page=1;render(list)};
  brand.addEventListener('change',()=>apply());stock.addEventListener('change',()=>apply());
}
function buildMega(){
  if(!window.matchMedia('(min-width:851px)').matches)return;const nav=document.querySelector('body>nav:not(.mobileBottomNav)');if(!nav||document.querySelector('#megaCatalog'))return;
  const groups=new Map();for(const p of products){const path=Array.isArray(p.path)?p.path.filter(Boolean):[];const top=path[0]||p.cat||'Каталог',sub=path[1]||p.cat||'';if(!groups.has(top))groups.set(top,new Map());if(sub)groups.get(top).set(sub,(groups.get(top).get(sub)||0)+1)}
  const ranked=[...groups.entries()].sort((a,b)=>[...b[1].values()].reduce((x,y)=>x+y,0)-[...a[1].values()].reduce((x,y)=>x+y,0)).slice(0,9);
  const panel=document.createElement('div');panel.className='megaCatalog';panel.id='megaCatalog';panel.innerHTML=ranked.map(([top,subs])=>`<section class="megaGroup"><h3>${esc(top)}</h3>${[...subs.entries()].sort((a,b)=>b[1]-a[1]).slice(0,6).map(([s,c])=>`<a href="#catalogProducts" data-mega-term="${esc(s)}">${esc(s)} <small>(${c})</small></a>`).join('')}</section>`).join('')+'<a class="megaAll" href="#catalogProducts" data-mega-all>Весь каталог →</a>';
  const back=document.createElement('div');back.className='megaCatalogBackdrop';document.body.append(back,panel);const link=[...nav.querySelectorAll('a')].find(a=>a.textContent.trim()==='Каталог');const close=()=>{panel.classList.remove('open');back.classList.remove('open')};if(link)link.addEventListener('click',e=>{e.preventDefault();panel.classList.toggle('open');back.classList.toggle('open',panel.classList.contains('open'))});back.onclick=close;panel.querySelectorAll('[data-mega-term]').forEach(a=>a.onclick=e=>{e.preventDefault();const term=a.dataset.megaTerm||'';if(document.querySelector('#q'))q.value=term;if(document.querySelector('#mobileQ'))mobileQ.value=term;category.value='';apply();close();document.querySelector('#catalogProducts')?.scrollIntoView({behavior:'smooth'})});
}
async function installFavorites(){
  let fav=readArray('ps-favorites').map(String),csrf='',logged=false;try{const r=await fetch('api/customer.php?action=me',{cache:'no-store'}),j=await r.json();if(r.ok&&j.ok&&j.customer){csrf=j.csrf||'';logged=true;fav=(j.favorites||[]).map(String);localStorage.setItem('ps-favorites',JSON.stringify(fav))}}catch(e){}
  const decorate=()=>document.querySelectorAll('.product').forEach(card=>{if(card.querySelector('.productFavorite'))return;const link=card.querySelector('a[href*="product.html?id="]');if(!link)return;const id=new URL(link.href).searchParams.get('id');if(!id)return;const p=products.find(x=>String(x.id)===String(id));if(p?.oldPrice&&p.oldPrice>p.price){const price=card.querySelector('.price');if(price&&!card.querySelector('.productDiscount'))price.insertAdjacentHTML('afterend',`<span class="productDiscount">−${Math.round((1-p.price/p.oldPrice)*100)}%</span>`)}const b=document.createElement('button');b.type='button';b.className='productFavorite'+(fav.includes(String(id))?' active':'');b.setAttribute('aria-label','В избранное');b.textContent=fav.includes(String(id))?'♥':'♡';b.onclick=async e=>{e.preventDefault();e.stopPropagation();const s=String(id);if(logged){try{const r=await fetch('api/customer.php?action=favorite',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({product_id:Number(id)})}),j=await r.json();if(!r.ok||!j.ok)throw 0;if(j.active&&!fav.includes(s))fav.push(s);if(!j.active)fav=fav.filter(x=>x!==s)}catch(err){location.href='profile.html';return}}else{const i=fav.indexOf(s);if(i>=0)fav.splice(i,1);else fav.push(s)}localStorage.setItem('ps-favorites',JSON.stringify(fav));b.classList.toggle('active',fav.includes(s));b.textContent=fav.includes(s)?'♥':'♡'};card.appendChild(b)});
  const baseRender=render;render=function(list=view){baseRender(list);decorate()};decorate();
}
waitProducts(()=>{const desk=document.querySelector('#q'),mob=document.querySelector('#mobileQ');const current=(desk?.value||mob?.value||'').trim(),fixed=repair(current);if(current&&fixed!==plain(current)){if(desk)desk.value=fixed;if(mob)mob.value=fixed}buildSuggestions(desk,document.querySelector('#search'));buildSuggestions(mob,document.querySelector('#mobileSearch'));installFilters();buildMega();installFavorites()});
})();
