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
  document.getElementById('workOptions').innerHTML=works.map(([id,title,lead,body],i)=>`<label class="workOption"><input type="checkbox" value="${id}"><span class="workNumber">0${i+1}</span><h3>${title}</h3><b>${lead}</b><p>${body}</p><span class="workChoose">Выбрать работу</span></label>`).join('');
  const refresh=()=>{
    const titles=works.filter(w=>selected.has(w[0])).map(w=>w[1]);
    document.getElementById('selectedWorkCount').textContent=titles.length?`Выбрано работ: ${titles.length}`:'Работы пока не выбраны';
    document.getElementById('bookingSelection').textContent=titles.length?'Выбрано: '+titles.join(', ')+'.':'Можно отправить заявку без выбора работ.';
    document.querySelectorAll('.workOption').forEach(label=>{const checked=selected.has(label.querySelector('input').value);label.classList.toggle('selected',checked);label.querySelector('.workChoose').textContent=checked?'Выбрано ✓':'Выбрать работу';label.querySelector('input').checked=checked});
    form.elements.problem.required=!titles.length;
    if(titles.length)form.elements.type.value=titles.length===1&&selected.has('assembly')?'Сборка велосипеда':titles.length===1&&selected.has('diagnostics')?'Диагностика':'Ремонт велосипеда';
  };
  document.querySelectorAll('.workOption input').forEach(input=>input.onchange=()=>{input.checked?selected.add(input.value):selected.delete(input.value);refresh()});
  window.selectedServiceWorks=()=>works.filter(w=>selected.has(w[0])).map(w=>w[1]);
  document.getElementById('bikeHotspots').innerHTML=parts.map(([name,x,y],i)=>`<button type="button" data-part="${i}" class="bikeHotspot" style="left:${x}%;top:${y}%" aria-label="${i+1}. ${name}">${i+1}</button>`).join('');
  document.getElementById('bikeParts').innerHTML=parts.map(([name],i)=>`<button type="button" data-part="${i}"><span>${String(i+1).padStart(2,'0')}</span>${name}</button>`).join('');
  const dialog=document.getElementById('partDialog');let current=0;
  document.querySelectorAll('[data-part]').forEach(button=>button.onclick=()=>{current=Number(button.dataset.part);const [name,,,description,symptoms]=parts[current];document.getElementById('partTitle').textContent=name;document.getElementById('partDescription').textContent=description;document.getElementById('partSymptoms').textContent=symptoms;dialog.showModal()});
  dialog.querySelector('.dialogClose').onclick=()=>dialog.close();
  document.getElementById('partRequest').onclick=()=>{const part=parts[current];selected.add(part[5]);refresh();const line='Проверить узел: '+part[0]+'.';if(!form.elements.problem.value.includes(line))form.elements.problem.value=(form.elements.problem.value+'\n'+line).trim().slice(0,3500);dialog.close();document.getElementById('booking').scrollIntoView({behavior:'smooth'});form.elements.name.focus({preventScroll:true})};
})();
