(()=>{
  const dialog=document.querySelector('#customerRegistration');
  if(!dialog)return;
  const form=dialog.querySelector('#customerRegistrationForm');
  const msg=dialog.querySelector('#customerRegistrationMsg');
  const close=()=>{if(dialog.open)dialog.close();const url=new URL(location.href);url.searchParams.delete('register');history.replaceState({},'',url.pathname+url.search+url.hash)};
  dialog.querySelectorAll('[data-register-close]').forEach(button=>button.addEventListener('click',close));
  document.querySelectorAll('[data-register-open]').forEach(link=>link.addEventListener('click',event=>{event.preventDefault();if(!dialog.open)dialog.showModal()}));
  dialog.addEventListener('cancel',event=>{event.preventDefault();close()});
  dialog.addEventListener('click',event=>{if(event.target===dialog)close()});
  const date=form.querySelector('[name="birth_date"]');date.max=new Date().toISOString().slice(0,10);
  async function token(){const response=await fetch('api/customer.php?action=me',{cache:'no-store'});const json=await response.json();if(!response.ok||!json.csrf)throw new Error('unavailable');return json.csrf}
  form.addEventListener('submit',async event=>{
    event.preventDefault();msg.textContent='';const button=form.querySelector('button[type="submit"]');button.disabled=true;button.textContent='Сохраняем…';
    try{
      const values=Object.fromEntries(new FormData(form));values.consent=form.elements.consent.checked;
      const response=await fetch('api/customer.php?action=qr_register',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':await token()},body:JSON.stringify(values)});const json=await response.json();
      if(!response.ok||!json.ok)throw new Error(json.error||'server_error');
      dialog.querySelector('.customerRegisterStart').hidden=true;dialog.querySelector('.customerRegisterSuccess').hidden=false;
    }catch(error){msg.textContent=error.message==='rate_limited'?'Слишком много попыток. Попробуйте немного позже.':error.message==='invalid_input'?'Проверьте заполнение всех полей.':'Не удалось сохранить анкету. Попробуйте ещё раз.'}
    finally{button.disabled=false;button.textContent='Зарегистрироваться'}
  });
  if(new URLSearchParams(location.search).get('register')==='qr')requestAnimationFrame(()=>dialog.showModal());
})();
