(()=>{
  /* v2: intentionally non-invasive.
     The old implementation resized html/body from visualViewport.width and re-ran
     after every DOM mutation. On iOS Safari that can feed a temporary viewport
     change back into the document layout and make the page appear to rescale after
     async catalog rendering. Width control is now CSS-only. */
  if(!window.matchMedia('(max-width:850px)').matches)return;
  document.documentElement.classList.add('ps-mobile-static-viewport');
})();
