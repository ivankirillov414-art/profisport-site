(()=>{
  const works=[
    ['diagnostics','Диагностика','Найдём причину неисправности','Осмотр узлов и уточнение причины шума, люфта или неполадок.'],
    ['brakes','Тормоза','Контроль и настройка торможения','Проверка колодок, ручек и механизма тормоза.'],
    ['transmission','Передачи и цепь','Чёткое переключение','Проверка переключателей, цепи и звёзд.'],
    ['wheels','Колёса и покрышки','Проколы, биение и люфты','Осмотр покрышек, камер, ободов и втулок.'],
    ['maintenance','Техническое обслуживание','Проверка основных узлов','Состояние крепежа, подшипников, тормозов и трансмиссии.'],
    ['assembly','Сборка и настройка','Подготовка велосипеда к езде','Сборка, регулировка узлов и проверка креплений.']
  ];
  const parts=[
    ['Седло и подседельный штырь',38,22,'Седло крепится к подседельному штырю. Его положение влияет на посадку и удобство педалирования.','Седло проворачивается, опускается или скрипит.','maintenance'],
    ['Руль и рулевая колонка',68,16,'Руль связан с вилкой через вынос и рулевую колонку. Подшипники позволяют поворачивать переднее колесо.','Люфт при торможении, заедание или стук при повороте.','maintenance'],
    ['Вилка',73,44,'Вилка удерживает переднее колесо. На некоторых велосипедах она также смягчает удары.','Люфт, стук, следы масла или заклинивание.','diagnostics'],
    ['Тормоза',75,57,'Тормоза замедляют вращение колёс. Конструкция бывает ободной или дисковой, с механическим либо гидравлическим приводом.','Слабое торможение, ручка проваливается или колодки постоянно трут.','brakes'],
    ['Покрышка и камера',87,81,'Покрышка обеспечивает контакт с дорогой. Воздух удерживается камерой или бескамерной системой.','Колесо теряет давление, есть порезы, трещины или вздутие.','wheels'],
    ['Обод и спицы',65,84,'Обод и спицы образуют жёсткую основу колеса и связывают его с втулкой.','Колесо бьёт в сторону, спицы ослабли или повреждены.','wheels'],
    ['Втулки',23,66,'Втулка находится в центре колеса. Подшипники обеспечивают его вращение на оси.','Колесо вращается туго, слышен хруст или ощущается боковой люфт.','wheels'],
    ['Цепь и звёзды',32,73,'Цепь передаёт усилие от педалей на заднее колесо. Размеры звёзд определяют передаточное отношение.','Цепь проскакивает, слетает или сильно изношена.','transmission'],
    ['Задний переключатель',23,82,'Переключатель перемещает цепь между задними звёздами и поддерживает её натяжение.','Передачи включаются неточно, цепь шумит или задевает соседнюю звезду.','transmission'],
    ['Каретка и шатуны',45,66,'Каретка позволяет шатунам вращаться в раме. Через шатуны усилие с педалей передаётся на передние звёзды.','Люфт, скрип или щелчки при педалировании.','maintenance'],
    ['Педали',52,75,'Педали дают опору ногам и вращаются на собственных подшипниках.','Педаль люфтит, заклинивает или повреждена.','maintenance'],
    ['Рама',54,38,'Рама соединяет основные узлы велосипеда. Её геометрия и размер определяют посадку.','Трещина, деформация или заметное повреждение соединений.','diagnostics']
  ];
  const selected=new Set();const form=document.getElementById('serviceForm');
  const groups=[['all','Все работы'],['care','Диагностика и ТО'],['brakes','Тормоза'],['transmission','Передачи и цепь'],['wheels','Колёса'],['assembly','Сборка']];
  const groupFor=id=>['diagnostics','maintenance'].includes(id)?'care':id;
  const details={
    diagnostics:['Что изменилось в работе велосипеда и когда это началось.','Какие узлы требуют дополнительной проверки.','Какие работы и запчасти могут понадобиться.'],
    brakes:['Состояние колодок и тормозной поверхности.','Работа ручек, тросов или гидравлического привода.','Необходимость регулировки или замены деталей.'],
    transmission:['Износ цепи и состояние звёзд.','Точность переключения и состояние тросов.','Совместимость деталей при необходимости замены.'],
    wheels:['Состояние покрышек и камер.','Биение обода, натяжение спиц и люфт втулок.','Подходящий способ устранения прокола или повреждения.'],
    maintenance:['Состояние креплений и основных подшипников.','Работа тормозов и трансмиссии.','Какие узлы нуждаются в очистке, смазке или замене.'],
    assembly:['Комплектность и состояние велосипеда.','Сборка и настройка основных узлов.','Посадка и проверка креплений перед эксплуатацией.']
  };
  document.getElementById('workCategories').innerHTML=groups.map(([id,title])=>`<button type="button" data-work-group="${id}" aria-pressed="${id==='all'}">${title}<span>${works.filter(w=>id==='all'||groupFor(w[0])===id).length}</span></button>`).join('');
  document.getElementById('workOptions').innerHTML=works.map(([id,title,lead,body],i)=>`<article class="workOption" data-work-category="${groupFor(id)}"><label class="workSelect"><input type="checkbox" value="${id}"><span class="workNumber">0${i+1}</span><h3>${title}</h3><b>${lead}</b><p>${body}</p><span class="workChoose">Выбрать работу</span></label><button type="button" class="workMore" data-work-details="${id}">Подробнее об услуге →</button></article>`).join('');
  document.querySelectorAll('[data-work-group]').forEach(button=>button.onclick=()=>{
    document.querySelectorAll('[data-work-group]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));
    document.querySelectorAll('.workOption').forEach(card=>card.hidden=button.dataset.workGroup!=='all'&&button.dataset.workGroup!==card.dataset.workCategory);
  });
  const refresh=()=>{
    const titles=works.filter(w=>selected.has(w[0])).map(w=>w[1]);
    document.getElementById('selectedWorkCount').textContent=titles.length?`Выбрано работ: ${titles.length}`:'Работы пока не выбраны';
    document.getElementById('bookingSelection').textContent=titles.length?'Выбрано: '+titles.join(', ')+'.':'Можно отправить заявку без выбора работ.';
    document.querySelectorAll('.workOption').forEach(label=>{const checked=selected.has(label.querySelector('input').value);label.classList.toggle('selected',checked);label.querySelector('.workChoose').textContent=checked?'Выбрано ✓':'Выбрать работу';label.querySelector('input').checked=checked});
    document.getElementById('selectedWorkChips').innerHTML=works.filter(w=>selected.has(w[0])).map(([id,title])=>`<button type="button" data-remove-work="${id}" aria-label="Убрать работу: ${title}">${title} ×</button>`).join('');
    document.querySelectorAll('[data-remove-work]').forEach(button=>button.onclick=()=>{selected.delete(button.dataset.removeWork);refresh()});
    form.elements.problem.required=!titles.length;
    if(titles.length)form.elements.type.value=titles.length===1&&selected.has('assembly')?'Сборка велосипеда':titles.length===1&&selected.has('diagnostics')?'Диагностика':'Ремонт велосипеда';
  };
  document.querySelectorAll('.workOption input').forEach(input=>input.onchange=()=>{input.checked?selected.add(input.value):selected.delete(input.value);refresh()});
  const workDialog=document.getElementById('workDialog');let detailedId='';
  workDialog.querySelector('.dialogClose').onclick=()=>workDialog.close();
  document.querySelectorAll('[data-work-details]').forEach(button=>button.onclick=()=>{
    detailedId=button.dataset.workDetails;const work=works.find(w=>w[0]===detailedId);
    document.getElementById('workTitle').textContent=work[1];document.getElementById('workDescription').textContent=work[3];
    document.getElementById('workDetails').innerHTML=details[detailedId].map(text=>`<li>${text}</li>`).join('');
    document.getElementById('chooseDetailedWork').textContent=selected.has(detailedId)?'Перейти к заявке':'Добавить в заявку';workDialog.showModal();
  });
  document.getElementById('chooseDetailedWork').onclick=()=>{selected.add(detailedId);refresh();workDialog.close();document.getElementById('booking').scrollIntoView({behavior:'smooth'})};
  window.selectedServiceWorks=()=>works.filter(w=>selected.has(w[0])).map(w=>w[1]);
  document.getElementById('bikeHotspots').innerHTML=parts.map(([name,x,y],i)=>`<button type="button" data-part="${i}" class="bikeHotspot" style="left:${x}%;top:${y}%" aria-label="${i+1}. ${name}">${i+1}</button>`).join('');
  document.getElementById('bikeParts').innerHTML=parts.map(([name],i)=>`<button type="button" data-part="${i}"><span>${String(i+1).padStart(2,'0')}</span>${name}</button>`).join('');
  const dialog=document.getElementById('partDialog');let current=0;
  document.querySelectorAll('[data-part]').forEach(button=>button.onclick=()=>{current=Number(button.dataset.part);const [name,,,description,symptoms]=parts[current];document.getElementById('partTitle').textContent=name;document.getElementById('partDescription').textContent=description;document.getElementById('partSymptoms').textContent=symptoms;dialog.showModal()});
  dialog.querySelector('.dialogClose').onclick=()=>dialog.close();
  document.getElementById('partRequest').onclick=()=>{const part=parts[current];selected.add(part[5]);refresh();const line='Проверить узел: '+part[0]+'.';if(!form.elements.problem.value.includes(line))form.elements.problem.value=(form.elements.problem.value+'\n'+line).trim().slice(0,3500);dialog.close();document.getElementById('booking').scrollIntoView({behavior:'smooth'});form.elements.name.focus({preventScroll:true})};
})();
