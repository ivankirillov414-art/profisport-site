(()=>{
  const mq=window.matchMedia('(max-width:850px)');
  let ticking=false;
  let observer;

  function visibleWidth(){
    const vv=window.visualViewport;
    const w=vv&&vv.width?vv.width:window.innerWidth;
    return Math.max(280,Math.round(w*100)/100);
  }

  function lock(){
    if(!mq.matches||!document.documentElement)return;
    const w=visibleWidth();
    const root=document.documentElement;
    const body=document.body;
    root.style.setProperty('--ps-visible-width',w+'px');
    root.style.width=w+'px';
    root.style.maxWidth=w+'px';
    root.style.overflowX='hidden';
    if(body){
      body.style.width=w+'px';
      body.style.maxWidth=w+'px';
      body.style.overflowX='hidden';
    }
    if(window.scrollX!==0) window.scrollTo(0,window.scrollY);
  }

  function clampDynamic(){
    if(!mq.matches||!document.body)return;
    const w=visibleWidth();
    const selectors=[
      '.top','main','.hero','.heroTrack','.heroSlide','.categorySection','.categories',
      '#catalogProducts','.sectionHead','.mobileSearch','.smartSearchWrap','.smartSuggest',
      '.filters','.kantFilterRow','.products','.product','.picker','.service',
      '.mobileBottomNav','.checkoutPage','.productShell','.productDetail','.profilePage',
      '.profileGrid','.profileCard','.drawer','.mobileMenu'
    ];
    document.querySelectorAll(selectors.join(',')).forEach(el=>{
      el.style.maxWidth='100%';
      el.style.minWidth='0';
      if(el.matches('.top,main,.hero,.categorySection,#catalogProducts,.categories,.filters,.kantFilterRow,.products,.picker,.service,.checkoutPage,.productShell,.productDetail,.profilePage,.profileGrid,.profileCard')){
        el.style.boxSizing='border-box';
      }
    });
    document.querySelectorAll('input,select,textarea').forEach(el=>{
      if(parseFloat(getComputedStyle(el).fontSize)<16) el.style.fontSize='16px';
      el.style.maxWidth='100%';
      el.style.minWidth='0';
    });
    document.querySelectorAll('img,video,canvas,svg').forEach(el=>{el.style.maxWidth='100%'});
    const rightEdge=w+2;
    document.querySelectorAll('body *').forEach(el=>{
      if(el.closest('.heroTrack')&&el.classList.contains('heroSlide'))return;
      const cs=getComputedStyle(el);
      if(cs.display==='none'||cs.visibility==='hidden')return;
      const r=el.getBoundingClientRect();
      if(r.width>rightEdge&&cs.position!=='fixed'){
        el.style.maxWidth='100%';
        el.style.minWidth='0';
      }
    });
  }

  function run(){
    if(ticking)return;
    ticking=true;
    requestAnimationFrame(()=>{
      ticking=false;
      lock();
      clampDynamic();
      lock();
    });
  }

  function start(){
    run();
    [50,150,350,700,1200,2000,4000,7000].forEach(ms=>setTimeout(run,ms));
    if(window.visualViewport){
      visualViewport.addEventListener('resize',run,{passive:true});
      visualViewport.addEventListener('scroll',run,{passive:true});
    }
    window.addEventListener('resize',run,{passive:true});
    window.addEventListener('orientationchange',run,{passive:true});
    window.addEventListener('pageshow',run,{passive:true});
    observer=new MutationObserver(run);
    observer.observe(document.body,{subtree:true,childList:true});
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
  else start();
})();
