(function(){
  'use strict';
  const esc=value=>String(value??'').replace(/[&<>']/g,match=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;'}[match]));
  const ready=fn=>document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn();

  function upgradeHeroScene(){
    const slider=document.getElementById('heroSlider');
    if(!slider)return;
    slider.classList.add('psfSceneHero');
    slider.querySelectorAll('.heroPosition,.heroDots,.dots,.heroPrev').forEach(node=>node.remove());
  }
  function removeButtonArrows(root=document){
    root.querySelectorAll(".primary span[aria-hidden='true'],button span[aria-hidden='true']").forEach(node=>node.remove());
    root.querySelectorAll('.primary,button').forEach(node=>{
      [...node.childNodes].forEach(child=>{
        if(child.nodeType===Node.TEXT_NODE)child.textContent=child.textContent.replace(/[→↗]/g,'').replace(/\s{2,}/g,' ');
      });
    });
  }

  function patchCopyGlitches(){
    document.querySelectorAll('#prevPage').forEach(button=>{
      button.setAttribute('aria-label','Предыдущая страница');
      [...button.childNodes].forEach(child=>{
        if(child.nodeType===Node.TEXT_NODE&&child.textContent.includes('Предыдрщая'))child.textContent=child.textContent.replaceAll('Предыдрщая','Предыдущая');
      });
    });
  }

  function setupSceneMotion(){
    const slider=document.getElementById('heroSlider');
    if(!slider||slider.dataset.sceneController)return;
    const slides=[...slider.querySelectorAll('.heroSlide')];
    if(!slides.length)return;
    slider.dataset.sceneController='single';
    document.getElementById('psHeroReducedMotionStyle')?.remove();
    document.getElementById('psfScrolltideHeroStyle')?.remove();
    let current=0, busy=false, autoplay=0, finishTimer=0, gesture=null, suppressClick=false;
    const schedule=()=>{
      clearTimeout(autoplay);
      if(!document.hidden&&!slider.querySelector(':focus-visible')&&!gesture){
        autoplay=window.setTimeout(()=>setSlide(current+1),7200);
      }
    };
    const setSlide=(index,initial=false)=>{
      if(busy&&!initial)return;
      const next=(index+slides.length)%slides.length;
      if(next===current&&!initial){schedule();return;}
      clearTimeout(autoplay);
      clearTimeout(finishTimer);
      const previous=initial?null:slides[current];
      slides.forEach((slide,i)=>{
        slide.classList.remove('psf-enter','psf-leave','is-entering','is-leaving');
        slide.classList.toggle('is-active',i===next);
        slide.inert=i!==next;
        slide.setAttribute('aria-hidden',String(i!==next));
      });
      current=next;
      if(previous)previous.classList.add('psf-leave');
      slides[next].classList.add('psf-enter');
      const upcoming=slides[(next+1)%slides.length];
      upcoming.querySelectorAll('img').forEach(image=>image.loading='eager');
      busy=true;
      finishTimer=window.setTimeout(()=>{
        slides.forEach(slide=>slide.classList.remove('psf-enter','psf-leave'));
        busy=false;
        schedule();
      },1780);
    };
    slider.querySelector('.heroAdvance,.heroNext')?.addEventListener('click',()=>{
      if(!suppressClick)setSlide(current+1);
    });
    slider.addEventListener('keydown',event=>{
      if(!['ArrowLeft','ArrowRight'].includes(event.key))return;
      event.preventDefault();
      setSlide(current+(event.key==='ArrowRight'?1:-1));
    });
    slider.addEventListener('focusin',event=>{
      if(event.target.matches(':focus-visible'))clearTimeout(autoplay);
    });
    slider.addEventListener('focusout',()=>setTimeout(schedule,0));
    document.addEventListener('visibilitychange',schedule);
    slider.addEventListener('pointerdown',event=>{
      if(event.button!==0||event.isPrimary===false||event.target.closest('a,input,select,textarea'))return;
      gesture={id:event.pointerId,x:event.clientX,y:event.clientY,dx:0,dragging:false};
      suppressClick=false;
      clearTimeout(autoplay);
    });
    slider.addEventListener('pointermove',event=>{
      if(!gesture||gesture.id!==event.pointerId)return;
      const dx=event.clientX-gesture.x,dy=event.clientY-gesture.y;
      if(!gesture.dragging){
        if(Math.abs(dy)>8&&Math.abs(dy)>Math.abs(dx)){gesture=null;schedule();return;}
        if(Math.abs(dx)<8)return;
        gesture.dragging=true;
        slider.setPointerCapture(event.pointerId);
      }
      gesture.dx=dx;
      event.preventDefault();
    });
    const finishGesture=event=>{
      if(!gesture||gesture.id!==event.pointerId)return;
      const {dx,dragging}=gesture;
      gesture=null;
      if(slider.hasPointerCapture(event.pointerId))slider.releasePointerCapture(event.pointerId);
      suppressClick=dragging;
      if(event.type==='pointerup'&&dragging&&Math.abs(dx)>45)setSlide(current+(dx<0?1:-1));
      else schedule();
      setTimeout(()=>{suppressClick=false;},0);
    };
    window.addEventListener('pointerup',finishGesture);
    window.addEventListener('pointercancel',finishGesture);
    slider.addEventListener('click',event=>{
      if(suppressClick){event.preventDefault();event.stopPropagation();}
    },true);
    // Decode the first scene before entrance; preload the next scene before autoplay.
    slider.classList.add('psf-preparing');
    slides.forEach((slide,i)=>{
      slide.classList.toggle('is-active',i===0);
      slide.inert=i!==0;
      slide.setAttribute('aria-hidden',String(i!==0));
    });
    const images=[...slides[0].querySelectorAll('img')];
    const decoded=Promise.all(images.map(image=>image.decode?.().catch(()=>{})));
    Promise.race([decoded,new Promise(resolve=>setTimeout(resolve,2400))]).then(()=>{
      slider.classList.remove('psf-preparing');
      requestAnimationFrame(()=>setSlide(0,true));
    });
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
    upgradeHeroScene();
    setupSceneMotion();
    setupSceneParallax();
    patchCopyGlitches();
    removeButtonArrows();
    const categoryWrap=document.getElementById('categoryTiles');
    if(categoryWrap){
      let scheduled=false;
      new MutationObserver(()=>{
        if(scheduled)return;
        scheduled=true;
        setTimeout(()=>{scheduled=false;removeButtonArrows(categoryWrap);patchCopyGlitches();},0);
      }).observe(categoryWrap,{childList:true,subtree:false});
    }
  });
})();
