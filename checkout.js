(()=>{
const form=document.getElementById('order'),summary=document.getElementById('orderSummary'),button=document.getElementById('submitOrder'),message=document.getElementById('orderMsg'),loyaltyBox=document.getElementById('loyaltyCheckout');
const rub=n=>Number(n||0).toLocaleString('ru-RU',{minimumFractionDigits:Number(n)%1?2:0,maximumFractionDigits:2})+' ₽',esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let cart=[],validCart=[],pending=null,accountData=null,cartTotal=0,bonusSpend=0;
try{const saved=JSON.parse(localStorage.getItem('ps-cart')||'[]');if(Array.isArray(saved))cart=saved;pending=JSON.parse(sessionStorage.getItem('ps-order-pending')||'null')}catch(e){}
function addressState(){const delivery=form.elements.delivery.value,needed=delivery==='orenburg_delivery',pickup=delivery==='pickup';document.getElementById('addressLabel').hidden=!needed;document.getElementById('pickupStoreLabel').hidden=!pickup;form.elements.address.required=needed;form.elements.pickup_store.required=pickup}
form.elements.delivery.onchange=addressState;addressState();form.elements.phone.oninput=()=>form.elements.phone.setCustomValidity('');
async function request(url,opts={}){const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);try{const r=await fetch(url,{...opts,signal:controller.signal});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'server_error');return j}finally{clearTimeout(timer)}}
function loyaltyNumbers(points){
  const cfg=accountData?.loyalty_program?.config||{},value=Math.max(1,Number(cfg.point_value_kopeks||0)),discount=points*value/100;
  return {discount,payable:Math.max(0,cartTotal-discount)};
}
function renderLoyalty(){
  if(!loyaltyBox)return;
  const customer=accountData?.customer,program=accountData?.loyalty_program,cfg=program?.config||{};
  if(!customer||!program?.enabled||!cfg.redeem_enabled||cartTotal<=0){bonusSpend=0;loyaltyBox.hidden=true;loyaltyBox.innerHTML='';return}
  const balance=Math.max(0,Number(customer.bonus_balance||0)),point=Math.max(1,Number(cfg.point_value_kopeks||0));
  const maxShareKopeks=Math.floor(cartTotal*100*Math.max(0,Number(cfg.max_redeem_percent_bp||0))/10000);
  const maxPoints=Math.max(0,Math.floor(Math.min(maxShareKopeks,balance*point,cartTotal*100)/point));
  bonusSpend=Math.min(bonusSpend,maxPoints);
  loyaltyBox.hidden=false;
  loyaltyBox.innerHTML='<div class="checkoutLoyaltyHead"><div><b>Бонусы ПрофиСпорт</b><span>Доступно: '+balance.toLocaleString('ru-RU')+' бонусов</span></div><strong id="loyaltyPayable">'+rub(loyaltyNumbers(bonusSpend).payable)+'</strong></div>'+
    (maxPoints>0?'<label class="checkoutLoyaltyControl">Списать бонусы<input id="bonusSpend" type="number" inputmode="numeric" min="0" max="'+maxPoints+'" step="1" value="'+bonusSpend+'"><small>Можно использовать до '+maxPoints.toLocaleString('ru-RU')+' бонусов. Сумма к оплате пересчитывается сразу.</small></label>':'<p class="checkoutLoyaltyEmpty">На этот заказ бонусы сейчас использовать нельзя.</p>')+
    '<div id="loyaltyDiscount" class="checkoutLoyaltyFoot">'+(bonusSpend?'Скидка бонусами: <b>'+rub(loyaltyNumbers(bonusSpend).discount)+'</b>':'Бонусы не выбраны')+'</div>';
  const input=document.getElementById('bonusSpend');
  if(input)input.oninput=()=>{let v=Math.round(Number(input.value)||0);v=Math.max(0,Math.min(maxPoints,v));bonusSpend=v;input.value=String(v);const n=loyaltyNumbers(v);document.getElementById('loyaltyPayable').textContent=rub(n.payable);document.getElementById('loyaltyDiscount').innerHTML=v?'Скидка бонусами: <b>'+rub(n.discount)+'</b>':'Бонусы не выбраны'};
}
request('api/customer.php?action=me',{cache:'no-store'}).then(j=>{accountData=j;if(j.customer){for(const key of ['name','email','phone'])if(!form.elements[key].value)form.elements[key].value=j.customer[key]||'';if(j.customer.preferred_store&&form.elements.pickup_store?.querySelector('option[value="'+CSS.escape(j.customer.preferred_store)+'"]'))form.elements.pickup_store.value=j.customer.preferred_store}renderLoyalty()}).catch(()=>{});
(async()=>{try{
const ids=[...new Set(cart.map(String))],all=[];
for(let i=0;i<ids.length;i+=8){const batch=await Promise.all(ids.slice(i,i+8).map(loadProduct));all.push(...batch.filter(Boolean))}
const byId=new Map(all.map(p=>[String(p.id),p])),groups=new Map;cart.forEach(id=>groups.set(String(id),(groups.get(String(id))||0)+1));let total=0,invalid=false;
const rows=[...groups].map(([id,qty])=>{const p=byId.get(id);const bad=!p||p.stockCode==='out'||p.price<=0||(p.stockQty!==null&&qty>p.stockQty);if(bad)invalid=true;else{for(let i=0;i<qty;i++)validCart.push(p.id);total+=p.price*qty}return `<div class="checkoutRow"><span>${esc(p?.name||'Недоступный товар')} × ${qty}${bad?'<br><strong>Товар недоступен, цена не задана или превышен остаток.</strong>':''}</span><span>${p?rub(p.price*qty):''} <button type="button" data-remove="${esc(id)}">Убрать</button></span></div>`}).join('');
cartTotal=total;summary.innerHTML=rows?`${rows}<div class="checkoutTotal">Сумма доступных товаров: <b>${rub(total)}</b></div>`:'<p>Корзина пуста. <a href="index.html">Перейти в каталог</a></p>';
summary.querySelectorAll('[data-remove]').forEach(b=>b.onclick=()=>{cart=cart.filter(id=>String(id)!==b.dataset.remove);localStorage.setItem('ps-cart',JSON.stringify(cart));location.reload()});
button.disabled=invalid||!validCart.length;if(invalid)message.textContent='Уберите недоступные позиции перед оформлением.';renderLoyalty();
}catch(e){summary.textContent='Не удалось загрузить корзину. Обновите страницу для повторной попытки.';button.disabled=true}})();
const errors={invalid_input:'Проверьте имя и телефон: нужен номер из 11 цифр, начинающийся с 7 или 8.',bad_email:'Проверьте адрес электронной почты.',address_required:'Укажите адрес доставки.',invalid_pickup_store:'Выберите магазин самовывоза.',out_of_stock:'Товар закончился. Обновите корзину.',insufficient_stock:'Заказанное количество превышает остаток. Обновите корзину.',price_unavailable:'Цена товара уточняется. Уберите его из заказа.',product_missing:'Один из товаров больше недоступен. Обновите корзину.',request_conflict:'Данные заказа изменились. Обновите страницу.',loyalty_login_required:'Чтобы списать бонусы, войдите в личный кабинет.',loyalty_unavailable:'Списание бонусов сейчас недоступно.',bonus_spend_exceeds_limit:'Баланс или допустимая сумма списания изменились. Обновите страницу.',invalid_bonus_spend:'Некорректная сумма бонусов.'};
form.onsubmit=async e=>{e.preventDefault();if(button.disabled||!validCart.length)return;const phone=form.elements.phone.value.trim();if(!/^[78]\d{10}$/.test(phone.replace(/\D/g,''))){form.elements.phone.setCustomValidity(errors.invalid_input);form.elements.phone.reportValidity();return}button.disabled=true;message.textContent='Отправляем заказ…';
const payload={name:form.elements.name.value.trim(),phone,email:form.elements.email.value.trim(),delivery:form.elements.delivery.value,pickup_store:form.elements.delivery.value==='pickup'?form.elements.pickup_store.value:'',address:form.elements.address.value.trim(),comment:form.elements.comment.value.trim(),items:validCart,bonus_spend:bonusSpend};const signature=JSON.stringify(payload);
if(!pending||pending.signature!==signature){pending={signature,key:Array.from(crypto.getRandomValues(new Uint8Array(32)),b=>b.toString(16).padStart(2,'0')).join('')};try{sessionStorage.setItem('ps-order-pending',JSON.stringify(pending))}catch(e){}}
try{const j=await request('api/order-create.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,request_key:pending.key})});try{localStorage.removeItem('ps-cart');sessionStorage.removeItem('ps-order-pending')}catch(e){}form.hidden=true;summary.hidden=true;loyaltyBox.hidden=true;message.textContent='';document.getElementById('done').innerHTML=`<h2>Заказ ${esc(j.order_number)} принят</h2><p>Сумма товаров: <b>${rub(j.total_rub)}</b>.</p>${Number(j.bonus_spent)>0?`<p>Списано: <b>${Number(j.bonus_spent).toLocaleString('ru-RU')} бонусов</b>.</p>`:''}<p>К оплате: <b>${rub(j.payable_rub)}</b>.</p><p>Менеджер свяжется с вами для подтверждения наличия, оплаты и получения.</p><a class="primary" href="index.html">Вернуться в магазин</a>`}catch(err){button.disabled=false;message.textContent=errors[err.message]||'Не удалось получить подтверждение. Нажмите ещё раз: повторная отправка того же заказа не создаст дубликат.'}
};
})();