// Parameters come from the currently selected live catalog rows.
const extraFilterState=new Map();
let extraFilterContext='';
const EXTRA_SPEC_DEFS=[
  ['brake','Тормоза',/^(тип тормоза|тип тормозов|тормоза)$/i],
  ['speeds','Количество скоростей',/^(количество скоростей|скорости)$/i],
  ['material','Материал',/^(материал|материал рамы|материал ботинка|материал древка)$/i],
  ['suspension','Амортизация',/^(амортизация|тип вилки)$/i],
  ['size','Размер',/^(размер|размер коньков|размер обуви)$/i],
  ['load','Максимальная нагрузка',/^максимальная нагрузка$/i],
  ['length','Длина',/^(длина|длина спицы)$/i],
  ['diameter','Диаметр',/^(диаметр подседельного штыря|толщина оси|размер обода)$/i]
];
function extraSpecValue(product,definition){
  const entry=catalogSpecEntries(product.specs||{}).find(([name,value])=>definition[2].test(String(name).trim())&&typeof value!=='object'&&String(value).trim());
  return entry?String(entry[1]).trim():'';
}
function configureExtraFilters(list,department){
  const box=document.getElementById('extraSpecFilters');if(!box)return;
  const context=department+'|'+selectedSubcategory;
  if(context!==extraFilterContext){extraFilterState.clear();extraFilterContext=context;document.getElementById('riderHeight').value='';document.getElementById('riderFitHint').textContent='Для горных велосипедов подскажем ориентир по размеру рамы. Точную посадку проверяют на примерке.';}
  box.replaceChildren();
  const used=new Set([facet1,facet2].filter(el=>el&&!el.hidden).map(el=>el.dataset.key));
  for(const def of EXTRA_SPEC_DEFS){
    if(!department||used.has(def[0]))continue;
    const values=sortFacetValues([...new Set(list.map(p=>extraSpecValue(p,def)).filter(v=>v&&v.length<=70))]);
    if(values.length<2||values.length>40){extraFilterState.delete(def[0]);continue;}
    const label=document.createElement('label');label.className='filterField';
    const caption=document.createElement('span');caption.textContent=def[1];
    const select=document.createElement('select');select.setAttribute('aria-label',def[1]);select.dataset.spec=def[0];
    select.add(new Option('Любой вариант',''));
    values.forEach(value=>select.add(new Option(value,value)));
    const previous=extraFilterState.get(def[0]);if(previous&&values.includes(previous))select.value=previous;else extraFilterState.delete(def[0]);
    select.onchange=()=>{if(select.value)extraFilterState.set(def[0],select.value);else extraFilterState.delete(def[0]);apply();};
    label.append(caption,select);box.append(label);
  }
  for(const [index,select] of [facet1,facet2].entries()){
    if(!select)continue;
    let label=select.closest('.filterField');
    if(!label){label=document.createElement('label');label.className='filterField';select.before(label);const caption=document.createElement('span');label.append(caption,select);}
    label.hidden=select.hidden;
    label.querySelector('span').textContent=FACET_SCHEMAS[department]?.[index]?.[1]||'Параметр';
    select.setAttribute('aria-label',label.querySelector('span').textContent);
  }
  document.getElementById('parameterSection').hidden=!box.children.length&&dynamicFilters.hidden;
  document.getElementById('riderFit').hidden=department!=='bicycle';
}
function applyExtraFilters(list){return list.filter(p=>[...extraFilterState].every(([key,value])=>extraSpecValue(p,EXTRA_SPEC_DEFS.find(d=>d[0]===key))===value));}
function resetExtraFilters(){extraFilterState.clear();extraFilterContext='';const input=document.getElementById('riderHeight');if(input)input.value='';}
function mountainFrameHint(height){
  if(!Number.isFinite(height)||height<140||height>205)return null;
  if(height<155)return ['XS','13–14″'];
  if(height<165)return ['S','15–16″'];
  if(height<175)return ['M','17–18″'];
  if(height<185)return ['L','19–20″'];
  if(height<195)return ['XL','21–22″'];
  return ['XXL','23–24″'];
}
document.addEventListener('DOMContentLoaded',()=>{
  document.getElementById('riderFitButton')?.addEventListener('click',()=>{
    const height=Number(document.getElementById('riderHeight').value),hint=document.getElementById('riderFitHint');
    if(!height||height<80||height>220){hint.textContent='Введите рост от 80 до 220 см.';return;}
    const frame=mountainFrameHint(height);
    if(!frame||/детск|малыш|беговел|bmx|шоссе|складн/i.test(selectedSubcategory)){hint.textContent='Для этого типа велосипеда посадку подбирают по геометрии модели. Используйте размер из характеристик и уточните посадку в магазине.';return;}
    hint.textContent=`Ориентир для горного велосипеда: ${frame[0]} / ${frame[1]}. Выберите подходящий размер рамы выше, если он указан в каталоге. У разных брендов посадка отличается.`;
  });
});
