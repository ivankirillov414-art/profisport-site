(()=>{
 const dialog=document.getElementById('adminMenu');if(!dialog)return;
 function open(){if(!dialog.open)dialog.showModal()}
 document.querySelector('.menu')?.addEventListener('click',open);
 document.querySelector('.bottom a[href="#more"]')?.addEventListener('click',e=>{e.preventDefault();open()});
 document.getElementById('closeAdminMenu').onclick=()=>dialog.close();
 document.getElementById('logoutAdmin').onclick=async()=>{
  const b=document.getElementById('logoutAdmin'),msg=document.getElementById('menuMessage');b.disabled=true;
  try{await api('logout',{method:'POST',body:'{}'});location.href='login.php'}
  catch(e){msg.textContent='Не удалось выйти. Повторите попытку.';b.disabled=false}
 };
})();
