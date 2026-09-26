(()=>{
'use strict';
const $=s=>document.querySelector(s);
let csrf='',savedConfig=null,categoryOptions=[],canEdit=false;
const state={excluded:new Set()};
const fmt=n=>Number(n||0).toLocaleString('ru-RU');
const money=n=>fmt(Math.round(Number(n)||0))+' ₽';
const pct=bp=>(Number(bp||0)/100).toLocaleString('ru-RU',{maximumFractionDigits:2})+'%';
function bool(id){return !!$('#'+id)?.checked}
function num(id){const el=$('#'+id);const n=Number(el?.value);return Number.isFinite(n)?n:0}
function syncPair(rangeId,numberId,scale=1){
  const r=$('#'+rangeId),n=$('#'+numberId);if(!r||!n)return;
  const fromRange=()=>{n.value=(Number(r.value)/scale).toString();update()};
  const fromNumber=()=>{let v=Number(n.value);if(!Number.isFinite(v))v=0;const min=Number(r.min)/scale,max=Number(r.max)/scale;v=Math.max(min,Math.min(max,v));n.value=v.toString();r.value=String(Math.round(v*scale));update()};
  r.addEventListener('input',fromRange);n.addEventListener('input',fromNumber);
}
function currentConfig(){
  return {
    enabled:false,
    earn_enabled:bool('earnEnabled'),
    redeem_enabled:bool('redeemEnabled'),
    expiration_enabled:bool('expirationEnabled'),
    review_bonus_enabled:bool('reviewBonusEnabled'),
    category_exclusions_enabled:bool('categoryExclusionsEnabled'),
    earn_percent_bp:Math.round(num('earnPercentNumber')*100),
    max_redeem_percent_bp:Math.round(num('redeemPercentNumber')*100),
    point_value_kopeks:Math.round(num('pointValueNumber')*100),
    expiration_days:Math.round(num('expirationDaysNumber')),
    min_order_rub:Math.round(num('minOrderNumber')),
    review_bonus:Math.round(num('reviewBonusNumber')),
    excluded_category_prefixes:[...state.excluded]
  };
}
function toggleControl(groupId,enabled){
  const el=$('#'+groupId);if(el)el.classList.toggle('disabled',!enabled);
}
function configured(c){
  if(!(c.earn_enabled||c.redeem_enabled||c.review_bonus_enabled))return false;
  if(c.point_value_kopeks<1)return false;
  if(c.earn_enabled&&(c.earn_percent_bp<0||c.min_order_rub<0))return false;
  if(c.redeem_enabled&&(c.max_redeem_percent_bp<0||c.max_redeem_percent_bp>10000))return false;
  if(c.expiration_enabled&&c.expiration_days<1)return false;
  if(c.review_bonus_enabled&&c.review_bonus<0)return false;
  return true;
}
function update(){
  const c=currentConfig();
  toggleControl('earnControls',c.earn_enabled);
  toggleControl('redeemControls',c.redeem_enabled);
  toggleControl('expirationControls',c.expiration_enabled);
  toggleControl('reviewControls',c.review_bonus_enabled);
  $('#categoryArea').hidden=!c.category_exclusions_enabled;

  const order=Math.round(num('scenarioOrder'));
  const eligibleShare=num('scenarioEligibleShare')/100;
  const balance=Math.round(num('scenarioBalance'));
  const marginPct=num('scenarioMargin')/100;
  const eligible=Math.round(order*eligibleShare);
  const pointValue=Math.max(.01,c.point_value_kopeks/100);
  const earned=c.earn_enabled&&eligible>=c.min_order_rub?Math.floor((eligible*(c.earn_percent_bp/10000))/pointValue):0;
  const earnedValue=earned*pointValue;
  const maxDiscountByShare=c.redeem_enabled?order*(c.max_redeem_percent_bp/10000):0;
  const balanceValue=c.redeem_enabled?balance*pointValue:0;
  const discount=Math.min(order,maxDiscountByShare,balanceValue);
  const spendPoints=pointValue>0?Math.floor(discount/pointValue):0;
  const actualDiscount=spendPoints*pointValue;
  const payable=Math.max(0,order-actualDiscount);
  const grossMargin=order*marginPct;
  const marginAfterSpend=grossMargin-actualDiscount;
  const conservative=marginAfterSpend-earnedValue;

  $('#scenarioOrderOut').textContent=money(order);
  $('#scenarioEligibleOut').textContent=Math.round(eligibleShare*100)+'%';
  $('#scenarioBalanceOut').textContent=fmt(balance)+' бонусов';
  $('#scenarioMarginOut').textContent=Math.round(marginPct*100)+'%';
  $('#earnedPoints').textContent=fmt(earned)+' бонусов';
  $('#earnedValue').textContent=money(earnedValue);
  $('#maxSpendPoints').textContent=fmt(spendPoints)+' бонусов';
  $('#discountRub').textContent=money(actualDiscount);
  $('#payableRub').textContent=money(payable);
  $('#marginAfter').textContent=money(marginAfterSpend);
  $('#conservativeMargin').textContent=money(conservative);
  $('#conservativeMargin').parentElement.classList.toggle('warn',conservative<0);

  $('#readyPoint').innerHTML=c.point_value_kopeks>0?'<span class="readyYes">Задано</span>':'<span class="readyNo">Не задано</span>';
  $('#readyFeatures').innerHTML=(c.earn_enabled||c.redeem_enabled||c.review_bonus_enabled)?'<span class="readyYes">Выбраны</span>':'<span class="readyNo">Ни одной</span>';
  $('#readyConfig').innerHTML=configured(c)?'<span class="readyYes">Корректен</span>':'<span class="readyNo">Неполный</span>';
  renderSelected();
}
function applyConfig(c){
  const defaults={
    earn_enabled:false,redeem_enabled:false,expiration_enabled:false,review_bonus_enabled:false,category_exclusions_enabled:false,
    earn_percent_bp:500,max_redeem_percent_bp:30*100,point_value_kopeks:100,expiration_days:365,min_order_rub:0,review_bonus:50,excluded_category_prefixes:[]
  };
  c={...defaults,...(c||{})};
  $('#earnEnabled').checked=!!c.earn_enabled;$('#redeemEnabled').checked=!!c.redeem_enabled;$('#expirationEnabled').checked=!!c.expiration_enabled;$('#reviewBonusEnabled').checked=!!c.review_bonus_enabled;$('#categoryExclusionsEnabled').checked=!!c.category_exclusions_enabled;
  const pairs=[
    ['earnPercentRange','earnPercentNumber',(c.earn_percent_bp??500)/100,100],
    ['redeemPercentRange','redeemPercentNumber',(c.max_redeem_percent_bp??3000)/100,100],
    ['pointValueRange','pointValueNumber',(c.point_value_kopeks??100)/100,100],
    ['expirationDaysRange','expirationDaysNumber',c.expiration_days??365,1],
    ['minOrderRange','minOrderNumber',c.min_order_rub??0,1],
    ['reviewBonusRange','reviewBonusNumber',c.review_bonus??50,1]
  ];
  pairs.forEach(([r,n,v,scale])=>{$('#'+n).value=String(v);$('#'+r).value=String(Math.round(Number(v)*Number(scale)))});
  state.excluded=new Set(Array.isArray(c.excluded_category_prefixes)?c.excluded_category_prefixes:[]);
  renderCategories();update();
}
function renderCategories(){
  const q=String($('#categorySearch')?.value||'').trim().toLowerCase();
  const rows=categoryOptions.filter(x=>!q||String(x.name).toLowerCase().includes(q)).slice(0,80);
  $('#categoryList').innerHTML=rows.map(x=>'<label class="categoryChip"><input type="checkbox" value="'+esc(x.name)+'" '+(state.excluded.has(x.name)?'checked':'')+'><b>'+esc(x.name)+'</b><small>'+fmt(x.count)+'</small></label>').join('')||'<span class="hint">Категории не найдены.</span>';
  $('#categoryList').querySelectorAll('input').forEach(input=>input.addEventListener('change',()=>{if(input.checked)state.excluded.add(input.value);else state.excluded.delete(input.value);update()}));
}
function renderSelected(){
  const box=$('#selectedCategories');if(!box)return;
  box.innerHTML=[...state.excluded].map(x=>'<span class="selectedTag">'+esc(x)+'<button type="button" data-remove="'+esc(x)+'" aria-label="Убрать '+esc(x)+'">×</button></span>').join('');
  box.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>{state.excluded.delete(b.dataset.remove);renderCategories();update()}));
}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
async function load(){
  const r=await fetch('../api/loyalty-admin.php',{cache:'no-store'}),j=await r.json();
  if(!r.ok||!j.ok)throw new Error(j.error||'load_failed');
  csrf=j.csrf||'';canEdit=!!j.can_edit;categoryOptions=Array.isArray(j.category_options)?j.category_options:[];savedConfig=j.program?.config||{};
  $('#programState').classList.toggle('live',!!j.program?.enabled);
  $('#programState').innerHTML='<div><b>Программа '+(j.program?.enabled?'включена':'выключена')+'</b><p>'+(j.live_activation_available?'Боевой запуск доступен.':'Сейчас это безопасный конструктор: настройки сохраняются, но клиентские начисления и списания не запускаются.')+'</p></div><span class="statePill">'+(j.program?.configured?'Конфигурация собрана':'Черновик')+'</span>';
  $('#stats').innerHTML='<div class="stat"><span>Служебный баланс клиентов</span><b>'+fmt(j.stats?.customer_balance)+'</b></div><div class="stat"><span>Клиентов с балансом</span><b>'+fmt(j.stats?.customers_with_balance)+'</b></div><div class="stat"><span>Операций в ledger</span><b>'+fmt(j.stats?.transactions)+'</b></div>';
  applyConfig(savedConfig);
  $('#saveDraft').disabled=!canEdit;
  if(!canEdit)$('#saveMsg').textContent='Редактирование доступно только владельцу.';
}
async function saveDraft(){
  const btn=$('#saveDraft'),msg=$('#saveMsg');btn.disabled=true;msg.className='saveMsg';msg.textContent='Сохраняю черновик…';
  try{
    const r=await fetch('../api/loyalty-admin.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'save_draft',config:currentConfig()})}),j=await r.json();
    if(!r.ok||!j.ok)throw new Error(j.error||'save_failed');
    csrf=j.csrf||csrf;savedConfig=j.program.config;applyConfig(savedConfig);msg.className='saveMsg ok';msg.textContent='Черновик сохранён. Программа остаётся выключенной.';
  }catch(e){msg.className='saveMsg err';msg.textContent='Не удалось сохранить: '+String(e.message||e)}
  finally{btn.disabled=!canEdit}
}
function init(){
  [['earnPercentRange','earnPercentNumber',100],['redeemPercentRange','redeemPercentNumber',100],['pointValueRange','pointValueNumber',100],['expirationDaysRange','expirationDaysNumber',1],['minOrderRange','minOrderNumber',1],['reviewBonusRange','reviewBonusNumber',1]].forEach(x=>syncPair(...x));
  ['earnEnabled','redeemEnabled','expirationEnabled','reviewBonusEnabled','categoryExclusionsEnabled'].forEach(id=>$('#'+id).addEventListener('change',update));
  ['scenarioOrder','scenarioEligibleShare','scenarioBalance','scenarioMargin'].forEach(id=>$('#'+id).addEventListener('input',update));
  $('#categorySearch').addEventListener('input',renderCategories);
  $('#addCustomCategory').addEventListener('click',()=>{const input=$('#customCategory'),v=input.value.trim();if(v){state.excluded.add(v.slice(0,250));input.value='';renderCategories();update()}});
  $('#saveDraft').addEventListener('click',saveDraft);
  load().catch(()=>{$('#programState').innerHTML='<div><b>Не удалось загрузить калькулятор</b><p>Обновите страницу и попробуйте снова.</p></div>'});
}
init();
})();