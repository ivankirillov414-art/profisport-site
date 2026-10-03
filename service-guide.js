(()=>{
  const works=[
    ['diagnostics','Диагностика','Найдём причину неисправности','Осмотр узлов и уточнение причины шума, люфта или неполадок.','Стоимость после осмотра'],
    ['brakes','Тормоза','Контроль и настройка торможения','Проверка колодок, ручек и механизма тормоза.','Стоимость после осмотра'],
    ['transmission','Передачи и цепь','Чёткое переключение','Проверка переключателей, цепи и звёзд.','Стоимость после осмотра'],
    ['wheels','Колёса и покрышки','Проколы, биение и люфты','Осмотр покрышек, камер, ободов и втулок.','Стоимость после осмотра'],
    ['maintenance','Техническое обслуживание','Проверка основных узлов','Состояние крепежа, подшипников, тормозов и трансмиссии.','Стоимость после осмотра'],
    ['assembly','Сборка и настройка','Подготовка велосипеда к езде','Сборка, регулировка узлов и проверка креплений.','Стоимость после осмотра']
  ];
  const parts=[
    ['Седло и подседельный штырь',32.6,24.8,'Седло крепится к подседельному штырю. Его положение влияет на посадку и удобство педалирования.','Седло проворачивается, опускается или скрипит.','maintenance'],
    ['Руль и рулевая колонка',65.2,23.5,'Руль связан с вилкой через вынос и рулевую колонку. Подшипники позволяют поворачивать переднее колесо.','Люфт при торможении, заедание или стук при повороте.','maintenance'],
    ['Вилка',72.3,49.6,'Вилка удерживает переднее колесо. На некоторых велосипедах она также смягчает удары.','Люфт, стук, следы масла или заклинивание.','diagnostics'],
    ['Тормоза',77.8,65.9,'Тормоза замедляют вращение колёс. Конструкция бывает ободной или дисковой, с механическим либо гидравлическим приводом.','Слабое торможение, ручка проваливается или колодки постоянно трут.','brakes'],
    ['Покрышка и камера',92,51,'Покрышка обеспечивает контакт с дорогой. Воздух удерживается камерой или бескамерной системой.','Колесо теряет давление, есть порезы, трещины или вздутие.','wheels'],
    ['Обод и спицы',84,82,'Обод и спицы образуют жёсткую основу колеса и связывают его с втулкой.','Колесо бьёт в сторону, спицы ослабли или повреждены.','wheels'],
    ['Втулки',18.1,68.6,'Втулка находится в центре колеса. Подшипники обеспечивают его вращение на оси.','Колесо вращается туго, слышен хруст или ощущается боковой люфт.','wheels'],
    ['Цепь и звёзды',32.6,66.4,'Цепь передаёт усилие от педалей на заднее колесо. Размеры звёзд определяют передаточное отношение.','Цепь проскакивает, слетает или сильно изношена.','transmission'],
    ['Задний переключатель',18.1,76.3,'Переключатель перемещает цепь между задними звёздами и поддерживает её натяжение.','Передачи включаются неточно, цепь шумит или задевает соседнюю звезду.','transmission'],
    ['Каретка и шатуны',42,70.4,'Каретка позволяет шатунам вращаться в раме. Через шатуны усилие с педалей передаётся на передние звёзды.','Люфт, скрип или щелчки при педалировании.','maintenance'],
    ['Педали',51.4,72.4,'Педали дают опору ногам и вращаются на собственных подшипниках.','Педаль люфтит, заклинивает или повреждена.','maintenance'],
    ['Рама',50.3,42,'Рама соединяет основные узлы велосипеда. Её геометрия и размер определяют посадку.','Трещина, деформация или заметное повреждение соединений.','diagnostics'],
    ['Задний амортизатор',44.9,57.5,'Амортизатор смягчает удары, передаваемые от заднего колеса к раме двухподвесного велосипеда.','Стук, утечка масла или потеря давления.','diagnostics']
  ];
  const details={
    diagnostics:['Что изменилось в работе велосипеда и когда это началось.','Какие узлы требуют дополнительной проверки.','Какие работы и запчасти могут понадобиться.'],
    brakes:['Состояние колодок и тормозной поверхности.','Работа ручек, тросов или гидравлического привода.','Необходимость регулировки или замены деталей.'],
    transmission:['Износ цепи и состояние звёзд.','Точность переключения и состояние тросов.','Совместимость деталей при необходимости замены.'],
    wheels:['Состояние покрышек и камер.','Биение обода, натяжение спиц и люфт втулок.','Подходящий способ устранения прокола или повреждения.'],
    maintenance:['Состояние креплений и основных подшипников.','Работа тормозов и трансмиссии.','Какие узлы нуждаются в очистке, смазке или замене.'],
    assembly:['Комплектность и состояние велосипеда.','Сборка и настройка основных узлов.','Посадка и проверка креплений перед эксплуатацией.']
  };

  const injectPriceListStyle=()=>{
    if(document.getElementById('servicePriceListStyle'))return;
    const style=document.createElement('style');
    style.id='servicePriceListStyle';
    style.textContent=`
      #serviceWorks.servicePriceSection{position:relative;overflow:hidden}
      #serviceWorks.servicePriceSection::before{content:"";position:absolute;inset:18px 18px auto auto;width:180px;height:180px;border-radius:999px;background:rgba(245,201,40,.18);filter:blur(4px);pointer-events:none}
      .servicePriceIntro{position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:end;padding:18px 20px;margin:22px 0 18px;border:1px solid #ead46a;border-radius:18px;background:linear-gradient(135deg,#fffdf5,#fff7d6)}
      .servicePriceIntro small{font-size:11px;font-weight:900;letter-spacing:.12em;color:#766116}.servicePriceIntro b{display:block;margin-top:4px;font-size:26px;letter-spacing:-.4px}.servicePriceIntro span{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 14px;border-radius:999px;background:#17191c;color:#fff;font-size:13px;font-weight:800;white-space:nowrap}
      #serviceWorks .servicePriceList{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:0}
      #serviceWorks .servicePriceCard{display:grid;grid-template-columns:auto minmax(0,1fr);gap:14px;padding:18px;border:1px solid #e5e0cf;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(34,38,43,.07)}
      .serviceWorkArtwork{float:right;width:clamp(92px,25%,160px);height:auto;aspect-ratio:3/2;object-fit:contain;margin:0 0 12px 12px}
      .servicePriceCard .workNumber{grid-row:1 / span 3;display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:#f5c928;color:#17191c;font-size:13px;font-weight:900}.servicePriceCard h3{margin:0;font-size:20px}.servicePriceCard b{display:block;margin-top:5px;color:#38414a;font-size:14px}.servicePriceCard p{margin:8px 0 0;color:#626b74;font-size:14px;line-height:1.5}.servicePriceMeta{display:flex;align-items:center;justify-content:space-between;gap:12px;grid-column:2;margin-top:12px}.servicePriceMeta strong{font-size:15px}.servicePriceMeta span{display:block;margin-top:3px;color:#6b727a;font-size:12px}.servicePriceCard .workMore{justify-self:end;min-height:36px;padding:8px 13px;border-radius:999px;background:#f5c928;box-shadow:none;font-size:13px}.servicePriceCard.is-linked{outline:3px solid #f5c928;outline-offset:3px;box-shadow:0 14px 34px rgba(211,169,8,.24)}
      .servicePriceFooter{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-top:18px;padding:16px 18px;border-radius:16px;background:#f6f8fb}.servicePriceFooter p{margin:0;max-width:720px;color:#4f5963}.servicePriceFooter .serviceCall{white-space:nowrap}
      @media(max-width:760px){.servicePriceIntro{grid-template-columns:1fr;align-items:start}.servicePriceIntro span{justify-self:start}#serviceWorks .servicePriceList{grid-template-columns:1fr}.servicePriceMeta{display:grid}.servicePriceCard .workMore{justify-self:start}.servicePriceFooter{display:grid}.servicePriceFooter .serviceCall{justify-self:start}}
    `;
    document.head.appendChild(style);
  };

  injectPriceListStyle();
  const workOptions=document.getElementById('workOptions');
  if(workOptions){
    workOptions.innerHTML=works.map(([id,title,lead,body,price],i)=>`<article class="servicePriceCard" id="price-${id}"><span class="workNumber">${String(i+1).padStart(2,'0')}</span><div><img class="serviceWorkArtwork" src="assets/categories/subsections/service-${String(i+1).padStart(2,'0')}-v1.webp" width="160" height="106" alt="" loading="lazy" decoding="async"><h3>${title}</h3><b>${lead}</b><p>${body}</p></div><div class="servicePriceMeta"><div><strong>${price}</strong><span>Точную сумму мастер согласует до начала ремонта</span></div><button type="button" class="workMore" data-work-details="${id}">Подробнее</button></div></article>`).join('');
  }

  const workDialog=document.getElementById('workDialog');let detailedId='';
  const showPriceCard=id=>{
    const card=document.getElementById('price-'+id);
    if(!card)return;
    card.scrollIntoView({behavior:'smooth',block:'center'});
    card.classList.add('is-linked');
    setTimeout(()=>card.classList.remove('is-linked'),2200);
  };
  if(workDialog){
    workDialog.querySelector('.dialogClose').onclick=()=>workDialog.close();
    document.querySelectorAll('[data-work-details]').forEach(button=>button.onclick=()=>{
      detailedId=button.dataset.workDetails;const work=works.find(w=>w[0]===detailedId);
      document.getElementById('workTitle').textContent=work[1];document.getElementById('workDescription').textContent=work[3];
      document.getElementById('workDetails').innerHTML=details[detailedId].map(text=>`<li>${text}</li>`).join('');
      document.getElementById('chooseDetailedWork').textContent='Показать в прайс-листе';workDialog.showModal();
    });
    document.getElementById('chooseDetailedWork').onclick=()=>{const id=detailedId;workDialog.close();showPriceCard(id)};
  }
  window.selectedServiceWorks=()=>[];

  const hotspots=document.getElementById('bikeHotspots');
  if(hotspots)hotspots.innerHTML=parts.map(([name,x,y],i)=>`<button type="button" data-part="${i}" class="bikeHotspot" style="left:${x}%;top:${y}%" aria-label="${i+1}. ${name}">${i+1}</button>`).join('');
  const partLabel=i=>`<button type="button" data-part="${i}" aria-controls="partDialog"><span>${String(i+1).padStart(2,'0')}</span>${parts[i][0]}</button>`;
  const bikeParts=document.getElementById('bikeParts');
  if(bikeParts)bikeParts.innerHTML=`<div class="bikePartsSide bikePartsLeft">${[0,11,12,7,8,9,10].map(partLabel).join('')}</div><div class="bikePartsSide bikePartsRight">${[1,2,3,4,5,6].map(partLabel).join('')}</div>`;
  const partButtons=[...document.querySelectorAll('[data-part]')];
  const highlightPart=id=>partButtons.forEach(button=>button.classList.toggle('is-highlighted',button.dataset.part===id));
  partButtons.forEach(button=>{
    button.addEventListener('pointerenter',()=>highlightPart(button.dataset.part));
    button.addEventListener('pointerleave',()=>highlightPart(null));
    button.addEventListener('focus',()=>highlightPart(button.dataset.part));
    button.addEventListener('blur',()=>highlightPart(null));
  });
  const dialog=document.getElementById('partDialog');let current=0;
  document.querySelectorAll('[data-part]').forEach(button=>button.onclick=()=>{current=Number(button.dataset.part);const [name,,,description,symptoms]=parts[current];document.getElementById('partTitle').textContent=name;document.getElementById('partDescription').textContent=description;document.getElementById('partSymptoms').textContent=symptoms;dialog.showModal()});
  if(dialog){
    dialog.querySelector('.dialogClose').onclick=()=>dialog.close();
    document.getElementById('partRequest').onclick=()=>{const part=parts[current];dialog.close();showPriceCard(part[5])};
  }
})();