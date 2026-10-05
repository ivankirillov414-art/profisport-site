(()=>{
 const dialog=document.getElementById('adminMenu');if(!dialog)return;
 const search=document.getElementById('adminSectionSearch');
 function open(){if(!dialog.open)dialog.showModal();search.focus()}
 document.querySelector('.menu')?.addEventListener('click',open);
 document.querySelector('.adminMenuTrigger')?.addEventListener('click',open);
 document.querySelector('.bottom a[href="#more"]')?.addEventListener('click',e=>{e.preventDefault();open()});
 document.getElementById('closeAdminMenu').addEventListener('click',()=>dialog.close());
 dialog.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close()}});
 search.addEventListener('input',()=>{
  const query=search.value.trim().toLocaleLowerCase('ru');let found=0;
  dialog.querySelectorAll('.adminMenuGroup').forEach(group=>{
   let visible=0;const title=group.querySelector('h3').textContent;
   group.querySelectorAll('a').forEach(a=>{a.hidden=!(title+' '+a.textContent).toLocaleLowerCase('ru').includes(query);if(!a.hidden)visible++});
   group.hidden=!visible;found+=visible;
  });
  document.getElementById('adminSectionEmpty').hidden=found>0;
 });
 dialog.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>dialog.close()));
 function revealImport(){if(location.hash==='#catalog'||location.hash==='#catalog-expand'){const section=document.getElementById('catalog-expand');if(section)section.open=true}}
 revealImport();window.addEventListener('hashchange',revealImport);
 document.getElementById('logoutAdmin').addEventListener('click',async()=>{
  const b=document.getElementById('logoutAdmin'),msg=document.getElementById('menuMessage');b.disabled=true;msg.textContent='';
  try{
   const meResponse=await fetch('../server/api.php?action=me',{cache:'no-store'});const me=await meResponse.json();if(!meResponse.ok||!me.csrf)throw new Error('auth');
   const r=await fetch('../server/api.php?action=logout',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':me.csrf},body:'{}'});if(!r.ok)throw new Error('logout');location.href='login.php';
  }catch(e){msg.textContent='Не удалось выйти. Повторите попытку.';b.disabled=false}
 });
})();
