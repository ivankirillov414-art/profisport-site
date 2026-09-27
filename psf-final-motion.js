(function(){
  'use strict';
  const rideTile={key:'scooter',label:'Ролики и скейты',note:'Катание, трюки и комплектующие',img:'assets/categories/scooter-illustration-v2.png'};
  const categoryTiles=[
    {key:'bicycle',label:'Велосипеды',note:'Город, прогулки и бездорожье',img:'assets/categories/bicycle-illustration-v1.png'},
    rideTile,
    {key:'cycling',label:'Запчасти',note:'Точный подбор и совместимость',img:'assets/categories/parts-illustration-v1.png'},
    {key:'accessories',label:'Аксессуары',note:'Защита, свет и оснащение',img:'assets/categories/accessories-illustration-v1.png'},
    {key:'skiing',label:'Зимний спорт',note:'Лыжи, коньки и экипировка',img:'assets/categories/skiing-illustration-v2.png'},
    {key:'fitness',label:'Фитнес',note:'Тренировки и восстановление',img:'assets/categories/fitness-illustration-v1.png'},
    {key:'tourism',label:'Туризм и водный спорт',note:'Снаряжение для новых маршрутов',img:'assets/categories/tourism-illustration-v2.png'}
  ];
  const sceneSlides=[
    {cls:'bikeSlide',scene:'route',bg:'assets/hero/classic-mountains-v1.webp',bgW:2048,bgH:768,label:'ПРОФИСПОРТ · SPORT ENERGY',title:'Спорт. энергия.<br><em>Твой маршрут</em>',desc:'Велосипеды, экипировка и сервис для маршрутов, которые хочется продолжать.',href:'#catalogProducts',cta:'Перейти в каталог',priority:true},
    {cls:'routeSlide',scene:'mountains',bg:'assets/hero/classic-mountains-v1.webp',bgW:2048,bgH:768,label:'ВЕЛОСИПЕДЫ · МАРШРУТЫ',title:'Больше спорта<br><em>в твоей жизни</em>',desc:'Подберите велосипед для города, грунта и горных маршрутов без случайных компромиссов.',href:'#catalogProducts',cta:'Выбрать велосипед',department:'bicycle'},
    {cls:'serviceSlide',scene:'workshop',bg:'assets/hero/classic-workshop-wide-v5.webp',bgW:2172,bgH:724,label:'МАСТЕРСКАЯ ПРОФИСПОРТ',title:'Вернём байк<br><em>в движение</em>',desc:'Диагностика, точная настройка и обслуживание перед новым сезоном и дальними поездками.',href:'service.html',cta:'Записаться в сервис'}
  ];
  const bikeObject='assets/hero/classic-mountains-v1.webp';
  const esc=value=>String(value??'').replace(/[&<>"']/g,match=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[match]));
  const ready=fn=>document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn();

  function installSceneRuntimeStyle(){
    document.getElementById('psHeroReducedMotionStyle')?.remove();
    let style=document.getElementById('psfScrolltideHeroStyle');
    if(!style){style=document.createElement('style');style.id='psfScrolltideHeroStyle';}
    style.textContent=`
.motion-page #heroSlider .heroSlide::after,.motion-page #heroSlider .heroSlide::before,.motion-page #heroSlider .heroPhoto::after{content:none!important;display:none!important;background:none!important;opacity:0!important}
.motion-page #heroSlider .heroAdvance{cursor:pointer!important;background:transparent!important;color:transparent!important;border:0!important;box-shadow:none!important}
.motion-page #heroSlider .heroAdvance::before,.motion-page #heroSlider .heroAdvance::after,.motion-page #heroSlider .heroPrev,.motion-page #heroSlider .heroDots,.motion-page #heroSlider .heroPosition,.motion-page #heroSlider [class*="heroPosition"],.motion-page #heroSlider [class*="heroDot"],.motion-page #heroSlider .dots{display:none!important;content:none!important}
.motion-page .primary span[aria-hidden="true"],.motion-page .primary::after,.motion-page button::after,.motion-page #categoryTiles .tileArrow{display:none!important;content:none!important}
@keyframes psf-scene-bg-in{0%{opacity:0;transform:translate3d(32px,0,0) scale(1.12);filter:blur(2px) saturate(.92) brightness(.72)}100%{opacity:1;transform:translate3d(0,0,0) scale(1.075);filter:blur(0) saturate(1.04) brightness(.82)}}
@keyframes psf-scene-bike-in{0%{opacity:0;transform:translate3d(38vw,34px,0) scale(1.16) rotate(.9deg);filter:blur(3px) saturate(.9)}42%{opacity:1;filter:blur(0) saturate(1.12)}74%{transform:translate3d(-18px,-4px,0) scale(1.018) rotate(-.18deg)}100%{opacity:1;transform:translate3d(0,0,0) scale(1) rotate(0);filter:blur(0) saturate(1.06)}}
@keyframes psf-scene-bike-out{0%{opacity:1;transform:translate3d(0,0,0) scale(1);filter:blur(0)}100%{opacity:0;transform:translate3d(-22vw,-10px,0) scale(.96) rotate(-.6deg);filter:blur(2px)}}
@keyframes psf-scene-copy-in{0%{opacity:0;transform:translate3d(-16px,28px,0);filter:blur(1px)}100%{opacity:1;transform:translate3d(0,0,0);filter:blur(0)}}
.motion-page #heroSlider .heroSlide.psf-enter .heroBackdrop img,.motion-page #heroSlider .heroSlide.is-entering .heroBackdrop img{animation:psf-scene-bg-in 820ms cubic-bezier(.2,.7,.2,1) both!important}
.motion-page #heroSlider .heroSlide.psf-enter .heroPhoto,.motion-page #heroSlider .heroSlide.is-entering .heroPhoto{animation:psf-scene-bike-in 1180ms cubic-bezier(.16,.84,.18,1) 80ms both!important;will-change:transform,opacity,filter!important}
.motion-page #heroSlider .heroSlide.psf-leave .heroPhoto,.motion-page #heroSlider .heroSlide.is-leaving .heroPhoto{animation:psf-scene-bike-out 660ms cubic-bezier(.35,0,.2,1) both!important;will-change:transform,opacity,filter!important}
.motion-page #heroSlider .heroSlide.psf-enter .heroCopy>:is(small,h1,p,a),.motion-page #heroSlider .heroSlide.is-entering .heroCopy>:is(small,h1,p,a){animation:psf-scene-copy-in 620ms cubic-bezier(.2,.7,.2,1) both!important;opacity:1!important}
.motion-page #heroSlider .heroSlide.psf-enter .heroCopy small,.motion-page #heroSlider .heroSlide.is-entering .heroCopy small{animation-delay:300ms!important}.motion-page #heroSlider .heroSlide.psf-enter .heroCopy h1,.motion-page #heroSlider .heroSlide.is-entering .heroCopy h1{animation-delay:430ms!important}.motion-page #heroSlider .heroSlide.psf-enter .heroCopy p,.motion-page #heroSlider .heroSlide.is-entering .heroCopy p{animation-delay:560ms!important}.motion-page #heroSlider .heroSlide.psf-enter .heroCopy a,.motion-page #heroSlider .heroSlide.is-entering .heroCopy a{animation-delay:700ms!important}
@media(prefers-reduced-motion:reduce){.motion-page #heroSlider .heroSlide.psf-enter .heroPhoto,.motion-page #heroSlider .heroSlide.is-entering .heroPhoto{animation:psf-scene-bike-in 560ms ease-out both!important}.motion-page #heroSlider .heroSlide.psf-leave .heroPhoto,.motion-page #heroSlider .heroSlide.is-leaving .heroPhoto{animation:psf-scene-bike-out 260ms ease-out both!important}.motion-page #heroSlider .heroSlide.psf-enter .heroCopy>:is(small,h1,p,a),.motion-page #heroSlider .heroSlide.is-entering .heroCopy>:is(small,h1,p,a){animation:psf-scene-copy-in 240ms ease-out both!important}}
`;
    document.head.appendChild(style);
  }

  function slideMarkup(slide){
    const loading=slide.priority?'fetchpriority="high"':'loading="lazy"';
    const dep=slide.department?` data-department="${esc(slide.department)}"`:'';
    return `<article class="heroSlide ${esc(slide.cls)}" data-scene="${esc(slide.scene)}"><div class="heroBackdrop"><img src="${esc(slide.bg)}" width="${slide.bgW}" height="${slide.bgH}" alt="" ${loading} decoding="async"></div><div class="heroCopy"><small>${esc(slide.label)}</small><h1>${slide.title}</h1><p>${esc(slide.desc)}</p><a class="primary" href="${esc(slide.href)}"${dep}>${esc(slide.cta)}</a></div><div class="heroPhoto heroObject"><img class="heroVisual" src="${bikeObject}" width="2048" height="768" alt="" ${loading} decoding="async"></div></article>`;
  }

  function upgradeHeroScene(){
    const slider=document.getElementById('heroSlider');
    const track=slider?.querySelector('.heroTrack');
    if(!slider||!track)return;
    slider.classList.add('psfSceneHero');
    if(!track.querySelector('.heroBackdrop')||track.querySelector('.storageSlide')){
      track.innerHTML=sceneSlides.map(slideMarkup).join('');
    }
    const slides=[...track.querySelectorAll('.heroSlide')];
    if(!slides.some(slide=>slide.classList.contains('is-active')))slides[0]?.classList.add('is-active');
    slides.forEach((slide,index)=>{
      slide.inert=!slide.classList.contains('is-active');
      slide.setAttribute('aria-hidden',String(!slide.classList.contains('is-active')));
      slide.dataset.psfIndex=String(index);
    });
    slider.querySelectorAll('.heroPosition,.heroDots,.dots,.heroPrev').forEach(node=>node.remove());
  }

  function removeButtonArrows(root=document){
    root.querySelectorAll('.primary span[aria-hidden="true"],button span[aria-hidden="true"]').forEach(node=>node.remove());
    root.querySelectorAll('.primary,button').forEach(node=>{
      [...node.childNodes].forEach(child=>{
        if(child.nodeType===Node.TEXT_NODE)child.textContent=child.textContent.replace(/[→↗]/g,'').replace(/\s{2,}/g,' ');
      });
    });
  }

  function restoreRideCategory(){
    const wrap=document.getElementById('categoryTiles');
    if(!wrap)return;
    const current=[...wrap.querySelectorAll('h3')].map(node=>node.textContent.trim()).join('|');
    if(current.includes('Ролики и скейты')&&!current.includes('Сервис')&&!wrap.querySelector('.tileArrow'))return;
    wrap.dataset.psfLocked='1';
    wrap.innerHTML=categoryTiles.map(tile=>`<a href="?cat=${encodeURIComponent(tile.key)}#catalogProducts" class="categoryTile" data-department="${esc(tile.key)}"><div><h3>${esc(tile.label)}</h3><span>${esc(tile.note)}</span></div><img class="categoryArtwork" src="${esc(tile.img)}" width="480" height="320" alt="" loading="lazy" decoding="async"></a>`).join('');
    wrap.querySelectorAll('[data-department]').forEach(link=>{
      link.addEventListener('click',event=>{
        if(typeof window.selectDepartment!=='function')return;
        event.preventDefault();
        window.selectDepartment(link.dataset.department);
      });
    });
  }

  function patchMenuCopy(){
    document.querySelectorAll('a[data-category="самокат"],a[data-department="scooter"]').forEach(link=>{
      if(link.textContent.includes('Самокаты'))link.textContent='Ролики и скейты';
    });
  }

  function setupSceneMotion(){
    const slider=document.getElementById('heroSlider');
    if(!slider)return;
    let slides=[...slider.querySelectorAll('.heroSlide')];
    if(!slides.length)return;
    let active=slides.find(slide=>slide.classList.contains('is-active'))||slides[0];
    let lastChange=0;
    let exitTimer=0;
    const currentIndex=()=>Math.max(0,slides.findIndex(slide=>slide.classList.contains('is-active')));
    const clean=slide=>slide&&slide.classList.remove('psf-enter','psf-leave');
    const replay=(next,prev)=>{
      if(!next)return;
      lastChange=Date.now();
      slides.forEach(slide=>slide.classList.remove('psf-enter'));
      if(prev&&prev!==next){
        prev.classList.remove('psf-leave');
        void prev.offsetWidth;
        prev.classList.add('psf-leave');
        clearTimeout(exitTimer);
        exitTimer=window.setTimeout(()=>clean(prev),760);
      }
      next.classList.remove('psf-enter');
      void next.offsetWidth;
      next.classList.add('psf-enter');
      window.setTimeout(()=>clean(next),1420);
      active=next;
    };
    const setActive=index=>{
      slides=[...slider.querySelectorAll('.heroSlide')];
      const normalized=(index+slides.length)%slides.length;
      const prev=slides.find(slide=>slide.classList.contains('is-active'))||active;
      slides.forEach((slide,i)=>{
        const on=i===normalized;
        slide.classList.toggle('is-active',on);
        slide.inert=!on;
        slide.setAttribute('aria-hidden',String(!on));
      });
      replay(slides[normalized],prev);
    };
    new MutationObserver(()=>{
      const next=slides.find(slide=>slide.classList.contains('is-active'))||slides[0];
      if(next!==active)replay(next,active);
    }).observe(slider,{subtree:true,attributes:true,attributeFilter:['class']});
    requestAnimationFrame(()=>replay(active,null));
    const advance=()=>setActive(currentIndex()+1);
    const button=slider.querySelector('.heroAdvance,.heroNext');
    if(button){
      button.addEventListener('click',()=>{
        const before=currentIndex();
        window.setTimeout(()=>{ if(currentIndex()===before)advance(); },70);
      });
    }
    window.setInterval(()=>{
      if(document.hidden)return;
      if(Date.now()-lastChange>6200)advance();
    },1300);
  }

  function setupSceneParallax(){
    const slider=document.getElementById('heroSlider');
    if(!slider)return;
    let pointerFrame=0;
    if(matchMedia('(hover:hover) and (pointer:fine)').matches){
      slider.addEventListener('pointermove',event=>{
        if(pointerFrame)return;
        pointerFrame=requestAnimationFrame(()=>{
          pointerFrame=0;
          const rect=slider.getBoundingClientRect();
          slider.style.setProperty('--hero-x',(((event.clientX-rect.left)/rect.width)-.5)*18+'px');
          slider.style.setProperty('--hero-y',(((event.clientY-rect.top)/rect.height)-.5)*12+'px');
        });
      });
      slider.addEventListener('pointerleave',()=>{slider.style.removeProperty('--hero-x');slider.style.removeProperty('--hero-y');});
    }
    let scrollFrame=0;
    const onScroll=()=>{
      if(scrollFrame)return;
      scrollFrame=requestAnimationFrame(()=>{
        scrollFrame=0;
        const rect=slider.getBoundingClientRect();
        const progress=Math.max(-1,Math.min(1,(window.innerHeight*.5-rect.top)/Math.max(1,window.innerHeight)));
        slider.style.setProperty('--psf-scroll-y',(progress*-16).toFixed(2)+'px');
      });
    };
    onScroll();
    window.addEventListener('scroll',onScroll,{passive:true});
    window.addEventListener('resize',onScroll,{passive:true});
  }

  ready(()=>{
    installSceneRuntimeStyle();
    upgradeHeroScene();
    setupSceneMotion();
    setupSceneParallax();
    restoreRideCategory();
    patchMenuCopy();
    removeButtonArrows();
    const categoryWrap=document.getElementById('categoryTiles');
    if(categoryWrap){
      let scheduled=false;
      new MutationObserver(()=>{
        if(scheduled)return;
        scheduled=true;
        setTimeout(()=>{scheduled=false;restoreRideCategory();removeButtonArrows(categoryWrap);},0);
      }).observe(categoryWrap,{childList:true,subtree:false});
    }
    new MutationObserver(()=>removeButtonArrows()).observe(document.body,{childList:true,subtree:true});
    [250,900,1800,3200].forEach(delay=>setTimeout(()=>{installSceneRuntimeStyle();restoreRideCategory();patchMenuCopy();removeButtonArrows();},delay));
  });
})();




