'use strict';
(() => {
  const config = window.KOSMOPORT_CONFIG;
  const funnel = step => window.dispatchEvent(new CustomEvent('kosmoport:funnel', {detail:{step}}));
  const backTop = document.querySelector('.back-top');
  const updateBackTop = () => { backTop.hidden = window.scrollY < Math.max(420, window.innerHeight * 0.65); };
  window.addEventListener('scroll', updateBackTop, {passive:true});
  window.addEventListener('resize', updateBackTop);
  updateBackTop();
  const money = value => value === null ? 'Стоимость появится позже' : new Intl.NumberFormat('ru-RU').format(value) + ' ₽';
  const menuToggle = document.querySelector('.menu-toggle');
  const mobileMenu = document.querySelector('#mobile-menu');
  const closeMenu = () => { mobileMenu.hidden = true; menuToggle.setAttribute('aria-expanded', 'false'); menuToggle.setAttribute('aria-label', 'Открыть меню'); };
  menuToggle.addEventListener('click', () => {
    const expanded = menuToggle.getAttribute('aria-expanded') === 'true';
    mobileMenu.hidden = expanded;
    menuToggle.setAttribute('aria-expanded', String(!expanded));
    menuToggle.setAttribute('aria-label', expanded ? 'Открыть меню' : 'Закрыть меню');
  });
  mobileMenu.querySelectorAll('a').forEach(a => a.addEventListener('click', closeMenu));
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });
  window.matchMedia('(min-width:901px)').addEventListener('change', event => { if(event.matches) closeMenu(); });
  document.querySelectorAll('[data-open]').forEach(button => button.addEventListener('click', event => {
    event.preventDefault();
    const dialog = document.getElementById(button.dataset.open);
    if (!dialog) return;
    if(dialog.id==='ticket-dialog'){ if(button.dataset.ticketChildren!==undefined){childInput.value=button.dataset.ticketChildren;adultInput.value=button.dataset.ticketAdults;planner?.setGuests(childInput.valueAsNumber,adultInput.valueAsNumber);} plannerHistory=[];dialog.classList.remove('cart-expanded');document.querySelector('#planner-cart-toggle').setAttribute('aria-expanded','false');planner?.goToStep(1);update(); }
    dialog.showModal();
    funnel(button.dataset.open === 'group-dialog' ? 'group_open' : 'ticket_open');
    document.body.classList.add('modal-open');
  }));
  document.querySelectorAll('dialog').forEach(dialog => {
    dialog.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('close', () => document.body.classList.remove('modal-open'));
    dialog.addEventListener('click', event => {
      if(event.target !== dialog) return;
      const box = dialog.getBoundingClientRect();
      if(event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
    });
  });
  let plannerHistory = [];
  let count = 2;
  let currentQuote = null;
  let currentBasket = null;
  const childInput = document.querySelector('#children-quantity');
  const adultInput = document.querySelector('#adults-quantity');
  const visitDate = document.querySelector('#visit-date');
  const pricingEvent={opening_date:config.openingDate,closing_date:config.closingDate};
  const planner=window.KosmoportPricing?.createPlanner(config.priceCatalog,pricingEvent);
  const plannerDialog=document.querySelector('#ticket-dialog');
  const selectionFields=[...document.querySelectorAll('.selection-input')];
  function resetSavedRequest(form) {
    if(!form) return;
    if(form.dataset.busy) form.dataset.dirty='true';
    delete form.dataset.saved;
    if(!form.dataset.busy) form.querySelector('[type="submit"]').disabled=false;
  }
  const quoteError=code=>code==='invalid_quantity'?'Укажите от 1 до 200 гостей: отдельно детей и взрослых.':code==='invalid_selection'?'Укажите от 0 до 20 наборов или активностей.':'Выберите действительную дату в период выставки.';
  const fieldStep=field=>Number(field.closest('[data-planner-panel]').dataset.plannerPanel);
  const invalidSelections=()=>selectionFields.filter(field=>!Number.isInteger(field.valueAsNumber)||field.valueAsNumber<0||field.valueAsNumber>20);
  const resetPlannerScroll=()=>{document.querySelector('.planner-scroll').scrollTop=0;};
  const update=()=>{
    const state=planner?.getState()||{step:1,date:visitDate.value,children:1,adults:1,combos:{},extras:{}};
    const previous=JSON.stringify(currentBasket);
    childInput.setCustomValidity('');adultInput.setCustomValidity('');visitDate.setCustomValidity('');
    currentQuote=window.KosmoportPricing?window.KosmoportPricing.quote(config.priceCatalog,pricingEvent,state):{ok:false,error:'unavailable'};
    currentBasket=planner?.summary()||{ok:false,error:'unavailable'};
    const invalidFields=invalidSelections();
    const valid=currentBasket.ok&&invalidFields.length===0;
    if(previous!==JSON.stringify(currentBasket))resetSavedRequest(document.querySelector('#visit-request-form'));
    count=currentQuote.ok?currentQuote.guest_count:0;
    document.querySelector('#quantity').textContent=currentQuote.ok?String(count):'—';
    document.querySelector('#total').textContent=currentQuote.ok?money(currentQuote.admission_total):'Проверьте данные';
    document.querySelector('#quote-status').textContent=currentQuote.ok?'':currentQuote.error==='unavailable'?'Не удалось загрузить расчёт. Обновите страницу.':quoteError(currentQuote.error);
    if(currentQuote.ok){
      document.querySelector('#child-rate').textContent=money(currentQuote.unit_prices.child)+' за билет';
      document.querySelector('#adult-rate').textContent=money(currentQuote.unit_prices.adult)+' за билет';
      document.querySelector('#price-day').textContent=(currentQuote.day_type==='weekend'?'Выходной день':'Будний день')+': детский '+money(currentQuote.unit_prices.child)+', взрослый '+money(currentQuote.unit_prices.adult);
    }else{
      document.querySelector('#price-day').textContent='Стоимость зависит от выбранной даты';
      if(currentQuote.error==='invalid_quantity')childInput.setCustomValidity(quoteError(currentQuote.error));
      else if(currentQuote.error!=='unavailable')visitDate.setCustomValidity(quoteError(currentQuote.error));
    }
    document.querySelectorAll('[data-guest-change]').forEach(button=>{const[kind,delta]=button.dataset.guestChange.split(':');const value=(kind==='children'?childInput:adultInput).valueAsNumber;button.disabled=Number(delta)<0?!(value>0):currentQuote.ok&&count>=200;});
    document.querySelectorAll('[data-selection-change]').forEach(button=>{const[kind,id,delta]=button.dataset.selectionChange.split(':');const value=state[kind][id];button.disabled=Number(delta)<0?value<=0:value>=20;});
    document.querySelectorAll('[data-planner-panel]').forEach(panel=>panel.hidden=Number(panel.dataset.plannerPanel)!==state.step);
    document.querySelectorAll('[data-planner-step]').forEach(button=>{const active=Number(button.dataset.plannerStep)===state.step;button.classList.toggle('is-active',active);if(active)button.setAttribute('aria-current','step');else button.removeAttribute('aria-current');});
    const cartLines=document.querySelector('#cart-lines');cartLines.replaceChildren();
    if(currentBasket.ok)currentBasket.lines.forEach(line=>{const item=document.createElement('li'),label=document.createElement('span'),amount=document.createElement('strong');label.textContent=line.label+' × '+line.quantity;amount.textContent=money(line.total);item.append(label,amount);cartLines.append(item);});
    document.querySelector('#cart-date').textContent=currentQuote.ok?new Intl.DateTimeFormat('ru-RU',{day:'numeric',month:'long',weekday:'short',timeZone:'UTC'}).format(new Date(state.date+'T12:00:00Z')):'Выберите дату посещения';
    for(const[id,key]of[['cart-admission','admission_total'],['cart-bundles','bundles_total'],['cart-extras','extras_total']])document.getElementById(id).textContent=currentBasket.ok?money(currentBasket[key]):'—';
    const totalText=valid?money(currentBasket.planned_total):!currentQuote.ok&&currentQuote.error!=='invalid_quantity'?'Выберите дату':'Проверьте количество';
    document.querySelector('#planner-total').textContent=totalText;document.querySelector('#mobile-planner-total').textContent=totalText;
    document.querySelector('#mobile-cart-guests').textContent=currentQuote.ok?'Гостей: '+count:'Выберите билеты';
    document.querySelector('#cart-note').textContent=currentBasket.ok&&currentBasket.requires_terms_confirmation?'Включение входного билета в наборы уточняется. Сумма позиций предварительная; это не билет и не подтверждение оплаты.':'План не является билетом или подтверждением оплаты.';
    const nextLabels=['Выбрать наборы','Добавить активности',document.querySelector('#visit-request-form')?'Перейти к контактам':'Перейти к итогу','Сохранить план'];
    const blocked=!currentQuote.ok||invalidFields.some(field=>fieldStep(field)<=state.step);
    for(const id of['planner-next','cart-next','mobile-planner-next']){const button=document.getElementById(id);button.textContent=nextLabels[state.step-1];button.disabled=blocked;}
    document.querySelectorAll('[data-planner-summary]').forEach(button=>button.disabled=!valid);
    document.querySelector('#price-day').dataset.dayType=currentQuote.ok?currentQuote.day_type:'';
    document.querySelector('#planner-next').hidden=state.step===4;document.querySelector('#planner-back').disabled=state.step===1;
    document.querySelector('#planner-status').textContent=invalidFields.length?quoteError('invalid_selection'):'';
    const submit=document.querySelector('#visit-request-form [type="submit"]');if(submit&&!submit.closest('form').dataset.busy)submit.disabled=!valid||submit.closest('form').dataset.saved==='true';
  };
  const navigate=(step,recordHistory=true)=>{
    if(!planner)return false;
    const current=planner.getState();
    if(step>current.step){
      const invalid=invalidSelections().find(field=>fieldStep(field)<step);
      if(invalid){planner.goToStep(fieldStep(invalid));update();invalid.reportValidity();return false;}
      if(!currentQuote?.ok){planner.goToStep(1);update();visitDate.reportValidity();childInput.reportValidity();return false;}
    }
    if(planner.goToStep(step)){
      if(recordHistory&&step!==current.step)plannerHistory.push(current.step);
      update();resetPlannerScroll();
      const heading=document.querySelector(`[data-planner-panel="${step}"] h3`);
      if(heading){heading.tabIndex=-1;heading.focus({preventScroll:true});}
      funnel('planner_step_'+step);
      return true;
    }
    return false;
  };
  document.querySelectorAll('[data-planner-step]').forEach(button=>button.addEventListener('click',()=>navigate(Number(button.dataset.plannerStep))));
  for(const id of['planner-next','cart-next','mobile-planner-next'])document.getElementById(id).addEventListener('click',()=>{const step=planner?.getState().step||1;if(step===4)document.querySelector('#planner-download').click();else navigate(step+1);});
  document.querySelectorAll('[data-planner-summary]').forEach(button=>button.addEventListener('click',()=>navigate(4)));
  document.querySelector('#planner-back').addEventListener('click',()=>{const previous=plannerHistory.at(-1)??(planner?.getState().step||1)-1;if(navigate(previous,false))plannerHistory.pop();});
  document.querySelectorAll('[data-guest-change]').forEach(button=>button.addEventListener('click',()=>{const[kind,delta]=button.dataset.guestChange.split(':');const input=kind==='children'?childInput:adultInput;const value=Number.isInteger(input.valueAsNumber)?input.valueAsNumber:0;input.value=String(Math.max(0,Math.min(200,value+Number(delta))));planner?.setGuests(childInput.valueAsNumber,adultInput.valueAsNumber);update();}));
  [childInput,adultInput].forEach(input=>input.addEventListener('input',()=>{planner?.setGuests(childInput.valueAsNumber,adultInput.valueAsNumber);update();}));
  visitDate.addEventListener('change',()=>{planner?.setDate(visitDate.value);update();});
  selectionFields.forEach(input=>input.addEventListener('input',()=>{input.setCustomValidity('');if(!planner?.setQuantity(input.dataset.selectionKind,input.dataset.selectionId,input.valueAsNumber))input.setCustomValidity(quoteError('invalid_selection'));update();}));
  document.querySelectorAll('[data-selection-change]').forEach(button=>button.addEventListener('click',()=>{const[kind,id,delta]=button.dataset.selectionChange.split(':');const state=planner?.getState();if(!state)return;const value=Math.max(0,Math.min(20,state[kind][id]+Number(delta)));planner.setQuantity(kind,id,value);const field=selectionFields.find(input=>input.dataset.selectionKind===kind&&input.dataset.selectionId===id);field.value=String(value);field.setCustomValidity('');update();}));
  document.querySelector('#planner-cart-toggle').addEventListener('click',()=>{const expanded=plannerDialog.classList.toggle('cart-expanded');document.querySelector('#planner-cart-toggle').setAttribute('aria-expanded',String(expanded));if(expanded)resetPlannerScroll();});
  update();
  function downloadFile(text, filename, type) {
    const url = URL.createObjectURL(new Blob([text], {type}));
    const link = document.createElement('a');
    link.href = url; link.download = filename;
    document.body.append(link); link.click(); link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 5000);
  }
  const basketDescription=()=>currentBasket?.ok?currentBasket.lines.map(line=>line.label+' × '+line.quantity+' — '+money(line.total)).join('\n')+'\nСумма выбранных позиций: '+money(currentBasket.planned_total)+(currentBasket.requires_terms_confirmation?'\nВключение входа в наборы уточняется.':''):'Проверьте выбранные позиции.';
  document.querySelector('#planner-download').addEventListener('click',()=>{
    if(!currentBasket?.ok||invalidSelections().length)return;
    const text=['КОСМОПОРТ — ПЛАН ПОСЕЩЕНИЯ',config.venue,'Дата: '+planner.getState().date,'',basketDescription(),'','Это план, не покупка, не бронь и не билет. Оплата не проводилась. Наборы активностей оплачиваются дополнительно к входным билетам.'].join('\n');
    downloadFile('\uFEFF'+text,'kosmoport-visit-plan.txt','text/plain;charset=utf-8');
  });
  const escapeICS = text => String(text).replace(/\\/g,'\\\\').replace(/\r?\n/g,'\\n').replace(/;/g,'\\;').replace(/,/g,'\\,');
  function calendar(date) {
    const next = new Date(date + 'T12:00:00Z');
    next.setUTCDate(next.getUTCDate() + 1);
    const endDate = next.toISOString().slice(0,10).replace(/-/g,'');
    const stamp = new Date().toISOString().replace(/[-:]/g,'').replace(/\.\d{3}/,'');
    const uid = globalThis.crypto?.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const lines = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Kosmoport//Visit planner//RU','CALSCALE:GREGORIAN','BEGIN:VEVENT',`UID:${uid}@kosmoport`,`DTSTAMP:${stamp}`,`DTSTART;VALUE=DATE:${date.replace(/-/g,'')}`,`DTEND;VALUE=DATE:${endDate}`,`SUMMARY:${escapeICS('Космопорт — запланировать посещение')}`,`LOCATION:${escapeICS(config.venue)}`,`DESCRIPTION:${escapeICS('План посещения выставки. Это не билет и не бронирование. Перед визитом проверьте часы работы и купите билеты. Выставка: ' + config.dates + '.')}`,'STATUS:TENTATIVE','TRANSP:TRANSPARENT','END:VEVENT','END:VCALENDAR'];
    // RFC 5545 folding uses UTF-8 octets, not JavaScript character counts.
    const encoder = new TextEncoder();
    const folded = lines.map(line => {
      const parts = []; let part = ''; let bytes = 0;
      for (const char of line) {
        const size = encoder.encode(char).length;
        if (bytes + size > 73) { parts.push(part); part = ' '; bytes = 1; }
        part += char; bytes += size;
      }
      parts.push(part); return parts.join('\r\n');
    });
    downloadFile(folded.join('\r\n') + '\r\n', `kosmoport-${date}.ics`, 'text/calendar;charset=utf-8');
  }
  document.querySelectorAll('.calendar-button').forEach(button => button.addEventListener('click', () => {
    const inTicketDialog = button.closest('#ticket-dialog');
    const input = document.querySelector('#visit-date');
    const status = document.querySelector('#date-status');
    if (inTicketDialog && (!input.value || input.value < config.openingDate || (config.closingDate && input.value > config.closingDate) || !input.checkValidity())) {
      status.textContent = 'Выберите дату в период работы выставки.';
      input.reportValidity(); input.focus(); return;
    }
    calendar(inTicketDialog ? input.value : config.openingDate);
    if(inTicketDialog) status.textContent = 'Файл календаря подготовлен. Откройте его, чтобы сохранить дату.';
  }));
  document.querySelector('#visit-date').addEventListener('change', () => { document.querySelector('#date-status').textContent = ''; resetSavedRequest(document.querySelector('#visit-request-form')); });
  async function sendRequest(form,payload,status) {
    if(form.dataset.busy) return;
    if(!form.reportValidity()) return;
    const button=form.querySelector('[type="submit"]');
    const signature=JSON.stringify(payload);
    if(form.dataset.signature!==signature) {
      form.dataset.requestId=[...crypto.getRandomValues(new Uint8Array(16))].map(x=>x.toString(16).padStart(2,'0')).join('');
      form.dataset.signature=signature;
    }
    form.dataset.busy='true';button.disabled=true;button.setAttribute('aria-busy','true');status.textContent='Отправляем заявку…';
    const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),15000);
    try {
      const endpoint=new URL(config.requestEndpoint,document.baseURI);
      endpoint.searchParams.set('r','website/request-config');
      const bootResponse=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
      const boot=await bootResponse.json();
      if(!bootResponse.ok || !boot.ok || !boot.data.enabled || !boot.data.csrf) throw Error('Приём заявок сейчас недоступен. Повторите позже.');
      endpoint.searchParams.set('r','website/request');
      const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':boot.data.csrf},body:JSON.stringify({...payload,request_id:form.dataset.requestId}),signal:controller.signal});
      const result=await response.json();
      if(!response.ok || !result.ok || result.data?.saved!==true) throw Error(result.error?.message || 'Сервер не подтвердил сохранение заявки. Повторите отправку.');
      status.textContent='Заявка сохранена. Номер '+result.data.id.slice(0,8).toUpperCase()+'. Организатор увидит её в CRM. Посещение пока не забронировано, оплата не проводилась.';
      form.dataset.saved='true';funnel('request_saved');
    } catch(error) {
      const connectionError=['TypeError','SyntaxError'].includes(error.name);
      status.textContent=error.name==='AbortError'?'Сервер не успел ответить. Повторите отправку: одинаковая заявка сохранится один раз.':connectionError?'Не удалось получить ответ сервера. Проверьте соединение и повторите отправку.':(error.message || 'Не удалось отправить заявку. Повторите позже.');
    } finally {clearTimeout(timer);delete form.dataset.busy;if(form.dataset.dirty)delete form.dataset.saved;delete form.dataset.dirty;button.disabled=form.dataset.saved==='true';button.removeAttribute('aria-busy');}
  }
  function sourceTag() {const p=new URL(location.href).searchParams;return ['utm_source','utm_medium','utm_campaign'].map(k=>p.get(k)?.slice(0,70)).filter(Boolean).join(' / ').slice(0,300);}
  document.querySelectorAll('#group-form,#visit-request-form').forEach(form=>form.addEventListener('input',()=>resetSavedRequest(form)));
  document.querySelector('#visit-request-form')?.addEventListener('submit',event=>{
    event.preventDefault();const form=event.currentTarget,data=new FormData(form),date=document.querySelector('#visit-date');
    if(!date.reportValidity() || !childInput.reportValidity() || !adultInput.reportValidity() || !currentBasket?.ok || invalidSelections().length)return;
    sendRequest(form,{kind:config.salesOpen?'visit':'notify',name:String(data.get('name')).trim(),contact:String(data.get('contact')).trim(),org:'',date:date.value,quantity:count,message:basketDescription(),source:sourceTag(),consent:data.get('consent')==='on',website:String(data.get('website')||'')},document.querySelector('#visit-request-status'));
  });
  document.querySelector('#group-form').addEventListener('submit', event => {
    event.preventDefault();
    const form = event.currentTarget;
    if(!form.reportValidity()) return;
    const data = new FormData(form);
    if(config.requestsEnabled) {
      sendRequest(form,{kind:'group',name:String(data.get('name')).trim(),contact:String(data.get('contact')).trim(),org:String(data.get('org')).trim(),date:String(data.get('date')),quantity:Number(data.get('size')),message:String(data.get('message')||'').trim(),source:sourceTag(),consent:data.get('consent')==='on',website:String(data.get('website')||'')},document.querySelector('#group-status'));
      return;
    }
    const text = ['КОСМОПОРТ — ПЛАН ГРУППОВОГО ПОСЕЩЕНИЯ', '', 'Это черновик для согласования. Не является заявкой или бронированием.', '', 'Место: ' + config.venue, 'Группа: ' + String(data.get('org')).trim(), 'Гостей: ' + data.get('size'), 'Желаемая дата: ' + data.get('date'), 'Пожелания: ' + (String(data.get('message')).trim() || 'Не указаны'), '', 'Вопросы для организатора:', '— Доступны ли нужная дата и время?', '— Какая программа подходит для возраста детей?', '— Какова стоимость группового посещения и сопровождающих?', '— Какие дополнительные активности оплачиваются отдельно?', '', 'План создан на сайте Космопорта. Организатору не отправлен.'].join('\n');
    downloadFile('\uFEFF' + text, 'kosmoport-group-plan.txt', 'text/plain;charset=utf-8');
    document.querySelector('#group-status').textContent = 'План подготовлен для скачивания. Сохраните его для согласования визита.';
  });
  const adventures = [{position:'78% center'},{position:'center'},{position:'100% center'}];
  adventures[0].image = config.fields['k15-image'];
  adventures[0].alt = config.fields['k15-alt'];
  adventures[0].title = config.fields['k12-text'] + ' ' + config.fields['k13-text'];
  adventures[0].description = config.fields['k14-text'];
  adventures[0].note = config.dates;
  adventures[0].label = 'ЗНАКОМСТВО С ВЫСТАВКОЙ';
  adventures[1].image = config.fields['k53-image'];
  adventures[1].alt = config.fields['k53-alt'];
  adventures[1].title = 'Посещение выставки';
  adventures[1].label = 'КОСМОПОРТ';
  adventures[1].description = 'Интерактивная выставка для детей и взрослых в Оренбурге.';
  adventures[1].note = config.dates;
  adventures[2].image = config.fields['k24-image'];
  adventures[2].alt = config.fields['k24-alt'];
  adventures[2].title = 'Приходите вместе';
  adventures[2].label = 'ДЛЯ ДЕТЕЙ И ВЗРОСЛЫХ';
  adventures[2].description = 'Выберите удобный день и приходите в «Космопорт» всей семьёй.';
  adventures[2].note = config.dates;
  let adventureIndex = 0;
  const slideButtons = document.querySelectorAll('[data-slide]');
  const showAdventure = index => {
    adventureIndex = (index + adventures.length) % adventures.length;
    const slide = adventures[adventureIndex];
    document.querySelector('#adventure-title').textContent = slide.title;
    document.querySelector('#mission-label').textContent = slide.label;
    document.querySelector('#adventure-description').textContent = slide.description;
    document.querySelector('#adventure-note').textContent = slide.note;
    document.querySelector('#adventure-index').textContent = adventureIndex + 1;
    const picture = document.querySelector('#adventure-image');
    picture.src = slide.image; picture.alt = slide.alt; picture.style.objectPosition = slide.position;
    slideButtons.forEach(button => button.setAttribute('aria-pressed', String(Number(button.dataset.slide) === adventureIndex)));
  };
  document.querySelector('#adventure-prev').addEventListener('click', () => showAdventure(adventureIndex - 1));
  document.querySelector('#adventure-next').addEventListener('click', () => showAdventure(adventureIndex + 1));
  slideButtons.forEach(button => button.addEventListener('click', () => showAdventure(Number(button.dataset.slide))));
  document.querySelector('.adventure-stage').addEventListener('keydown', event => {
    if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    event.preventDefault(); showAdventure(adventureIndex + (event.key === 'ArrowRight' ? 1 : -1));
  });
  const galleryDialog = document.querySelector('#gallery-dialog');
  document.querySelectorAll('[data-gallery]').forEach(button => button.addEventListener('click', () => {
    const picture = document.querySelector('#gallery-full-image');
    const current = button.querySelector('img');
    picture.src = current?.getAttribute('src') || button.dataset.gallery; picture.alt = current?.getAttribute('alt') || button.dataset.galleryCaption;
    document.querySelector('#gallery-caption').textContent = picture.alt;
    galleryDialog.showModal(); document.body.classList.add('modal-open');
  }));
  // Checkout stays closed unless the organizer supplies a real HTTPS URL.
  if (config.salesOpen && config.checkoutUrl) {
    try {
      const url = new URL(config.checkoutUrl);
      if(url.protocol !== 'https:') throw new Error('Checkout must use HTTPS');
      const link = document.querySelector('#checkout-link');
      link.href = config.checkoutPath; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.hidden = false;
      document.querySelector('#checkout-note').hidden = false;
      link.addEventListener('click',()=>funnel('checkout_open'));
      document.querySelector('.sales-status').hidden = true;
      document.querySelector('.ticket-footnote').textContent = 'Билеты доступны онлайн';
      document.querySelector('#ticket-dialog-title').textContent = 'Выбрать билеты';
    } catch { /* Keep the accurate presale state if configuration is invalid. */ }
  }
})();
