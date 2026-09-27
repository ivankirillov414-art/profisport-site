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
