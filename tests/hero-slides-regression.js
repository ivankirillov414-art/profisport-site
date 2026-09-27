const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),read=p=>fs.readFileSync(path.join(root,p),'utf8');
const index=read('index.html'),app=read('app.js'),css=read('hero-slides-v5.css');
assert.equal((index.match(/<article class="heroSlide /g)||[]).length,4,'Keep all four hero slides');
for(const name of ['bikeSlide','winterSlide','serviceSlide','storageSlide'])assert(index.includes(name));
assert(read('storefront-v2.css').includes('hero-slides-v5.css?v='));
const setup=app.slice(app.indexOf('function setupHero(){'),app.indexOf('function setupStoragePromo(){'));
assert(setup.includes("track.style.transform='none'"));
assert(!setup.includes('translateX('),'Do not translate a track with display:none siblings');
assert(css.includes('.heroTrack>.heroSlide.is-active{display:grid!important}'));
assert(css.includes('background:transparent!important'));
for(const asset of ['assets/hero/classic-workshop-wide-v5.webp','assets/hero/classic-workshop-mobile-v5.webp','assets/hero/winter-storage-v10.webp'])assert(fs.statSync(path.join(root,asset)).size>10000,asset+' must exist');
console.log('Hero slides regression checks passed.');
// Exercise the controller: transitions retain an outgoing scene, keyboard/click
// wrap correctly, and reduced motion uses a slower timer without spatial outgoing motion.
const vm=require('node:vm');
function runtime(reduced=false){
 const listeners={},timers=new Map();let seq=0;
 const node=()=>({classes:new Set(),attrs:{},style:{},inert:false,
  classList:{toggle(k,on){on?this.owner.classes.add(k):this.owner.classes.delete(k)},add(k){this.owner.classes.add(k)},remove(k){this.owner.classes.delete(k)}},
  setAttribute(k,v){this.attrs[k]=v},addEventListener(k,fn){this.listeners[k]=fn},listeners:{}});
 const slides=Array.from({length:4},node);slides.forEach(s=>s.classList.owner=s);
 const next=node(),track=node(),position={textContent:''};const slider=node();slider.classList.owner=slider;
 slider.querySelector=k=>({'.heroTrack':track,'.heroNext':next}[k]||null);slider.contains=()=>false;
 slider.hasPointerCapture=()=>false;
 const context={$:k=>k==='#heroSlider'?slider:k==='#heroCurrent'?position:null,$$:k=>k==='.heroSlide'?slides:[],
  matchMedia:q=>({matches:q.includes('reduce')&&reduced,addEventListener(){}}),document:{hidden:false,activeElement:null,addEventListener(){}},window:{addEventListener(k,fn){listeners[k]=fn}},
  setInterval(fn,delay){const id=++seq;timers.set(id,{fn,delay});return id},clearInterval(id){timers.delete(id)},setTimeout(){return ++seq},clearTimeout(){}};
 vm.runInNewContext(setup+';setupHero();',context);return{slider,slides,next,position,timers};
}
for(const reduced of [false,true]){
 const t=runtime(reduced);assert.equal(t.position.textContent,'01');
 assert.equal(t.timers.size,1);assert.equal([...t.timers.values()][0].delay,reduced?8000:5000);
 t.next.onclick();assert.equal(t.position.textContent,'02');
 assert.equal(t.slides[0].classes.has('is-leaving'),!reduced);
 assert(t.slides[0].inert);assert(!t.slides[1].inert);
 t.slider.listeners.keydown({key:'ArrowLeft',preventDefault(){}});assert.equal(t.position.textContent,'01');
 t.slider.listeners.keydown({key:'ArrowLeft',preventDefault(){}});assert.equal(t.position.textContent,'04');
 assert.equal(t.slides.filter(s=>!s.inert).length,1);
}
assert(!index.includes('class="heroArrow'),'No visible hero arrow controls');
assert(!index.includes('class="dots"'),'No white indicator bars');
console.log('Hero click/keyboard wrap, outgoing motion, inert slides and reduced autoplay passed.');
