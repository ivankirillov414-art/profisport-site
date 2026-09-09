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
function fallbackImageUrl(name,cat){
  return`api/product-fallback-image.php?name=${encodeURIComponent(name||'')}&cat=${encodeURIComponent(cat||'')}`;
}

const CATALOG_DEPARTMENTS={
  bicycle:{label:'Велосипеды',order:10},
  scooter:{label:'Самокаты',order:20},
  cycling:{label:'Велозапчасти и аксессуары',order:30},
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
  other:{label:'Другие товары',order:999}
};
function cleanPath(path){
  const list=Array.isArray(path)?path.map(x=>String(x||'').trim()).filter(Boolean):[];
  return list.filter(x=>!['главная','каталог товаров','каталог'].includes(textNorm(x)))
}
function startsAny(n,arr){return arr.some(x=>n===x||n.startsWith(x+' ')||n.startsWith(x+'-'))}
function isCyclingPulleyName(n){
  return n.startsWith('ролики ')&&(
    n.includes('переключател')||n.includes('суппорт')||n.includes('подшипник')||n.includes('направляющ')||
    n.includes('shimano')||n.includes('sram')||/(?:^|\s)rd[- ]?[a-z0-9]/.test(n)
  )
}
function primaryProductType(name){
  const n=textNorm(name);
  if(startsAny(n,['электровелосипед','велосипед']))return'bicycle';
  if(startsAny(n,['электросамокат','самокат']))return'scooter';
  if(startsAny(n,['сноуборд']))return'snowboard';
  if(startsAny(n,['роликовые коньки','коньки роликовые','коньки для танцев','квады']))return'rollers';
  if(startsAny(n,['коньки']))return'skates';
  if(startsAny(n,['ролики'])){
    if(isCyclingPulleyName(n))return'';
    return'rollers';
  }
  if(startsAny(n,['лыжи','лыжи беговые','лыжи горные']))return'skis';
  if(startsAny(n,['скейтборд']))return'skateboard';
  if(startsAny(n,['лонгборд']))return'longboard';
  if(startsAny(n,['санки']))return'sled';
  if(startsAny(n,['снегокат']))return'snow_scooter';
  if(startsAny(n,['тюбинг']))return'tubing';
  if(startsAny(n,['беговая дорожка']))return'treadmill';
  if(startsAny(n,['велотренажер','велотренажёр']))return'exercise_bike';
  if(startsAny(n,['эллиптический тренажер','эллиптический тренажёр','эллипсоид']))return'elliptical';
  if(startsAny(n,['гантель','гантели']))return'dumbbell';
  if(startsAny(n,['штанга']))return'barbell';
  if(startsAny(n,['гиря','гири']))return'kettlebell';
  if(startsAny(n,['палатка']))return'tent';
  if(startsAny(n,['спальный мешок','спальник']))return'sleeping_bag';
  if(startsAny(n,['рюкзак']))return'backpack';
  if(startsAny(n,['батут']))return'trampoline';
  if(startsAny(n,['бассейн']))return'pool';
  if(startsAny(n,['сап','sup']))return'sup';
  if(startsAny(n,['каяк']))return'kayak';
  if(startsAny(n,['лодка']))return'boat';
  if(startsAny(n,['ракетка']))return'racket';
  if(startsAny(n,['мяч']))return'ball';
  if(startsAny(n,['клюшка']))return'hockey_stick';
  return''
}
function departmentFor(name,path){
  const type=primaryProductType(name),p=textNorm(cleanPath(path).join(' ')),n=textNorm(name);
  if(type==='bicycle')return{key:'bicycle',type,source:'name'};
  if(type==='scooter')return{key:'scooter',type,source:'name'};
  if(type==='snowboard')return{key:'snowboard',type,source:'name'};
  if(type==='skates')return{key:'skates',type,source:'name'};
  if(type==='rollers')return{key:'rollers',type,source:'name'};
  if(type==='skis')return{key:'skiing',type,source:'name'};
  if(['skateboard','longboard'].includes(type))return{key:'boards',type,source:'name'};
  if(['treadmill','exercise_bike','elliptical','dumbbell','barbell','kettlebell','trampoline'].includes(type))return{key:'fitness',type,source:'name'};
  if(['tent','sleeping_bag','backpack'].includes(type))return{key:'tourism',type,source:'name'};
  if(['pool','sup','kayak','boat'].includes(type))return{key:'water',type,source:'name'};
  if(['racket','ball','hockey_stick'].includes(type))return{key:'team',type,source:'name'};
  if(['sled','snow_scooter','tubing'].includes(type))return{key:'winter',type,source:'name'};
  if(p.includes('велосип')||p.includes('bmx')||p.includes('велозапчаст'))return{key:'cycling',type:'',source:'path'};
  if(p.includes('беговые лыжи')||p.includes('горные лыжи')||p.includes('лыж'))return{key:'skiing',type:'',source:'path'};
  if(p.includes('сноуборд'))return{key:'snowboard',type:'',source:'path'};
  if(p.includes('ролик'))return{key:'rollers',type:'',source:'path'};
  if(p.includes('коньк'))return{key:'skates',type:'',source:'path'};
  if(p.includes('скейт')||p.includes('лонгборд'))return{key:'boards',type:'',source:'path'};
  if(p.includes('фитнес')||p.includes('тренаж')||p.includes('гантел')||p.includes('штанг'))return{key:'fitness',type:'',source:'path'};
  if(p.includes('туризм')||p.includes('палат')||p.includes('спальн')||p.includes('рюкзак'))return{key:'tourism',type:'',source:'path'};
  if(p.includes('водн')||p.includes('бассейн')||p.includes('сап')||p.includes('лодк'))return{key:'water',type:'',source:'path'};
  if(p.includes('хоккей')||p.includes('футбол')||p.includes('баскет')||p.includes('волейбол')||p.includes('теннис'))return{key:'team',type:'',source:'path'};
  if(p.includes('зимн')||p.includes('санк')||p.includes('снегокат')||p.includes('тюбинг'))return{key:'winter',type:'',source:'path'};
  if(p.includes('аксессуар')||p.includes('экипиров')||p.includes('защит')||n.includes('чехол')||n.includes('сумка'))return{key:'accessories',type:'',source:'path'};
  return{key:'other',type:'',source:'fallback'}
}
function findSpec(specs,needles){
  const entries=Object.entries(specs||{});
  for(const [k,v] of entries){const nk=textNorm(k);if(needles.some(x=>nk.includes(x))&&v!==null&&v!==undefined&&String(v).trim()!=='')return String(v).trim()}
  return''
}
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
function normalizeProduct(p,i){
  const rawPath=pathOf(p),path=cleanPath(rawPath),
    price=Number(p.price_rub??String(p.price||0).replace(/[^0-9]/g,''))||0,
    rawOld=Number(p.old_price_rub??String(p.old_price||0).replace(/[^0-9]/g,''))||0,
    oldPrice=rawOld>price?rawOld:0,
    qty=p.stock_qty===null||p.stock_qty===undefined||p.stock_qty===''?null:Number(p.stock_qty),
    stockCode=(p.availability==='in_stock'||p.stock_status==='in_stock')?'in':(p.availability==='out_of_stock'||p.stock_status==='out_of_stock')?'out':'unknown',
    name=p.title||p.name||'Товар',
    specs=p.specs||{},
    brand=String(p.brand||findSpec(specs,['бренд','производитель'])||'').trim(),
    model=String(p.model||'').trim(),
    description=p.description||'',
    pathText=path.join(' '),
    rawCat=path[path.length-1]||path[0]||'Каталог',
    tax=departmentFor(name,path),
    dep=CATALOG_DEPARTMENTS[tax.key]||CATALOG_DEPARTMENTS.other,
    displayCategory=tax.source==='name'?dep.label:rawCat,
    images=(Array.isArray(p.images)?p.images:[]).map(imageUrl).filter(Boolean),
    main=imageUrl(p.image||p.main_image||''),
    fallback=fallbackImageUrl(name,rawCat),
    baseImages=images.length?images:(main?[main]:[]),
    finalImages=[...new Set([...baseImages,fallback])],
    stockText=stockCode==='in'?(Number.isFinite(qty)&&qty>0?`В наличии: ${qty} шт.`:'В наличии'):stockCode==='out'?'Нет в наличии':'Уточняйте наличие',
    facets=deriveFacets(name,specs,tax.key,tax.type);
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
    searchText:[name,p.sku||'',brand,model,dep.label,rawCat,pathText,description,Object.entries(specs).flat().join(' ')].join(' ').toLowerCase(),
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
      const j=await catalogRequest('api/catalog.php?limit=24&v=imgfix1',{},parseCatalogResponse);
      return j.items.map(normalizeProduct);
    }catch(e){return[]}
  })();
  return initialCatalogPromise;
}
async function loadRealCatalog(){
  if(catalogPromise)return catalogPromise;
  catalogPromise=(async()=>{
    try{
      const j=await catalogRequest('api/catalog.php?v=imgfix1',{},parseCatalogResponse);
      if(j.items.length){const items=j.items.map(normalizeProduct);window.CATALOG_SOURCE='live';return items}
    }catch(e){}
    const manifest=await catalogRequest('data/manifest.json',{},r=>r.json());
    const parts=Array.isArray(manifest.parts)?manifest.parts:[];
    const arrays=await Promise.all(parts.map(file=>catalogRequest(`data/${file}`,{},r=>r.json())));
    window.CATALOG_SOURCE='static';
    return arrays.flat().map(normalizeProduct)
  })();
  return catalogPromise;
}
