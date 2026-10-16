/* Connection draft: never read, store or prefill password fields. */
(()=>{
 const form=document.getElementById('migrationForm');if(!form)return;
 const note=document.getElementById('migrationDraftStatus');
 const fields=['target_url','ftp_host','ftp_port','ftp_user','ftp_root','db_host','db_port','db_name','db_user'];
 const key='profisport-migration-connection-v1';
 function apply(data){
  if(!data||typeof data!=='object'||Array.isArray(data))return 0;
  let count=0;
  for(const name of fields){
   if(!Object.prototype.hasOwnProperty.call(data,name)||typeof data[name]!=='string'||data[name].length>2048)continue;
   if(name.endsWith('_port')&&!/^(?:[1-9][0-9]{0,4})$/.test(data[name]))continue;
   if(name.endsWith('_port')&&Number(data[name])>65535)continue;
   const input=form.elements.namedItem(name);if(!input||input.type==='password')continue;
   input.value=data[name];count++;
  }
  return count;
 }
 function save(){
  const data={};for(const name of fields){const input=form.elements.namedItem(name);if(input&&input.type!=='password')data[name]=input.value;}
  try{localStorage.setItem(key,JSON.stringify(data));}catch{}
 }
 let restored=0;try{restored=apply(JSON.parse(localStorage.getItem(key)||'null'));}catch{}
 const hash=new URLSearchParams(location.hash.slice(1));
 if(hash.has('setup')){
  try{restored=apply(JSON.parse(hash.get('setup')))||restored;}catch{}
  // Keep connection identifiers out of subsequently copied page addresses.
  history.replaceState(null,'',location.pathname+location.search);
 }
 if(restored){save();if(note)note.textContent='Параметры подключения заполнены. Проверьте папку назначения. Пароли вводятся отдельно; перенос не запущен.';}
 form.addEventListener('input',save);form.addEventListener('change',save);
})();
/* Migration request controller */
(()=>{
const $=id=>document.getElementById(id),form=$('migrationForm'),msg=$('migrationMessage'),jobs=$('migrationJobs');let csrf='';
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const errors={current_password_invalid:'Неверный текущий пароль.',owner_required:'Перенос доступен только владельцу.',target_database_not_empty:'Целевая база не пустая. Для безопасного переноса укажите пустую базу.',target_ftps_connect_failed:'Не удалось подключиться к FTPS-серверу.',target_ftps_login_failed:'Неверный FTPS-логин или пароль.',target_ftps_write_failed:'Нет права записи в папку нового сайта.',target_db_credentials_required:'Заполните реквизиты новой базы.',target_url_https_required:'Адрес нового сайта должен начинаться с https://',migration_already_active:'Уже есть активное задание переноса.',ftps_unavailable_on_current_host:'На текущем хостинге недоступен FTPS-клиент. Автоперенос с этого сервера невозможен.'};
async function json(url,opt={}){const r=await fetch(url,{cache:'no-store',...opt});const j=await r.json().catch(()=>({error:'bad_json'}));if(!r.ok)throw new Error(errors[j.error]||j.error||('HTTP '+r.status));return j}
async function auth(){const j=await json('../server/api.php?action=me');csrf=j.csrf||''}
function payload(includePassword=true){const v=Object.fromEntries(new FormData(form));if(!includePassword)delete v.current_password;return v}
async function call(action,data){return json('../api/migration.php?action='+encodeURIComponent(action),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)})}
function phase(x){return({db_schema:'Структура базы',db_data:'Данные',files:'Файлы',config:'Конфигурация',verify:'Проверка',completed:'Завершено'})[x]||x}
function status(x){return({scheduled:'Запланирован',running:'Выполняется',completed:'Завершён',failed:'Ошибка',cancelled:'Отменён'})[x]||x}
async function load(){
 const j=await json('../api/migration.php?action=status');const list=j.items||[];
 jobs.innerHTML=list.length?list.map(x=>{const p=x.progress||{};return '<div class="job"><div><span class="pill">'+esc(status(x.status))+'</span> · <b>#'+x.id+'</b></div><div>'+esc(phase(x.phase))+' · запуск '+esc(x.due_at)+'</div><div class="muted">Таблиц: '+Number(p.data_tables||p.schema_tables||0)+' · строк: '+Number(p.rows_copied||0)+' · файлов: '+Number(p.files_copied||0)+'</div>'+(x.error?'<p class="bad">'+esc(x.error)+'</p>':'')+(x.status==='scheduled'?'<button class="btn danger" data-cancel="'+x.id+'" type="button">Отменить</button>':x.status==='failed'?'<button class="btn secondary" data-retry="'+x.id+'" type="button">Повторить с места ошибки</button>':'')+'</div>'}).join(''):'<p class="muted">Заданий переноса пока нет.</p>';
 jobs.querySelectorAll('[data-cancel]').forEach(b=>b.onclick=async()=>{const password=prompt('Введите текущий пароль админки для отмены переноса');if(!password)return;b.disabled=true;try{await call('cancel',{id:Number(b.dataset.cancel),current_password:password});await load()}catch(e){alert(e.message)}finally{b.disabled=false}});
 jobs.querySelectorAll('[data-retry]').forEach(b=>b.onclick=async()=>{const password=prompt('Введите текущий пароль админки для повторного запуска');if(!password)return;b.disabled=true;try{await call('retry',{id:Number(b.dataset.retry),current_password:password});await load()}catch(e){alert(e.message)}finally{b.disabled=false}});
}
$('preflightBtn').onclick=async()=>{const b=$('preflightBtn');b.disabled=true;msg.className='muted';msg.textContent='Проверяем новый сервер…';try{const j=await call('preflight',payload(false));msg.className='ok';msg.textContent='Проверка пройдена: FTPS работает, MySQL доступен, база пустая.';}catch(e){msg.className='bad';msg.textContent=e.message}finally{b.disabled=false}};
form.onsubmit=async e=>{e.preventDefault();const password=form.elements.current_password.value;if(!password){msg.className='bad';msg.textContent='Введите текущий пароль для подтверждения.';return}if(!confirm('Запланировать полный перенос файлов и базы на новый сервер? До запуска его можно отменить.'))return;const b=e.submitter;b.disabled=true;msg.className='muted';msg.textContent='Повторно проверяем сервер и создаём задание…';try{const j=await call('schedule',payload(true));msg.className='ok';msg.textContent='Перенос запланирован на '+j.due_at+'. Пароль подтверждён.';form.elements.current_password.value='';await load()}catch(e){msg.className='bad';msg.textContent=e.message}finally{b.disabled=false}};
$('refreshMigration').onclick=load;
(async()=>{try{await auth();await load()}catch(e){msg.className='bad';msg.textContent=e.message}})();
})();