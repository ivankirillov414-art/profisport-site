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
  const esc=value=>String(value??'').replace(/[&<>"']/g,match=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[match]));
  const ready=fn=>document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn();

  function restoreRideCategory(){
    const wrap=document.getElementById('categoryTiles');
    if(!wrap)return;
    const current=[...wrap.querySelectorAll('h3')].map(node=>node.textContent.trim()).join('|');
    if(current.includes('Ролики и скейты')&&!current.includes('Сервис'))return;
    wrap.dataset.psfLocked='1';
    wrap.innerHTML=categoryTiles.map(tile=>`<a href="?cat=${encodeURIComponent(tile.key)}#catalogProducts" class="categoryTile" data-department="${esc(tile.key)}"><div><h3>${esc(tile.label)}</h3><span>${esc(tile.note)}</span></div><img class="categoryArtwork" src="${esc(tile.img)}" width="480" height="320" alt="" loading="lazy" decoding="async"><b class="tileArrow" aria-hidden="true">↗</b></a>`).join('');
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

  function installFinalHeroMotionStyle(){
    const legacy=document.getElementById('psHeroReducedMotionStyle');
    if(legacy)legacy.remove();
    let style=document.getElementById('psfFinalHeroMotionRuntimeStyle');
    if(!style){
      style=document.createElement('style');
      style.id='psfFinalHeroMotionRuntimeStyle';
    }
    style.textContent=`
@keyframes psf-force-bike-in{0%{opacity:0;transform:translate3d(340px,34px,0) scale(1.22) rotate(.8deg);filter:blur(3px) saturate(.92)}38%{opacity:1;filter:blur(0) saturate(1.08)}72%{transform:translate3d(-14px,-3px,0) scale(1.018) rotate(-.18deg)}100%{opacity:1;transform:translate3d(0,0,0) scale(1) rotate(0);filter:blur(0) saturate(1.04)}}
@keyframes psf-force-bike-out{0%{opacity:1;transform:translate3d(0,0,0) scale(1);filter:blur(0)}100%{opacity:0;transform:translate3d(-190px,-10px,0) scale(.955) rotate(-.5deg);filter:blur(2px)}}
@keyframes psf-force-copy-in{0%{opacity:0;transform:translate3d(-10px,26px,0)}100%{opacity:1;transform:translate3d(0,0,0)}}
@keyframes psf-force-bg-in{0%{opacity:.3;transform:scale(1.07) translate3d(22px,0,0)}100%{opacity:.64;transform:scale(1.015) translate3d(0,0,0)}}
.motion-page #heroSlider .heroSlide::after,.motion-page #heroSlider .heroSlide.is-active::after{content:none!important;display:none!important;opacity:0!important;background:none!important;animation:none!important}
.motion-page #heroSlider .heroSlide.psf-enter::before,.motion-page #heroSlider .heroSlide.is-entering::before{animation:psf-force-bg-in 760ms cubic-bezier(.2,.7,.2,1) both!important}
.motion-page #heroSlider .heroSlide.psf-enter.is-active .heroPhoto,.motion-page #heroSlider .heroSlide.is-entering.is-active .heroPhoto{animation:psf-force-bike-in 1080ms cubic-bezier(.14,.86,.16,1) 60ms both!important;will-change:transform,opacity,filter!important}
.motion-page #heroSlider .heroSlide.psf-leave .heroPhoto,.motion-page #heroSlider .heroSlide.is-leaving .heroPhoto{animation:psf-force-bike-out 620ms cubic-bezier(.35,0,.2,1) both!important;will-change:transform,opacity,filter!important}
.motion-page #heroSlider .heroSlide.psf-enter.is-active .heroCopy>:is(small,h1,p,a),.motion-page #heroSlider .heroSlide.is-entering.is-active .heroCopy>:is(small,h1,p,a){animation:psf-force-copy-in 560ms cubic-bezier(.2,.7,.2,1) both!important;opacity:1!important}
.motion-page #heroSlider .heroSlide.psf-enter.is-active .heroCopy small,.motion-page #heroSlider .heroSlide.is-entering.is-active .heroCopy small{animation-delay:260ms!important}
.motion-page #heroSlider .heroSlide.psf-enter.is-active .heroCopy h1,.motion-page #heroSlider .heroSlide.is-entering.is-active .heroCopy h1{animation-delay:360ms!important}
.motion-page #heroSlider .heroSlide.psf-enter.is-active .heroCopy p,.motion-page #heroSlider .heroSlide.is-entering.is-active .heroCopy p{animation-delay:470ms!important}
.motion-page #heroSlider .heroSlide.psf-enter.is-active .heroCopy a,.motion-page #heroSlider .heroSlide.is-entering.is-active .heroCopy a{animation-delay:580ms!important}
@media(prefers-reduced-motion:reduce){
 .motion-page #heroSlider .heroSlide.psf-enter.is-active .heroPhoto,.motion-page #heroSlider .heroSlide.is-entering.is-active .heroPhoto{animation:psf-force-bike-in 980ms cubic-bezier(.2,.7,.2,1) 40ms both!important}
 .motion-page #heroSlider .heroSlide.psf-leave .heroPhoto,.motion-page #heroSlider .heroSlide.is-leaving .heroPhoto{animation:psf-force-bike-out 520ms ease-out both!important}
 .motion-page #heroSlider .heroSlide.psf-enter.is-active .heroCopy>:is(small,h1,p,a),.motion-page #heroSlider .heroSlide.is-entering.is-active .heroCopy>:is(small,h1,p,a){animation:psf-force-copy-in 460ms ease-out both!important}
}
`;
    document.head.appendChild(style);
  }

  function protectFinalHeroMotionStyle(){
    let scheduled=false;
    const ensure=()=>{
      if(scheduled)return;
      scheduled=true;
      requestAnimationFrame(()=>{
        scheduled=false;
        installFinalHeroMotionStyle();
      });
    };
    installFinalHeroMotionStyle();
    new MutationObserver(ensure).observe(document.head,{childList:true,subtree:false});
    [100,500,1200,2400].forEach(delay=>setTimeout(installFinalHeroMotionStyle,delay));
  }
  function setupHeroMotion(){
    const slider=document.getElementById('heroSlider');
    if(!slider)return;
    const slides=[...slider.querySelectorAll('.heroSlide')];
    if(!slides.length)return;
    let active=slides.find(slide=>slide.classList.contains('is-active'))||slides[0];
    let lastChange=Date.now();
    const clean=slide=>slide&&slide.classList.remove('psf-enter','psf-leave');
    const replay=(next,prev)=>{
      if(!next)return;
      lastChange=Date.now();
      slides.forEach(slide=>slide.classList.remove('psf-enter'));
      if(prev&&prev!==next){
        prev.classList.remove('psf-leave');
        void prev.offsetWidth;
        prev.classList.add('psf-leave');
        window.setTimeout(()=>clean(prev),720);
      }
      next.classList.remove('psf-enter');
      void next.offsetWidth;
      next.classList.add('psf-enter');
      window.setTimeout(()=>clean(next),1320);
      active=next;
    };
    const currentActive=()=>slides.find(slide=>slide.classList.contains('is-active'))||slides[0];
    const observer=new MutationObserver(()=>{
      const next=currentActive();
      if(next!==active)replay(next,active);
    });
    slides.forEach(slide=>observer.observe(slide,{attributes:true,attributeFilter:['class']}));
    requestAnimationFrame(()=>replay(currentActive(),null));
    slider.addEventListener('pointerup',()=>window.setTimeout(()=>{
      const next=currentActive();
      if(next!==active)replay(next,active);
    },80),true);
    window.setInterval(()=>{
      if(document.hidden)return;
      const nextButton=slider.querySelector('.heroAdvance,.heroNext');
      if(nextButton&&Date.now()-lastChange>6200)nextButton.click();
    },1400);
  }

  ready(()=>{
    protectFinalHeroMotionStyle();
    setupHeroMotion();
    restoreRideCategory();
    patchMenuCopy();
    const wrap=document.getElementById('categoryTiles');
    if(wrap){
      let scheduled=false;
      new MutationObserver(()=>{
        if(scheduled)return;
        scheduled=true;
        setTimeout(()=>{scheduled=false;restoreRideCategory();},0);
      }).observe(wrap,{childList:true,subtree:false});
    }
    [250,900,1800,3200].forEach(delay=>setTimeout(()=>{restoreRideCategory();patchMenuCopy();},delay));
  });
})();
