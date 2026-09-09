(()=>{
  /* Mobile-safe storefront logic.
     Important: this file deliberately does not wrap/reparent forms, append filter rows,
     build mega menus, or force an extra initial catalog render. Those delayed DOM
     mutations were the last major difference between the first stable frame and the
     frame shown a fraction of a second later on iOS. */
  const mobile=window.matchMedia('(max-width:850px)').matches;
  if(!mobile)return;

  const esc=s=>String(s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const readArray=k=>{try{const v=JSON.parse(localStorage.getItem(k)||'[]');return Array.isArray(v)?v:[]}catch(e){return[]}};
  const ruMap={'q':'й','w':'ц','e':'у','r':'к','t':'е','y':'н','u':'г','i':'ш','o':'щ','p':'з','[':'х',']':'ъ','a':'ф','s':'ы','d':'в','f':'а','g':'п','h':'р','j':'о','k':'л','l':'д',';':'ж',"'":'э','z':'я','x':'ч','c':'с','v':'м','b':'и','n':'т','m':'ь',',':'б','.':'ю'};
  const enMap=Object.fromEntries(Object.entries(ruMap).map(([a,b])=>[b,a]));
  const swap=(s,map)=>[...String(s||'').toLowerCase()].map(ch=>map[ch]||ch).join('');
  const synonyms=[[/велик[а-я]*/g,'велосип'],[/^вел$/g,'велосип'],[/\bmtb\b/g,'велосип'],[/горник[а-я]*/g,'горн'],[/запчасти?/g,'запчаст'],[/аксессуары?/g,'аксессуар'],[/лыжи?/g,'лыж'],[/сноуборды?/g,'сноуборд']];
  const plain=s=>String(s||'').toLowerCase().trim().replace(/ё/g,'е').replace(/[.,;:()[\]{}"']/g,' ').replace(/\s+/g,' ');
  const repairLegacyQuery=s=>plain(s).replace(/^велосипедосипед(?=\s|$)/,'велосипед').replace(/^велосипосипед(?=\s|$)/,'велосипед');
  const canon=s=>{let x=repairLegacyQuery(s);for(const [r,v] of synonyms)x=x.replace(r,v);return x.replace(/\s+/g,' ')};
  const variants=q=>[canon(q),canon(swap(q,ruMap)),canon(swap(q,enMap))].filter((v,i,a)=>v&&a.indexOf(v)===i);
  const ptext=p=>canon([p.name,p.brand,p.model,p.cat,p.pathText,p.description,Object.entries(p.specs||{}).flat().join(' ')].join(' '));
  const accessoryWords=/\b(чехол|сумк|багажник|крыл|фонар|звонок|замок|покрыш|камер|насос|держател|креплен|корзин|зеркал|седл|сиден|педал|грипс|трос|цеп|кассет|звезд|переключ|тормоз|обод|вилк|рам|втулк|спиц|подножк|подстав|крепеж|адаптер|палк|ботинк|маск|очк|перчат|защит|колес|подшип|ось|амортиз|ремкомплект|запчаст)\w*/;
  const intentRules=[
    {id:'bicycle',q:/^(?:электро)?велосип/,direct:[/^(?:(?:детск\w*|подростков\w*|горн\w*|складн\w*|городск\w*|дорожн\w*|женск\w*|мужск\w*|трехколесн\w*|3[- ]?х\s*колесн\w*)\s+)*(?:электро\s*)?велосипед\w*(?:\s|$)/,/^электровелосипед\w*(?:\s|$)/]},
    {id:'scooter',q:/^(?:электро)?самокат/,direct:[/^(?:(?:детск\w*|трюков\w*|городск\w*|складн\w*|взросл\w*|двухколесн\w*|трехколесн\w*)\s+)*(?:электро\s*)?самокат\w*(?:\s|$)/,/^электросамокат\w*(?:\s|$)/]},
    {id:'skis',q:/^лыж/,direct:[/^лыж(?:и|а)?\b/,/^(?:(?:горн\w*|бегов\w*|детск\w*|охотнич\w*|туристич\w*|прогулочн\w*)\s+){1,2}лыж(?:и|а)?\b/]},
    {id:'snowboard',q:/^сноуборд/,direct:[/^(?:(?:детск\w*|женск\w*|мужск\w*|унисекс\w*)\s+)*сноуборд\w*(?:\s|$)/]},
    {id:'skates',q:/^коньк/,direct:[/^(?:(?:фигурн\w*|хоккейн\w*|детск\w*|женск\w*|мужск\w*|раздвижн\w*)\s+)*коньк\w*(?:\s|$)/]},
    {id:'rollers',q:/^(?:ролик|роликов)/,direct:[/^(?:(?:детск\w*|женск\w*|мужск\w*|раздвижн\w*)\s+)*(?:роликов\w*\s+коньк\w*|ролик\w*)(?:\s|$)/]},
    {id:'skateboard',q:/^скейтборд/,direct:[/^(?:(?:детск\w*|трюков\w*)\s+)*скейтборд\w*(?:\s|$)/]},
    {id:'longboard',q:/^лонгборд/,direct:[/^лонгборд\w*(?:\s|$)/]},
    {id:'sled',q:/^санк/,direct:[/^санк\w*(?:\s|$)/]},
    {id:'snow_scooter',q:/^снегокат/,direct:[/^снегокат\w*(?:\s|$)/]},
    {id:'tubing',q:/^тюбинг/,direct:[/^тюбинг\w*(?:\s|$)/]},
    {id:'helmet',q:/^шлем/,direct:[/^(?:(?:велосипедн\w*|горнолыжн\w*|хоккейн\w*|детск\w*)\s+)*шлем\w*(?:\s|$)/]},
    {id:'backpack',q:/^рюкзак/,direct:[/^рюкзак\w*(?:\s|$)/]},
    {id:'tent',q:/^палатк/,direct:[/^палатк\w*(?:\s|$)/]},
    {id:'sleeping_bag',q:/^(?:спальник|спальн\w*\s+мешок)/,direct:[/^(?:спальник\w*|спальн\w*\s+мешок\w*)(?:\s|$)/]},
    {id:'trampoline',q:/^батут/,direct:[/^батут\w*(?:\s|$)/]},
    {id:'treadmill',q:/^бегов\w*\s+дорожк/,direct:[/^бегов\w*\s+дорожк\w*(?:\s|$)/]},
    {id:'exercise_bike',q:/^велотренажер/,direct:[/^велотренажер\w*(?:\s|$)/]},
    {id:'elliptical',q:/^(?:эллипс|эллиптическ)/,direct:[/^(?:эллипс\w*|эллиптическ\w*\s+тренажер\w*)(?:\s|$)/]},
    {id:'dumbbell',q:/^гантел/,direct:[/^гантел\w*(?:\s|$)/]},
    {id:'barbell',q:/^штанг/,direct:[/^штанг\w*(?:\s|$)/]},
    {id:'kettlebell',q:/^гир(?:я|и|ь)/,direct:[/^гир(?:я|и|ь)\w*(?:\s|$)/]},
    {id:'racket',q:/^ракетк/,direct:[/^ракетк\w*(?:\s|$)/]},
    {id:'ball',q:/^мяч/,direct:[/^мяч\w*(?:\s|$)/]},
    {id:'hockey_stick',q:/^клюшк/,direct:[/^клюшк\w*(?:\s|$)/]},
    {id:'pool',q:/^бассейн/,direct:[/^бассейн\w*(?:\s|$)/]},
    {id:'sup',q:/^(?:сап|sup)(?:\s|$)/,direct:[/^(?:сап|sup)(?:[- ]?борд\w*)?(?:\s|$)/]},
    {id:'kayak',q:/^каяк/,direct:[/^каяк\w*(?:\s|$)/]},
    {id:'boat',q:/^лодк/,direct:[/^лодк\w*(?:\s|$)/]}
  ];
  function searchIntent(q){const c=canon(q);if(!c)return'';for(const r of intentRules){if(!r.q.test(c))continue;if(accessoryWords.test(c)&&!['helmet','backpack'].includes(r.id))return'';return r.id}return''}
  function intentRule(id){return intentRules.find(r=>r.id===id)}
  function isPrimaryProduct(p,intent){if(!intent)return true;const rule=intentRule(intent);if(!rule)return true;const n=plain(p.name||'');return rule.direct.some(r=>r.test(n))}
  function relevanceScore(p,q,intent){const c=canon(q),n=canon(p.name||''),brand=canon(p.brand||''),model=canon(p.model||''),cat=canon(p.cat||''),path=canon(p.pathText||'');let s=0;if(intent&&isPrimaryProduct(p,intent))s+=1200;if(n===c)s+=1000;if(n.startsWith(c))s+=650;else if(n.includes(c))s+=420;if(brand===c||model===c)s+=500;else if(brand.startsWith(c)||model.startsWith(c))s+=280;if(cat.includes(c))s+=120;if(path.includes(c))s+=80;return s}
  function bestQuery(q){const vs=variants(q);if(!vs.length)return'';for(const v of vs){const intent=searchIntent(v);if(products.some(p=>(!intent||isPrimaryProduct(p,intent))&&ptext(p).includes(v)))return v}return vs[0]}

  function installSearch(){
    const previousApply=apply;
    apply=function(resetPage=true){
      let list=[...products],c=category.value,min=+minPrice.value||0,max=+maxPrice.value||Infinity;
      const raw=(document.querySelector('#q')?.value||document.querySelector('#mobileQ')?.value||'').trim();
      const qv=bestQuery(raw),intent=searchIntent(qv||raw);
      if(c)list=list.filter(p=>p.cat===c);
      if(qv){
        const terms=qv.split(' ').filter(Boolean);
        list=list.filter(p=>(!intent||isPrimaryProduct(p,intent))&&terms.every(t=>ptext(p).includes(t)));
        if(sort.value==='popular')list.sort((a,b)=>relevanceScore(b,qv,intent)-relevanceScore(a,qv,intent));
      }
      list=list.filter(p=>p.price>=min&&p.price<=max);
      if(sort.value==='priceAsc')list.sort((a,b)=>a.price-b.price);
      if(sort.value==='priceDesc')list.sort((a,b)=>b.price-a.price);
      if(sort.value==='name')list.sort((a,b)=>a.name.localeCompare(b.name,'ru'));
      if(resetPage)page=1;
      render(list);
    };
    // Do not render again on startup. Only repair a legacy corrupted query value.
    const desk=document.querySelector('#q'),mob=document.querySelector('#mobileQ');
    const current=(desk?.value||mob?.value||'').trim(),repaired=repairLegacyQuery(current);
    if(current&&repaired!==plain(current)){if(desk)desk.value=repaired;if(mob)mob.value=repaired}
  }

  async function installFavoritesWithoutRerender(){
    let fav=readArray('ps-favorites').map(String),csrf='',logged=false;
    try{
      const r=await fetch('api/customer.php?action=me',{cache:'no-store'}),j=await r.json();
      if(r.ok&&j.ok&&j.customer){csrf=j.csrf||'';logged=true;fav=(j.favorites||[]).map(String);localStorage.setItem('ps-favorites',JSON.stringify(fav))}
    }catch(e){}

    const decorate=()=>{
      document.querySelectorAll('.product').forEach(card=>{
        if(card.querySelector('.productFavorite'))return;
        const link=card.querySelector('a[href*="product.html?id="]');
        if(!link)return;
        const id=new URL(link.href).searchParams.get('id');
        if(!id)return;
        const p=products.find(x=>String(x.id)===String(id));
        if(p?.oldPrice&&p.oldPrice>p.price){
          const price=card.querySelector('.price');
          if(price&&!card.querySelector('.productDiscount')){const pct=Math.round((1-p.price/p.oldPrice)*100);price.insertAdjacentHTML('afterend',`<span class="productDiscount">−${pct}%</span>`)}
        }
        const b=document.createElement('button');
        b.type='button';b.className='productFavorite'+(fav.includes(String(id))?' active':'');b.setAttribute('aria-label','В избранное');b.textContent=fav.includes(String(id))?'♥':'♡';
        b.onclick=async e=>{
          e.preventDefault();e.stopPropagation();const s=String(id);
          if(logged){
            try{const r=await fetch('api/customer.php?action=favorite',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({product_id:Number(id)})}),j=await r.json();if(!r.ok||!j.ok)throw 0;if(j.active&&!fav.includes(s))fav.push(s);if(!j.active)fav=fav.filter(x=>x!==s)}catch(err){location.href='profile.html';return}
          }else{const i=fav.indexOf(s);if(i>=0)fav.splice(i,1);else fav.push(s)}
          localStorage.setItem('ps-favorites',JSON.stringify(fav));b.classList.toggle('active',fav.includes(s));b.textContent=fav.includes(s)?'♥':'♡';
        };
        card.appendChild(b);
      });
    };
    const oldRender=render;
    render=function(list=view){oldRender(list);decorate()};
    decorate();
  }

  let tries=0;
  const timer=setInterval(()=>{
    tries++;
    if(typeof products!=='undefined'&&products.length){
      clearInterval(timer);
      installSearch();
      installFavoritesWithoutRerender();
    }else if(tries>120)clearInterval(timer);
  },100);
})();