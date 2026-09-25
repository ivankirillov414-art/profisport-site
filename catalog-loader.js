function readStoredArray(key){try{const value=JSON.parse(localStorage.getItem(key)||'[]');return Array.isArray(value)?value.filter(x=>typeof x==='string'||typeof x==='number'):[]}catch(e){return[]}}
function pathOf(p){return p.category_path||p.breadcrumbs||[]}
function textNorm(s){return String(s||'').toLowerCase().replace(/ё/g,'е').replace(/[^a-zа-я0-9.,+"' -]+/gi,' ').replace(/\s+/g,' ').trim()}
function imageUrl(u){
  if(!u)return'';
  u=String(u);
  if(u.startsWith('/import/'))return`api/product-image.php?p=${encodeURIComponent(u.slice(8))}`;
  if(u.startsWith('import/'))return`api/product-image.php?p=${encodeURIComponent(u.slice(7))}`;
  return u;
}

const CATALOG_DEPARTMENTS={
  bicycle:{label:'Велосипеды',order:10},
  scooter:{label:'Самокаты',order:20},
  cycling:{label:'Запчасти',order:30},
  skiing:{label:'Лыжи и экипировка',order:40},
  snowboard:{label:'Сноуборды',order:50},
  skates:{label:'Коньки',order:60},
  rollers:{label:'Ролики',order:70},
  boards:{label:'Скейтборды и доски',order:80},
  fitness:{label:'Фитнес и тренажёры',order:90},
  team:{label:'Игровой спорт',order:100},
  tourism:{label:'Туризм',order:110},
  water:{label:'Водный спорт',order:120},
  winter:{label:'Зимний спорт',order:130},
  accessories:{label:'Спортивные аксессуары',order:140},
  combat:{label:'Единоборства',order:101},
  hockey:{label:'Хоккей',order:61},
  clothing:{label:'Спортивная одежда',order:141},
  walking:{label:'Скандинавская ходьба',order:111},
  other:{label:'Другие товары',order:999}
};
// Home sections collect every department; detail departments remain available in filters.
const CATALOG_SECTIONS={
  bicycle:{label:'Велосипеды',note:'Город, прогулки и бездорожье',icon:'bicycle',departments:['bicycle']},
  scooter:{label:'Самокаты, ролики и скейты',note:'Катание, трюки и комплектующие',icon:'scooter',departments:['scooter','rollers','boards']},
  skiing:{label:'Зимний спорт',note:'Лыжи, сноуборды, коньки и хоккей',icon:'skiing',departments:['skiing','snowboard','skates','winter','hockey']},
  cycling:{label:'Запчасти',note:'Детали для ремонта и обслуживания',icon:'cycling',departments:['cycling']},
  accessories:{label:'Аксессуары',note:'Оснащение, защита и экипировка',icon:'tourism',departments:['accessories','clothing']},
  fitness:{label:'Фитнес и спорт',note:'Тренировки, игры и единоборства',icon:'fitness',departments:['fitness','team','combat']},
  tourism:{label:'Туризм и водный спорт',note:'SUP-борды, плавание и походы',icon:'tourism',departments:['tourism','water','walking']},
};
function catalogMatchesDepartment(product,key){
  return !key||(CATALOG_SECTIONS[key]?.departments||[key]).includes(product.department);
}
function catalogSectionFor(department){
  return Object.keys(CATALOG_SECTIONS).find(key=>CATALOG_SECTIONS[key].departments.includes(department))||'other';
}
function catalogCategoryLabel(key){return CATALOG_SECTIONS[key]?.label||CATALOG_DEPARTMENTS[key]?.label||key}
function isSupReference(name){return /(?:^|[^a-zа-я])(?:sup(?=$|[^a-z])|са[пб](?:[- ]?борд|(?=$|[^а-я])))/i.test(textNorm(name))}
function cleanPath(path){
  const list=Array.isArray(path)?path.map(x=>String(x||'').trim()).filter(Boolean):[];
  return list.filter(x=>!['главная','каталог товаров','каталог'].includes(textNorm(x)))
}
function startsAny(n,arr){return arr.some(x=>n===x||n.startsWith(x+' ')||n.startsWith(x+'-')||n.startsWith(x+','))}
function isCyclingPulleyName(n){
  return n.startsWith('ролики ')&&(
    n.includes('переключател')||n.includes('суппорт')||n.includes('подшипник')||n.includes('направляющ')||
    n.includes('shimano')||n.includes('sram')||/(?:^|\s)rd[- ]?[a-z0-9]/.test(n)
  )
}
function primaryProductType(name){
  const n=textNorm(name).replace(/^[\"' -]+/,'');
  if(isSupReference(n)&&(/^(?:sup|са[пб](?:[- ]?борд|[- ]))/.test(n)||/^(?:надувная )?доска/.test(n)))return'sup';
  if(startsAny(n,['беговел']))return'balance_bike';
  if(startsAny(n,['ролик для пресса','ролики для пресса']))return'ab_wheel';
  if(/^вело +[0-9]/.test(n)||startsAny(n,['детский велосипед','горный велосипед','электровелосипед','велосипед']))return'bicycle';
  if(startsAny(n,['детский самокат','городской самокат','трюковой самокат','электросамокат','самокат']))return'scooter';
  if(startsAny(n,['сноуборд']))return'snowboard';
  if(startsAny(n,['роликовые коньки','коньки роликовые','коньки для танцев','квады']))return'rollers';
  if(startsAny(n,['коньки']))return'skates';
  if(startsAny(n,['ролики'])){
    if(isCyclingPulleyName(n))return'';
    return'rollers';
  }
  if(startsAny(n,['лыжи','беговые лыжи','горные лыжи']))return'skis';
  if(startsAny(n,['скейтборд']))return'skateboard';
  if(startsAny(n,['лонгборд']))return'longboard';
  if(startsAny(n,['санки','сани','ледянка']))return'sled';
  if(startsAny(n,['снегокат']))return'snow_scooter';
  if(startsAny(n,['тюбинг']))return'tubing';
  if(startsAny(n,['беговая дорожка']))return'treadmill';
  if(startsAny(n,['велотренажер','велотренажёр']))return'exercise_bike';
  if(startsAny(n,['эллиптический тренажер','эллиптический тренажёр','эллипсоид']))return'elliptical';
  if(startsAny(n,['гантель','гантели']))return'dumbbell';
  if(startsAny(n,['штанга'])&&!/велокрес|hamax/.test(n))return'barbell';
  if(startsAny(n,['эспандер']))return'resistance_band';
  if(startsAny(n,['гиря','гири']))return'kettlebell';
  if(startsAny(n,['палатка']))return'tent';
  if(startsAny(n,['спальный мешок','спальник']))return'sleeping_bag';
  if(startsAny(n,['рюкзак']))return'backpack';
  if(startsAny(n,['батут']))return'trampoline';
  if(startsAny(n,['бассейн']))return'pool';
  if(startsAny(n,['каяк']))return'kayak';
  if(startsAny(n,['лодка']))return'boat';
  if(startsAny(n,['ракетка']))return'racket';
  if(startsAny(n,['мяч']))return'ball';
  if(startsAny(n,['клюшка']))return'hockey_stick';
  return''
}
function departmentFor(name,path){
  const type=primaryProductType(name),parts=cleanPath(path),p=textNorm(parts.join(' ')),n=textNorm(name);
  const match=(key,source='path',productType='')=>({key,type:productType,source});
  // Explicit object names win over polluted source paths. Accessories mentioning
  // a sport are routed before broad generic categories such as pumps or bags.
  if(['bicycle','balance_bike'].includes(type))return match('bicycle','name',type);
  if(type==='scooter')return match('scooter','name',type);
  if(type==='snowboard')return match('snowboard','name',type);
  if(type==='skates')return match('skates','name',type);
  if(type==='rollers')return match('rollers','name',type);
  if(type==='skis')return match('skiing','name',type);
  if(['skateboard','longboard'].includes(type))return match('boards','name',type);
  if(['treadmill','exercise_bike','elliptical','dumbbell','barbell','kettlebell','trampoline','ab_wheel','resistance_band'].includes(type))return match('fitness','name',type);
  if(['tent','sleeping_bag','backpack'].includes(type))return match('tourism','name',type);
  if(['pool','sup','kayak','boat'].includes(type))return match('water','name',type);
  if(type==='hockey_stick')return match('hockey','name',type);
  if(type==='racket')return match('team','name',type);
  if(type==='ball')return match(/хоккей/.test(p+' '+n)?'hockey':/фитнес|гимнаст|массаж|медбол|фитбол/.test(p+' '+n)?'fitness':'team','name',type);
  if(['sled','snow_scooter','tubing'].includes(type))return match('winter','name',type);
  if(isSupReference(n)||isSupReference(p))return match('water','name','sup_accessory');
  if(isCyclingPulleyName(n))return match('cycling','name');
  if(/для самокат|для сам\.|самокатн|электроскутер|электросамокат/.test(n))return match('scooter','name');
  if(/для скейт|для лонгборд/.test(n))return match('boards','name');
  if(/для ролик/.test(n))return match('rollers','name');
  if(/скандинав.*ходьб|скандин\.? ходьб/.test(p+' '+n))return match('walking');
  if(/плавани|для плав|д.плав|снорклинг|дайвинг/.test(p+' '+n))return match('water');
  if(/бокс|единобор|карат[еэ]|дзюдо|самбо|борцов/.test(p)||/боксерск|для бокса|кикбокс|единобор|карат[еэ]|дзюдо|самбо|борцов/.test(n))return match('combat');
  if(/сноуборд/.test(p))return match('snowboard');
  if(/лыж|лыжероллер/.test(p)||/(?:^| )лыжн|для (?:беговых |горных )?лыж/.test(n))return match('skiing');
  if(/ролик/.test(p))return match('rollers');
  if(/коньк/.test(p)&&!/клюшк|шайб/.test(p+' '+n))return match('skates');
  if(/хоккей/.test(p+' '+n)||/^клюшка(?: |$)/.test(n))return match('hockey');
  if(/скейт|лонгборд/.test(p))return match('boards');
  if(/самокат|электроскутер/.test(p))return match('scooter');
  if(/фитнес|тренаж|гантел|штанг|спортивные комплекс|пульсометр/.test(p))return match('fitness');
  if(/игровые виды спорта|футбол|баскет|волейбол|теннис|бадминтон|бейсбол/.test(p))return match('team');
  if(/туризм|палат|спальн|рюкзак/.test(p)&&!/велосум|велосип|велобагаж/.test(p+' '+n))return match('tourism');
  if(/водный спорт|водные виды|бассейн|(?:^| )лодк/.test(p))return match('water');
  if(/зимн|сани|санк|снегокат|тюбинг/.test(p))return match('winter');
  if(/одежд|термобель|носки|гетры|лосины|банданы/.test(p))return match('clothing');
  // Source categories often contain only component names, without "велосипед".
  const partCategories=new Set(['адаптеры','вилки и амортизация','втулки','выносы','грипсы','детали для вилки','запчасти для рам','звезды','калиперы','камеры','каретки','колеса','манетки','обода','педали','переключатели','подседельные штыри','подшипники','рамы','рога','рулевые колонки','рули','седла','спицы','тормоза гидравлические','тормоза v-brake','тормозные колодки','тормозные ротора диски','тормозные ручки','тросики рубашки','хомуты эксцентрики','цепи','шатуны','покрышки','кассеты','трещотки']);
  const pathKeys=parts.map(x=>textNorm(x).replace(/[^a-zа-я0-9 -]/g,'').trim());
  if(pathKeys.some(x=>partCategories.has(x)))return match('cycling');
  if(/(?:диск\\.? торм|дисков.*тормоз|адаптер калипера|тормоза postmount)/.test(n))return match('cycling','name');
  if(/^(?:покрышка|камера|подшипник|каретка|педали|шатуны|цепь|спицы)(?: |$)/.test(n))return match('cycling','name');
  if(/жилеты.*нарукавники|надувные лодоч|надувная мебель/.test(p))return match('water');
  if(/велосум|велобагаж|велозам|велокомпьют|фляг|насос|фонар|звонок|зеркал|крылья|багажник|корзин|велокрес|велочех|шлем|инструмент|стенд|смазк/.test(p+' '+n))return match('accessories');
  if(/велосип|bmx|велозапчаст|веломастер|велосум/.test(p))return match('cycling');
  if(/аксессуар|экипиров|защит/.test(p)||/чехол|сумка/.test(n))return match('accessories');
  return match('other','fallback');
}
function catalogSubcategory(name,path,tax){
  if(tax.type==='sup')return'SUP-борды';
  if(tax.type==='sup_accessory')return'SUP-аксессуары';
  if(tax.type==='balance_bike')return'Беговелы';
  path=cleanPath(path);
  const leaf=path[path.length-1]||CATALOG_DEPARTMENTS[tax.key]?.label||'Другие товары';
  // DIAFAN roots can include tax suffixes: "Велосипеды (НДС)".
  // Keep their actual leaf categories instead of collapsing every bike to the root.
  if(tax.key==='bicycle'&&(path.some(part=>/велосипед/.test(textNorm(part)))||/горн|детск|малыш|подрост|складн|фэтбайк|двухподвес|гибрид|шоссе|дорожн|городск|bmx/i.test(leaf))){
    return leaf.replace(/\s*\(?\s*(?:НДС|NDS|VAT)(?:\s*[-–:]?\s*\d+(?:[.,]\d+)?\s*%)?\s*\)?/gi,'').trim()||'Велосипеды';
  }
  // Do not expose an unrelated old category (e.g. skates on a bicycle).
  if(tax.source==='name'&&path.length&&departmentFor('',path).key!==tax.key)return CATALOG_DEPARTMENTS[tax.key]?.label||leaf;
  return leaf;
}
function catalogSpecEntries(specs){
  if(Array.isArray(specs))return specs.map(item=>{
    if(!item||typeof item!=='object')return['',''];
    return[String(item.name??item.key??item.title??''),item.value??''];
  }).filter(([k,v])=>k&&v!==null&&v!==undefined);
  return Object.entries(specs||{});
}
function findSpec(specs,needles){
  for(const [k,v] of catalogSpecEntries(specs)){
    const nk=textNorm(k);
    if(needles.some(x=>nk.includes(x))&&v!==null&&v!==undefined&&String(v).trim()!=='')return String(v).trim();
  }
  return''
}
function specSearchText(specs){return catalogSpecEntries(specs).flat().join(' ')}
function qualityNorm(s){return textNorm(s).replace(/[^a-zа-я0-9]+/gi,' ').replace(/\s+/g,' ').trim()}
function sanitizeCatalogSpecs(name,specs){
  const material=/^(?:пластик|сталь|алюминий|алюминий сплав|карбон|углепластик|композит)$/i;
  const bicycleLike=/^(?:электровелосипед|велосипед|bmx)(?:\s|$)/i.test(qualityNorm(name));
  if(Array.isArray(specs))return specs.filter(item=>{
    if(!item||typeof item!=='object')return true;
    const key=qualityNorm(item.name??item.key??item.title??''),value=qualityNorm(item.value??'');
    return !(key==='ростовка рамы'&&!bicycleLike&&material.test(value));
  });
  const out={};
  for(const [key,value] of Object.entries(specs||{})){
    const nk=qualityNorm(key),nv=qualityNorm(value);
    if(nk==='ростовка рамы'&&!bicycleLike&&material.test(nv))continue;
    out[key]=value;
  }
  return out
}
const CATALOG_BRAND_ALIASES=[
  ['RUSH HOUR','Rush Hour'],['VINCA SPORT','Vinca Sport'],['CN SPOKE','CN Spoke'],['X-TREME','X-Treme'],
  ['TECHTEAM','TechTeam'],['MAXISCOO','Maxiscoo'],['PROVOKATOR','Provokator'],['NORDSKI','Nordski'],
  ['SHIMANO','Shimano'],['STARFIT','Starfit'],['FISCHER','Fischer'],['ATOMIC','Atomic'],['BRADOS','Brados'],
  ['DEUTER','Deuter'],['SIMPLA','Simpla'],['ASPECT','Aspect'],['BOYBO','BoyBo'],['KENDA','Kenda'],['KENLI','Kenli'],
  ['MAXXIS','Maxxis'],['ROCKET','Rocket'],['SIGMA','Sigma'],['SPINE','SPINE'],['STELS','STELS'],['TREK','TREK'],
  ['VARMA','VARMA'],['WANDA','Wanda'],['WELT','Welt']
];
function resolveCatalogBrand(name,brand,specs){
  const existing=String(brand||'').trim();
  if(existing)return{brand:existing,inferred:false};
  const fromSpecs=findSpec(specs,['бренд','производитель']);
  if(fromSpecs)return{brand:fromSpecs,inferred:false};
  const hay=` ${qualityNorm(name)} `;
  for(const [alias,canonical] of CATALOG_BRAND_ALIASES){
    const needle=qualityNorm(alias);
    if(needle&&hay.includes(` ${needle} `))return{brand:canonical,inferred:true};
  }
  return{brand:'',inferred:false}
}
function catalogPriceValue(p){return Number(p?.price_rub??String(p?.price||0).replace(/[^0-9]/g,''))||0}
function isPurchasableCatalogRow(p){return catalogPriceValue(p)>0}
function numericFrom(s,min,max){
  const nums=String(s||'').replace(/,/g,'.').match(/\d+(?:\.\d+)?/g)||[];
  for(const raw of nums){const n=Number(raw);if(n>=min&&n<=max)return String(Number.isInteger(n)?n:n)}
  return''
}
function wheelFacet(name,specs){
  const v=findSpec(specs,['диаметр колес','диаметр колеса','размер колеса','колеса']);
  const n=numericFrom(v,10,32);
  if(n)return n+'″';
  const m=textNorm(name).match(/(?:велосипед|bmx)[^0-9]{0,18}(12|14|16|18|20|24|26|27[.,]5|28|29)(?=\s|"|'|$)/);
  return m?m[1].replace(',','.')+'″':''
}
function frameFacet(name,specs){
  const raw=findSpec(specs,['размер рамы','ростовка рамы','рама размер']);
  if(!raw)return'';
  const up=raw.toUpperCase().replace(/\s+/g,'');
  if(/^(?:XXS|XS|S|M|L|XL|XXL)$/.test(up))return up;
  const n=numericFrom(raw,8,24);return n?n+'″':''
}
function lengthFacet(specs,min,max){const raw=findSpec(specs,['ростовка','длина']);const n=numericFrom(raw,min,max);return n?n+' см':''}
function sizeFacet(specs){
  const raw=findSpec(specs,['размер обуви','размер ботинка','размер']);
  if(!raw)return'';
  const n=numericFrom(raw,20,50);return n||''
}
function deriveFacets(name,specs,department,type){
  const out={};
  if(department==='bicycle'||type==='bicycle'){const wheel=wheelFacet(name,specs),frame=frameFacet(name,specs);if(wheel)out.wheel=wheel;if(frame)out.frame=frame}
  if(department==='skiing'){const length=lengthFacet(specs,70,220);if(length)out.length=length}
  if(department==='snowboard'){const length=lengthFacet(specs,80,190);if(length)out.length=length}
  if(department==='skates'||department==='rollers'){const size=sizeFacet(specs);if(size)out.size=size}
  return out
}
function catalogStockLabel(code){
  return code==='in'?'В наличии':code==='out'?'Нет в наличии':'Уточняйте наличие';
}
function normalizeProduct(p,i){
  const rawPath=pathOf(p),path=cleanPath(rawPath);
  const price=catalogPriceValue(p);
  const rawOld=Number(p.old_price_rub??String(p.old_price||0).replace(/[^0-9]/g,''))||0;
  const oldPrice=rawOld>price?rawOld:0;
  const qty=p.stock_qty===null||p.stock_qty===undefined||p.stock_qty===''?null:Number(p.stock_qty);
  const stockCode=(p.availability==='in_stock'||p.stock_status==='in_stock')?'in':(p.availability==='out_of_stock'||p.stock_status==='out_of_stock')?'out':'unknown';
  const name=p.title||p.name||'Товар';
  const specs=sanitizeCatalogSpecs(name,p.specs||{});
  const brandInfo=resolveCatalogBrand(name,p.brand,specs);
  const brand=brandInfo.brand;
  const model=String(p.model||'').trim();
  const description=p.description||'';
  const pathText=path.join(' ');
  const tax=departmentFor(name,path);
  const rawCat=catalogSubcategory(name,path,tax);
  const dep=CATALOG_DEPARTMENTS[tax.key]||CATALOG_DEPARTMENTS.other;
  const displayCategory=tax.source==='name'?dep.label:rawCat;
  const images=(Array.isArray(p.images)?p.images:[]).map(imageUrl).filter(Boolean);
  const main=imageUrl(p.main_image||p.image||'');
  const baseImages=main?[main,...images]:images;
  // Parser pages include recommendations and banners among product images.
  // A missing database photo must stay missing until its source link is verified.
  const finalImages=[...new Set(baseImages)];
  const stockText=catalogStockLabel(stockCode);
  const facets=deriveFacets(name,specs,tax.key,tax.type);
  return{
    id:p.id??p.url??p.sku??`real-${i}`,
    sourceId:p.source_id||'',
    name,
    price,
    oldPrice,
    cat:displayCategory,
    rawCat,
    path,
    rawPath:Array.isArray(rawPath)?rawPath:[],
    pathText,
    department:tax.key,
    departmentLabel:dep.label,
    departmentOrder:dep.order,
    taxonomySource:tax.source,
    productType:tax.type,
    facets,
    searchText:[name,p.sku||'',brand,model,dep.label,rawCat,pathText,description,specSearchText(specs)].join(' ').toLowerCase(),
    icon:'🏷️',
    image:finalImages[0]||'',
    images:finalImages,
    url:p.url||`product.html?id=${encodeURIComponent(p.id??'')}`,
    stock:stockText,
    stockCode,
    stockQty:Number.isFinite(qty)?qty:null,
    specs,
    description,
    sku:p.sku||'',
    brand,
    brandInferred:Boolean(p.brand_inferred)||brandInfo.inferred,
    model
  }
}

// Bound both the request and response body so a stalled server cannot block startup.
async function catalogRequest(url,options={},parse=r=>r.json()){
  const controller=new AbortController();
  let timer;
  try{
    return await Promise.race([
      (async()=>{
        const response=await fetch(url,{...options,signal:controller.signal});
        if(!response.ok)throw Error(`catalog HTTP ${response.status}`);
        return parse(response);
      })(),
      new Promise((_,reject)=>{timer=setTimeout(()=>{
        reject(Error('catalog request timed out'));
        controller.abort();
      },15000)})
    ]);
  }finally{clearTimeout(timer)}
}

let catalogPromise,initialCatalogPromise;
async function parseCatalogResponse(r){
  if(!r.ok)throw Error(`live catalog HTTP ${r.status}`);
  const j=await r.json();
  if(!j.ok||!Array.isArray(j.items))throw Error('live catalog invalid');
  return j;
}
async function loadInitialCatalog(){
  if(initialCatalogPromise)return initialCatalogPromise;
  initialCatalogPromise=(async()=>{
    try{
      const j=await catalogRequest('api/catalog.php?limit=24&v=imgtruth5',{},parseCatalogResponse);
      return j.items.filter(isPurchasableCatalogRow).map(normalizeProduct);
    }catch(e){return[]}
  })();
  return initialCatalogPromise;
}
async function loadRealCatalog(){
  if(catalogPromise)return catalogPromise;
  catalogPromise=(async()=>{
    const j=await catalogRequest('api/catalog.php?v=1ctruth1',{},parseCatalogResponse);
    const liveItems=j.items.filter(isPurchasableCatalogRow);
    if(!liveItems.length)throw Error('live catalog empty');
    window.CATALOG_SOURCE='live';
    window.CATALOG_PHOTO_SOURCE='mysql';
    return liveItems.map(normalizeProduct);
  })();
  return catalogPromise;
}
// Product IDs must be resolved against the live store, never a static fallback.
async function loadProduct(id){
  const key=String(id);
  if(!/^[1-9][0-9]*$/.test(key))return null;
  const j=await catalogRequest('api/catalog.php?id='+encodeURIComponent(key),{cache:'no-store'},parseCatalogResponse);
  const row=j.items.find(p=>String(p.id)===key);
  return row?normalizeProduct(row):null;
}

