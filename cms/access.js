'use strict';
window.CMSAccess={mount({api,getState,report,demo=false}){
 const labels={viewer:'Просмотр',editor:'Редактирование',publisher:'Редактирование и публикация',owner:'Владелец'};
 const node=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
 const button=(text,fn)=>{const b=node('button',text);b.type='button';b.onclick=fn;return b;};
 const actions=document.querySelector('#save').parentElement;
 const usersButton=button('Пользователи',()=>openUsers());usersButton.id='usersButton';usersButton.hidden=true;
 const passwordButton=button('Мой пароль',()=>openPassword());passwordButton.id='passwordButton';passwordButton.hidden=demo;
 const roleLabel=node('span','');roleLabel.id='roleLabel';actions.append(roleLabel,usersButton,passwordButton);
 function permissions(){return demo?{read:true,edit:true,publish:false,manage:false}:getState()?.permissions||{read:true,edit:false,publish:false,manage:false};}
 function apply(){const p=permissions();document.body.dataset.cmsEdit=String(p.edit);document.body.dataset.cmsPublish=String(p.publish);document.body.dataset.cmsManage=String(p.manage);usersButton.hidden=!p.manage;roleLabel.textContent=demo?'Демо':labels[getState()?.role]||'Просмотр';
  for(const id of ['fields','blocks','canvas','properties']){const element=document.getElementById(id);if(element)element.inert=!p.edit;}
 }
 function dialog(title){const d=node('dialog');d.className='access-dialog';d.append(node('h2',title));d.append(button('Закрыть',()=>d.close()));d.addEventListener('close',()=>d.remove());document.body.append(d);d.showModal();return d;}
 function input(form,title,name,type='text',value=''){const label=node('label',title),i=node('input');i.name=name;i.type=type;i.value=value;label.append(i);form.append(label);return i;}
 async function openUsers(){const d=dialog('Пользователи и доступ к сайтам'),notice=node('p','Загружаю…'),content=node('div');notice.setAttribute('role','status');d.append(notice,content);
  async function refresh(selected=null){try{const data=await api('users');content.replaceChildren();notice.textContent='Владелец управляет всеми сайтами. Изменение прав или пароля завершает прежние сеансы пользователя.';
   const chooser=node('select');chooser.setAttribute('aria-label','Пользователь');const fresh=node('option','＋ Новый пользователь');fresh.value='';chooser.append(fresh);
   data.items.forEach(user=>{const option=node('option',user.username+(user.owner?' · владелец':user.active?'':' · отключён'));option.value=String(user.id);chooser.append(option);});content.append(chooser);chooser.value=selected?String(selected):'';
   const area=node('div');content.append(area);chooser.onchange=draw;draw();
   function draw(){area.replaceChildren();const user=data.items.find(u=>String(u.id)===chooser.value);if(user?.owner){area.append(node('p','Владелец имеет полный доступ. Для смены собственного пароля используйте «Мой пароль».'));return;}
    const form=node('form');form.id='userAccessForm';const username=input(form,'Логин','username','text',user?.username||'');username.required=true;username.minLength=3;username.maxLength=100;username.disabled=!!user;username.autocomplete='off';
    const password=input(form,user?'Новый пароль (оставьте пустым, чтобы сохранить прежний)':'Пароль','password','password');password.required=!user;password.minLength=12;password.maxLength=72;password.autocomplete='new-password';
    const active=input(form,'Учётная запись активна','active','checkbox');active.checked=user?.active??true;
    form.append(node('h3','Доступ к сайтам'));for(const site of data.sites){const label=node('label',site.name),select=node('select');select.dataset.site=site.site_key;select.setAttribute('aria-label','Доступ: '+site.name);
     for(const [key,text] of Object.entries({'':'Нет доступа',viewer:labels.viewer,editor:labels.editor,publisher:labels.publisher})){const option=node('option',text);option.value=key;select.append(option);}select.value=user?.memberships.find(m=>m.site_key===site.site_key)?.role||'';label.append(select);form.append(label);}
    const save=node('button',user?'Сохранить доступ':'Создать пользователя');save.type='submit';form.append(save);area.append(form);
    form.onsubmit=async event=>{event.preventDefault();save.disabled=true;notice.textContent='Сохраняю…';try{
     const memberships=[...form.querySelectorAll('[data-site]')].filter(s=>s.value).map(s=>({site_key:s.dataset.site,role:s.value}));
     const result=await api(user?'update-user':'create-user',{id:user?.id,username:username.value,password:password.value,active:active.checked,memberships});password.value='';await refresh(result.id);notice.textContent='Доступ сохранён.';
    }catch(error){notice.textContent=error.message;save.disabled=false;}};
   }
  }catch(error){notice.textContent=error.message;}}
  await refresh();
 }
 function openPassword(){const d=dialog('Смена пароля'),form=node('form'),notice=node('p','После смены пароля остальные сеансы будут завершены.');notice.setAttribute('role','status');
  const current=input(form,'Текущий пароль','current_password','password');current.required=true;current.autocomplete='current-password';
  const password=input(form,'Новый пароль','password','password');password.required=true;password.minLength=12;password.maxLength=72;password.autocomplete='new-password';
  const confirm=input(form,'Повторите новый пароль','confirm','password');confirm.required=true;confirm.autocomplete='new-password';
  const submit=node('button','Изменить пароль');submit.type='submit';form.append(submit);d.append(form,notice);
  form.onsubmit=async event=>{event.preventDefault();if(password.value!==confirm.value){notice.textContent='Новые пароли не совпадают.';return;}submit.disabled=true;try{await api('change-password',{current_password:current.value,password:password.value});form.reset();d.close();if(document.querySelector('#noSites'))document.querySelector('#noSites').append(node('p','Пароль изменён.'));else report('Пароль изменён. Остальные сеансы завершены.');}catch(error){notice.textContent=error.message;submit.disabled=false;}};
 }
 function noSites(){document.querySelector('#login').hidden=true;document.querySelector('#app').hidden=true;let panel=document.querySelector('#noSites');if(!panel){panel=node('div');panel.id='noSites';panel.className='login';document.body.append(panel);}panel.replaceChildren(node('h1','Пока нет доступных сайтов'),node('p','Попросите владельца назначить вам доступ. После этого обновите страницу.'),button('Обновить',()=>location.reload()),button('Мой пароль',()=>openPassword()),button('Выйти',async()=>{try{await api('logout',{});location.reload();}catch(error){panel.append(node('p',error.message));}}));}
 async function unavailable(){const data=await api('sites');noSites();if(!data.items.length)return;const panel=document.querySelector('#noSites');panel.querySelector('h1').textContent='Выберите доступный сайт';panel.querySelector('p').textContent='У вас нет доступа к сайту по этой ссылке. Вам доступны:';for(const item of data.items){const link=node('a',item.name),u=new URL('index.html',location.href);u.searchParams.set('site',item.site_key);link.href=u.href;link.style.cssText='display:block;margin:16px 0';panel.append(link);}}
 return {apply,permissions,noSites,unavailable};
}};
