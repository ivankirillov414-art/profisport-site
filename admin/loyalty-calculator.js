(()=>{
'use strict';
const $=s=>document.querySelector(s);
let csrf='',canEdit=false,activeConfig={},savedDraft={},categoryOptions=[],previewProducts=[];
const state={groups:[],excluded:new Set()};
const fmt=n=>Number(n||0).toLocaleString('ru-RU');
const money=n=>Number(n||0).toLocaleString('ru-RU',{maximumFractionDigits:2})+' ₽';
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const bool=id=>!!$('#'+id)?.checked;
const num=id=>{const n=Number($('#'+id)?.value);return Number.isFinite(n)?n:0};
function slug(s){return String(s||'').toLowerCase().trim().replace(/[^a-z0-9а-яё_-]+/gi,'-').replace(/^-+|-+$/g,'').slice(0,70)}
function syncPair(rangeId,numberId,scale=1){
  const r=$('#'+rangeId),n=$('#'+numberId);if(!r||!n)return;
  r.addEventListener('input',()=>{n.value=String(Number(r.value)/scale);update()});
  n.addEventListener('input',()=>{let v=Number(n.value);if(!Number.isFinite(v))v=0;v=Math.max(Number(r.min)/scale,Math.min(Number(r.max)/scale,v));n.value=String(v);r.value=String(Math.round(v*scale));update()});
}
function currentConfig(){
  return {
    enabled:bool('programEnabled'),
    discounts_enabled:state.groups.some(g=>Number(g.percent_bp)>0&&g.categories.length>0),
    discount_stack_rule:$('#discountStackRule').value,
    earn_basis:$('#earnBasis').value,
    discount_groups:state.groups.map(g=>({key:g.key||slug(g.name),name:g.name,percent_bp:Math.round(Number(g.percent_bp)||0),categories:[...g.categories]})),
    earn_enabled:bool('earnEnabled'),redeem_enabled:bool('redeemEnabled'),expiration_enabled:bool('expirationEnabled'),review_bonus_enabled:bool('reviewBonusEnabled'),category_exclusions_enabled:bool('categoryExclusionsEnabled'),
    earn_percent_bp:Math.round(num('earnPercentNumber')*100),max_redeem_percent_bp:Math.round(num('redeemPercentNumber')*100),point_value_kopeks:Math.round(num('pointValueNumber')*100),
    expiration_days:Math.round(num('expirationDaysNumber')),min_order_rub:Math.round(num('minOrderNumber')),review_bonus:Math.round(num('reviewBonusNumber')),
    excluded_category_prefixes:[...state.excluded]
  };
}
function configured(c){
  const bonus=c.earn_enabled||c.redeem_enabled||c.review_bonus_enabled;
  const discounts=c.discounts_enabled;
  if(!(bonus||discounts))return false;
  if(bonus&&c.point_value_kopeks<1)return false;
  if(c.redeem_enabled&&(c.max_redeem_percent_bp<0||c.max_redeem_percent_bp>10000))return false;
  if(c.expiration_enabled&&c.expiration_days<1)return false;
  return true;
}
function discountFor(path,c=currentConfig()){
  const group=(c.discount_groups||[]).find(g=>(g.categories||[]).includes(path));
  return group?{bp:Number(group.percent_bp||0),name:group.name}:{bp:0,name:null};
}
function renderPreview(){
  const c=currentConfig();
  $('#productPreview').innerHTML=previewProducts.length?previewProducts.map(p=>{
    const d=discountFor(String(p.category_path||''),c),base=Number(p.price_rub||0),price=Math.max(0,Math.round(base*(10000-d.bp)/10000));
    return '<article class="previewProduct"><div><b>'+esc(p.name)+'</b><small>'+esc(p.category_path||'Без категории')+'</small></div><div class="previewPrice">'+(d.bp?'<del>'+money(base)+'</del><strong>'+money(price)+'</strong><span>−'+(d.bp/100).toLocaleString('ru-RU')+'% · '+esc(d.name)+'</span>':'<strong>'+money(base)+'</strong><span>Без скидки</span>')+'</div></article>';
  }).join(''):'<div class="empty">В актуальном каталоге нет товаров для предпросмотра.</div>';
}
function groupCard(g,index){
  const selected=g.categories.length;
  return '<article class="discountGroup" data-group-index="'+index+'"><div class="groupHead"><label>Название группы<input data-group-name value="'+esc(g.name)+'" placeholder="Например: Аксессуары"></label><button type="button" data-remove-group class="iconButton" aria-label="Удалить группу">×</button></div><div class="groupDiscount"><label>Скидка <b data-group-percent-out>'+(Number(g.percent_bp||0)/100).toLocaleString('ru-RU')+'%</b></label><input data-group-percent type="range" min="0" max="5000" step="100" value="'+Number(g.percent_bp||0)+'"></div><div class="groupMeta"><span>Категорий: <b>'+selected+'</b></span><span>'+esc(g.key||'новая группа')+'</span></div><div class="selectedGroupCategories">'+(selected?g.categories.map(x=>'<span>'+esc(x)+'</span>').join(''):'<span class="emptyTag">Категории не выбраны</span>')+'</div><details data-category-details><summary>Настроить состав группы</summary><input data-group-category-search class="categorySearch" placeholder="Найти категорию"><div data-group-category-list class="groupCategoryList"></div></details></article>';
}
function renderGroups(){
  $('#discountGroups').innerHTML=state.groups.length?state.groups.map(groupCard).join(''):'<div class="empty groupEmpty">Скидочных групп пока нет. Добавьте группу и отметьте категории из текущей базы 1С.</div>';
  document.querySelectorAll('.discountGroup').forEach(card=>{
    const index=Number(card.dataset.groupIndex),g=state.groups[index];
    card.querySelector('[data-group-name]').addEventListener('input',e=>{g.name=e.target.value;g.key=g.key||slug(g.name)||('group-'+(index+1));update(false)});
    card.querySelector('[data-group-percent]').addEventListener('input',e=>{g.percent_bp=Math.round(Number(e.target.value)||0);card.querySelector('[data-group-percent-out]').textContent=(g.percent_bp/100).toLocaleString('ru-RU')+'%';update(false)});
    card.querySelector('[data-remove-group]').onclick=()=>{state.groups.splice(index,1);renderGroups();update(false)};
    const details=card.querySelector('[data-category-details]'),search=card.querySelector('[data-group-category-search]');
    details.addEventListener('toggle',()=>{if(details.open)renderGroupCategories(card,index,'')});
    search.addEventListener('input',()=>renderGroupCategories(card,index,search.value));
  });
}
function renderGroupCategories(card,index,query){
  const g=state.groups[index],q=String(query||'').trim().toLowerCase();
  const list=categoryOptions.filter(x=>!q||String(x.path).toLowerCase().includes(q)).slice(0,q?1000:250);
  const box=card.querySelector('[data-group-category-list]');
  box.innerHTML=list.map(x=>'<label class="categoryRow"><input type="checkbox" value="'+esc(x.path)+'" '+(g.categories.includes(x.path)?'checked':'')+'><span>'+esc(x.path)+'</span><small>'+fmt(x.count)+'</small></label>').join('')||'<div class="empty">Ничего не найдено.</div>';
  box.querySelectorAll('input').forEach(input=>input.onchange=()=>{
    if(input.checked&&!g.categories.includes(input.value))g.categories.push(input.value);
    if(!input.checked)g.categories=g.categories.filter(x=>x!==input.value);
    renderGroups();update(false);
    const next=document.querySelector('[data-group-index="'+index+'"] [data-category-details]');if(next)next.open=true;
  });
}
function renderBonusCategories(){
  const q=String($('#categorySearch')?.value||'').trim().toLowerCase(),rows=categoryOptions.filter(x=>!q||String(x.path).toLowerCase().includes(q)).slice(0,q?1000:250);
  $('#categoryList').innerHTML=rows.map(x=>'<label class="categoryChip"><input type="checkbox" value="'+esc(x.path)+'" '+(state.excluded.has(x.path)?'checked':'')+'><b>'+esc(x.path)+'</b><small>'+fmt(x.count)+'</small></label>').join('');
  $('#categoryList').querySelectorAll('input').forEach(input=>input.onchange=()=>{input.checked?state.excluded.add(input.value):state.excluded.delete(input.value);renderBonusCategories();update(false)});
  $('#selectedCategories').innerHTML=[...state.excluded].map(x=>'<span class="selectedTag">'+esc(x)+'</span>').join('');
}
function renderEconomics(){
  const c=currentConfig(),order=Math.round(num('scenarioOrder')),eligible=Math.round(order*num('scenarioEligibleShare')/100),balance=Math.round(num('scenarioBalance')),point=Math.max(.01,c.point_value_kopeks/100);
  const earned=c.earn_enabled&&eligible>=c.min_order_rub?Math.floor((eligible*(c.earn_percent_bp/10000))/point):0;
  const maxDiscount=Math.min(order*(c.max_redeem_percent_bp/10000),balance*point,order),spend=c.redeem_enabled?Math.floor(maxDiscount/point):0,discount=spend*point;
  $('#scenarioOrderOut').textContent=money(order);$('#scenarioEligibleOut').textContent=Math.round(num('scenarioEligibleShare'))+'%';$('#scenarioBalanceOut').textContent=fmt(balance)+' бонусов';
  $('#earnedPoints').textContent=fmt(earned)+' бонусов';$('#maxSpendPoints').textContent=fmt(spend)+' бонусов';$('#discountRub').textContent=money(discount);$('#payableRub').textContent=money(order-discount);
}
function renderReadiness(){
  const c=currentConfig(),validGroups=c.discount_groups.filter(g=>g.name.trim()&&g.percent_bp>0&&g.categories.length>0).length;
  $('#readyGroups').innerHTML=c.discounts_enabled?'<span class="readyYes">'+validGroups+' настроено</span>':'<span class="readyYes">Не используются</span>';
  $('#readyStack').innerHTML=c.discount_stack_rule?'<span class="readyYes">Выбрано</span>':'<span class="readyNo">Не выбрано</span>';
  $('#readyBasis').innerHTML=c.earn_basis?'<span class="readyYes">Выбрано</span>':'<span class="readyNo">Не выбрано</span>';
  $('#readyConfig').innerHTML=configured(c)?'<span class="readyYes">Готова</span>':'<span class="readyNo">Нет активных механик</span>';
}
function renderChangeSummary(){
  const c=currentConfig(),groups=c.discount_groups.filter(g=>g.percent_bp>0&&g.categories.length),active=activeConfig||{};
  const changed=JSON.stringify(c)!==JSON.stringify({...c,...active,discount_groups:active.discount_groups||[]});
  $('#changeSummary').innerHTML='<b>'+(changed?'Черновик отличается от опубликованной версии':'Черновик совпадает с опубликованной версией')+'</b><span>Система после публикации: '+(c.enabled?'включена':'выключена')+' · скидочных групп: '+groups.length+' · начисление бонусов: '+(c.earn_enabled?(c.earn_percent_bp/100).toLocaleString('ru-RU')+'%':'выкл.')+' · списание: '+(c.redeem_enabled?'до '+(c.max_redeem_percent_bp/100).toLocaleString('ru-RU')+'%':'выкл.')+'</span>';
}
function update(rerender=true){
  const c=currentConfig();
  $('#categoryArea').hidden=!c.category_exclusions_enabled;
  for(const [id,on] of [['earnControls',c.earn_enabled],['redeemControls',c.redeem_enabled],['expirationControls',c.expiration_enabled],['reviewControls',c.review_bonus_enabled]])$('#'+id)?.classList.toggle('disabled',!on);
  if(rerender)renderBonusCategories();
  renderPreview();renderEconomics();renderReadiness();renderChangeSummary();
}
function applyConfig(input){
  const d={enabled:false,discounts_enabled:false,discount_stack_rule:'max',earn_basis:'after_discounts',discount_groups:[],earn_enabled:false,redeem_enabled:false,expiration_enabled:false,review_bonus_enabled:false,category_exclusions_enabled:false,earn_percent_bp:500,max_redeem_percent_bp:3000,point_value_kopeks:100,expiration_days:365,min_order_rub:0,review_bonus:50,excluded_category_prefixes:[]};
  const c={...d,...(input||{})};
  $('#programEnabled').checked=!!c.enabled;$('#discountStackRule').value=c.discount_stack_rule;$('#earnBasis').value=c.earn_basis;
  $('#earnEnabled').checked=!!c.earn_enabled;$('#redeemEnabled').checked=!!c.redeem_enabled;$('#expirationEnabled').checked=!!c.expiration_enabled;$('#reviewBonusEnabled').checked=!!c.review_bonus_enabled;$('#categoryExclusionsEnabled').checked=!!c.category_exclusions_enabled;
  const pairs=[['earnPercentRange','earnPercentNumber',(c.earn_percent_bp??500)/100,100],['redeemPercentRange','redeemPercentNumber',(c.max_redeem_percent_bp??3000)/100,100],['pointValueRange','pointValueNumber',(c.point_value_kopeks??100)/100,100],['expirationDaysRange','expirationDaysNumber',c.expiration_days??365,1],['minOrderRange','minOrderNumber',c.min_order_rub??0,1],['reviewBonusRange','reviewBonusNumber',c.review_bonus??50,1]];
  pairs.forEach(([r,n,v,scale])=>{$('#'+n).value=String(v);$('#'+r).value=String(Math.round(Number(v)*Number(scale)))});
  state.groups=(Array.isArray(c.discount_groups)?c.discount_groups:[]).map((g,i)=>({key:String(g.key||('group-'+(i+1))),name:String(g.name||''),percent_bp:Number(g.percent_bp||0),categories:Array.isArray(g.categories)?[...g.categories]:[]}));
  state.excluded=new Set(Array.isArray(c.excluded_category_prefixes)?c.excluded_category_prefixes:[]);
  renderGroups();renderBonusCategories();update(false);
}
async function load(){
  const r=await fetch('../api/loyalty-admin.php',{cache:'no-store'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'load_failed');
  csrf=j.csrf||'';canEdit=!!j.can_edit;activeConfig=j.program?.config||{};savedDraft=j.draft||activeConfig;categoryOptions=Array.isArray(j.category_options)?j.category_options:[];previewProducts=Array.isArray(j.preview_products)?j.preview_products:[];
  $('#programState').classList.toggle('live',!!j.program?.enabled);
  $('#programState').innerHTML='<div><b>Опубликованная система '+(j.program?.enabled?'работает':'выключена')+'</b><p>Все изменения ниже остаются черновиком до ввода пароля и нажатия «Применить изменения».</p></div><span class="statePill">'+(j.program?.activation_at?'Активна с '+esc(j.program.activation_at):'Без активного запуска')+'</span>';
  $('#stats').innerHTML='<div class="stat"><span>Бонусов на балансах</span><b>'+fmt(j.stats?.customer_balance)+'</b></div><div class="stat"><span>Персональных скидок</span><b>'+fmt(j.stats?.customers_with_personal_discount)+'</b></div><div class="stat"><span>Опубликованных версий</span><b>'+fmt(j.stats?.versions)+'</b></div>';
  applyConfig(savedDraft);
  for(const id of ['saveDraft','publishChanges','resetDraft'])$('#'+id).disabled=!canEdit;
}
async function saveDraft(){
  const btn=$('#saveDraft'),msg=$('#saveMsg');btn.disabled=true;msg.textContent='Сохраняю черновик…';
  try{const r=await fetch('../api/loyalty-admin.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'save_draft',config:currentConfig()})}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'save_failed');savedDraft=j.draft;msg.textContent='Черновик сохранён. На сайт не опубликован.'}
  catch(e){msg.textContent='Не удалось сохранить черновик.'}finally{btn.disabled=!canEdit}
}
async function publish(){
  const password=$('#publishPassword').value,msg=$('#saveMsg'),btn=$('#publishChanges');if(!password){msg.textContent='Введите текущий пароль администратора.';return}
  if(!window.confirm('Применить эту версию правил к магазину? Цены, персональные скидки и бонусная система начнут работать по опубликованной конфигурации.'))return;
  btn.disabled=true;msg.textContent='Публикую правила…';
  try{const r=await fetch('../api/loyalty-admin.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'publish',config:currentConfig(),current_password:password})}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'publish_failed');activeConfig=j.program.config;savedDraft=j.draft;$('#publishPassword').value='';msg.textContent='Изменения опубликованы.';await load()}
  catch(e){msg.textContent=e.message==='current_password_invalid'?'Неверный текущий пароль.':e.message==='loyalty_not_configured'?'Включённая система должна содержать хотя бы одну рабочую механику.':'Не удалось применить изменения.';btn.disabled=false}
}
function init(){
  [['earnPercentRange','earnPercentNumber',100],['redeemPercentRange','redeemPercentNumber',100],['pointValueRange','pointValueNumber',100],['expirationDaysRange','expirationDaysNumber',1],['minOrderRange','minOrderNumber',1],['reviewBonusRange','reviewBonusNumber',1]].forEach(x=>syncPair(...x));
  ['programEnabled','earnEnabled','redeemEnabled','expirationEnabled','reviewBonusEnabled','categoryExclusionsEnabled','discountStackRule','earnBasis'].forEach(id=>$('#'+id).addEventListener('change',()=>update()));
  ['scenarioOrder','scenarioEligibleShare','scenarioBalance'].forEach(id=>$('#'+id).addEventListener('input',()=>update(false)));
  $('#categorySearch').addEventListener('input',renderBonusCategories);
  $('#addDiscountGroup').onclick=()=>{const n=state.groups.length+1;state.groups.push({key:'group-'+n,name:'Новая группа '+n,percent_bp:500,categories:[]});renderGroups();update(false)};
  $('#saveDraft').onclick=saveDraft;$('#publishChanges').onclick=publish;$('#resetDraft').onclick=()=>{applyConfig(activeConfig);$('#saveMsg').textContent='Возвращена опубликованная версия в редактор.'};
  load().catch(()=>{$('#programState').innerHTML='<div><b>Не удалось загрузить Центр лояльности</b><p>Обновите страницу и попробуйте снова.</p></div>'});
}
init();
})();