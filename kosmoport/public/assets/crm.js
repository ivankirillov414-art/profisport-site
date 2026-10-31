import {createApi, ApiError} from './crm-api.js';
import {escapeHtml as e, number, date, filename, items, objectJson, canAdmit, sourceUsed} from './crm-utils.js';
import {recordPayload, staffPayload, websiteFields, splitWebsiteContent, composeWebsiteContent, canManageStaff} from './crm-forms.js';

const api = createApi(new URL('../api/index.php', document.baseURI));
const app = document.querySelector('#app');
const modal = document.querySelector('#modal');
const notice = document.querySelector('#notice');
const rootUrl = new URL('../', document.baseURI).href;
const state = {user:null, route:'overview', epoch:0, editor:null, ticket:null, admission:null, ticketCode:'', requestId:null, users:[]};
let toastTimer, modalId=0;
const labels = {overview:'Обзор',partners:'Партнёры',tasks:'Задачи',tickets:'Билеты',documents:'Документы',website:'Сайт',settings:'Настройки'};
const staffRoles={owner:'Владелец',admin:'Администратор',manager:'Менеджер',cashier:'Кассир',editor:'Редактор сайта',document_manager:'Документы',reader:'Просмотр'};
const canManageUsers=()=>canManageStaff(state.user?.role);
const canManageRecords=()=>['owner','admin','manager'].includes(state.user?.role);
const partnerStatuses={new:'Новый',active:'Активен',contacted:'На связи',negotiation:'Обсуждение',negotiating:'Переговоры',agreed:'Договорились',confirmed:'Подтверждён',paused:'Приостановлен',declined:'Отказ',archived:'В архиве'};
const taskStatuses={todo:'Запланирована',in_progress:'В работе',blocked:'Заблокирована',done:'Выполнена',cancelled:'Отменена'};
const statusLabels = {new:'Новый',active:'Активен',contacted:'На связи',negotiation:'Обсуждение',negotiating:'Переговоры',confirmed:'Подтверждён',declined:'Отказ',agreed:'Договорились',paused:'Приостановлен',archived:'В архиве',pending:'Запланирована',todo:'Запланирована',in_progress:'В работе',done:'Выполнена',cancelled:'Отменена',valid:'Готов к проходу',ready:'Готов к проходу',available:'Готов к проходу',unused:'Не использован',used:'Уже использован',admitted:'Проход зарегистрирован',blocked:'Заблокирован',expired:'Срок истёк',revoked:'Отозван'};
const roleLabels = {owner:'Владелец',admin:'Администратор',manager:'Менеджер',editor:'Редактор',document_manager:'Документы',ticket_manager:'Билеты',ticket_operator:'Оператор билетов',operator:'Оператор',cashier:'Оператор',reader:'Просмотр',viewer:'Просмотр',staff:'Сотрудник'};
const icons = {overview:'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z', partners:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M16 3a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',tasks:'M9 5h12 M9 12h12 M9 19h12 M2 5l1 1 2-2 M2 12l1 1 2-2 M2 19l1 1 2-2',tickets:'M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4z M15 5v14',documents:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M8 13h8 M8 17h6',website:'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0 M3 12h18 M12 3c4 5 4 13 0 18 M12 3c-4 5-4 13 0 18'};
const icon = key => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${icons[key]}"></path></svg>`;
const status = value => `<span class="badge ${['done','active','agreed','valid','admitted'].includes(value)?'good':''}">${e(statusLabels[value] || value || 'Не указан')}</span>`;
const empty = (title, detail = '') => `<div class="empty"><p>${e(title)}</p>${detail ? `<small>${e(detail)}</small>` : ''}</div>`;
const heading = (title, description, action = '') => `<header class="page-heading"><div><h1>${e(title)}</h1><p class="muted">${e(description)}</p></div>${action}</header>`;
const errorBox = (error, retry = false) => `<div class="alert" role="alert">${e(error.message || 'Не удалось загрузить данные.')}${retry ? '<div><button data-action="reload">Повторить</button></div>' : ''}</div>`;
const options = (values, selected, placeholder) => `${placeholder ? `<option value="">${e(placeholder)}</option>` : ''}${Object.entries(values).map(([key,label])=>`<option value="${e(key)}" ${String(selected??'')===String(key)?'selected':''}>${e(label)}</option>`).join('')}`;
const field = (name,label,value='', type='text', extra='') => `<label>${e(label)}<input name="${e(name)}" type="${type}" value="${e(value)}" ${extra}></label>`;
function toast(message, error = false) {
  clearTimeout(toastTimer); notice.textContent = message; notice.classList.toggle('error', error); notice.hidden = false;
  toastTimer = setTimeout(()=>{notice.hidden=true;}, 6500);
}
function download(blob, name) {
  const link = document.createElement('a'); const url = URL.createObjectURL(blob);
  link.href=url; link.download=name; link.click(); setTimeout(()=>URL.revokeObjectURL(url),30000);
}
function login(message = '') {
  state.user=null; state.epoch++; modal.close(); state.editor=null;
  app.innerHTML=`<div class="login"><aside class="login-brand"><div><div class="wordmark">КОСМОПОРТ<small>Рабочее пространство выставки</small></div><h1>Всё для работы.<br>В одном месте.</h1><p>Партнёры, приглашения, задачи и документы команды.</p></div><p class="login-footer">Доступ только для сотрудников</p></aside><main id="main" class="login-main" tabindex="-1"><h2>Вход в CRM</h2><p class="muted">Используйте учётную запись сотрудника.</p><div id="login-message">${message ? errorBox({message}) : ''}</div><form id="login-form">${field('email','Электронная почта','','email','required autocomplete="username" maxlength="254"')}${field('password','Пароль','','password','required autocomplete="current-password" maxlength="1024"')}<div class="form-error" hidden role="alert"></div><button class="primary" type="submit">Войти</button></form><p class="hint">Если доступа ещё нет, обратитесь к администратору.</p></main></div>`;
  document.querySelector('#login-form').addEventListener('submit',event=>{
    event.preventDefault(); const form=event.currentTarget;
    formRun(form,async()=>{
      // Refresh CSRF bootstrap after an expired session before attempting login.
      await api.get('auth/me');
      const data=await api.post('auth/login',Object.fromEntries(new FormData(form)));
      if(!data?.user) throw new ApiError('Сервер не подтвердил вход. Повторите попытку.');
      state.user=data.user; shell(); await navigate(false);
    });
  });
}
function shell() {
  app.innerHTML=`<div class="shell"><aside class="sidebar"><div class="wordmark">КОСМОПОРТ<small>CRM · команда выставки</small></div><nav class="nav" aria-label="Разделы CRM">${Object.entries(labels).filter(([key])=>key!=='settings' || canManageUsers()).map(([key,label])=>`<a href="#${key}" data-nav="${key}">${icons[key]?icon(key):''}${label}</a>`).join('')}</nav><div class="sidebar-foot">Время: Екатеринбург<a href="${e(rootUrl)}" target="_blank" rel="noopener">Открыть сайт</a></div></aside><div class="workspace"><header class="topbar"><div class="user">${e(state.user.name || state.user.email)}<small>${e(roleLabels[state.user.role] || 'Сотрудник')}</small></div><button class="text small" data-action="logout">Выйти</button></header><main id="main" class="content" tabindex="-1"></main></div></div>`;
}
async function formRun(form, callback) {
  if(form.dataset.busy) return;
  const error=form.querySelector('.form-error'); if(error) error.hidden=true;
  const submit=form.querySelector('[type=submit]'); const wasDisabled=submit?.disabled;
  form.dataset.busy='true'; if(submit){submit.disabled=true;submit.setAttribute('aria-busy','true');}
  try { await callback(); }
  catch(err){
    if(err.status===401 && state.user){login('Сессия истекла. Войдите снова. Несохранённые действия не отправлены.');}
    else if(error){error.textContent=err.message || 'Не удалось выполнить действие.';error.hidden=false;error.scrollIntoView({block:'nearest'});}
    else toast(err.message || 'Не удалось выполнить действие.',true);
  } finally {delete form.dataset.busy;if(submit){submit.disabled=!!wasDisabled;submit.removeAttribute('aria-busy');}}
}
function dialog(title, body, onsubmit) {
  const id=++modalId;
  modal.innerHTML=`<header><h2 id="modal-title">${e(title)}</h2><button type="button" data-action="close-modal" aria-label="Закрыть">×</button></header>${body}`;
  const form=modal.querySelector('form'); if(form && onsubmit) form.addEventListener('submit',event=>{event.preventDefault();formRun(form,()=>onsubmit(form));});
  modal.showModal();return id;
}
function formFooter(label='Сохранить') {return `<div class="form-error" hidden role="alert"></div><div class="actions"><button type="button" data-action="close-modal">Отмена</button><button type="submit" class="primary">${e(label)}</button></div>`;}
async function findFreshRecord(route,id) {
  for(let offset=0;offset<20000;offset+=100){
    const data=await api.get(route,{limit:100,offset,...(route==='users'?{include_inactive:1}:{})});
    const rows=items(data), found=rows.find(row=>String(row.id)===String(id));
    if(found)return found;
    if(rows.length<100 || offset+rows.length>=Number(data.total))break;
  }
  throw new Error('Карточка не найдена в актуальном списке. Закройте форму и обновите раздел.');
}
function conflictReview(form,route,record){
  let box=form.querySelector('.record-conflict');if(!box){box=document.createElement('div');box.className='record-conflict alert info';form.querySelector('.actions')?.before(box);}
  box.innerHTML='<p>Карточка уже изменена. Ваши значения остались в форме. Сверьте их с текущими данными перед сохранением.</p><button type="button">Сверить с сервером</button>';
  box.querySelector('button').addEventListener('click',async event=>{
    const button=event.currentTarget;button.disabled=true;
    try{
      const current=await findFreshRecord(route,record.id);if(!form.isConnected)return;
      const rows=[...form.elements].filter(el=>el.name && !['password','expected_revision'].includes(el.name));
      const show=(el,value)=>{
        if(el.type==='checkbox')return value?'Да':'Нет';
        if(el.tagName==='SELECT')return [...el.options].find(option=>option.value===String(value??''))?.textContent || value || 'Не указано';
        return value===null || value===undefined || value===''?'Не указано':String(value);
      };
      box.innerHTML=`<p>Актуальная версия: ${e(current.revision)}. Введённые вами значения не изменились.</p><div class="table-wrap"><table><thead><tr><th>Поле</th><th>На сервере</th></tr></thead><tbody>${rows.map(el=>`<tr><td>${e(el.closest('label')?.childNodes[0]?.textContent?.trim() || el.name)}</td><td class="wrap">${e(show(el,current[el.name]))}</td></tr>`).join('')}</tbody></table></div><label class="check"><input type="checkbox"><span>Я сверил данные и хочу сохранить введённые значения.</span></label><button type="button" disabled>Использовать актуальную версию</button>`;
      box.querySelector('input').addEventListener('change',event=>{box.querySelector('button').disabled=!event.target.checked;});
      box.querySelector('button').addEventListener('click',()=>{
        record.revision=current.revision;
        box.textContent='Версия обновлена. Ваши значения сохранены в форме. Проверьте их и нажмите «Сохранить».';
        const error=form.querySelector('.form-error');if(error)error.hidden=true;
      });
    }catch(err){button.disabled=false;toast(err.message,true);}
  });
}
async function saveRecord(form,route,record,values){
  try{return await api.post(route,route==='users'?staffPayload(values,record):recordPayload(values,record));}
  catch(err){if(err.status===409 && record.id && err.code==='REVISION_CONFLICT')conflictReview(form,route,record);throw err;}
}
function isDirty(){return !!state.editor && state.editor.inputSignature!==state.editor.savedSignature;}
async function navigate(focus = true) {
  if(!state.user)return;
  const requested=location.hash.slice(1) || 'overview';
  if(isDirty() && requested!==state.route){
    if(!window.confirm('В редакторе есть несохранённые изменения. Выйти без сохранения?')){history.replaceState(null,'',`#${state.route}`);return;}
  }
  state.editor=null; state.ticket=null; state.admission=null; modal.close();
  state.route=requested; const [section,sub='']=requested.split('/');
  document.title=`${labels[section] || 'CRM'} — Космопорт`;
  document.querySelectorAll('[data-nav]').forEach(link=>{if(link.dataset.nav===section)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');});
  const main=document.querySelector('#main'); const epoch=++state.epoch;
  main.innerHTML='<div class="loading" role="status">Загружаем данные…</div>';
  if(focus)main.focus({preventScroll:true});
  try {
    const render={overview:renderOverview,partners:renderPartners,tasks:renderTasks,tickets:()=>renderTickets(sub),documents:renderDocuments,website:renderWebsite,settings:renderSettings}[section];
    if(!render){main.innerHTML=heading('Раздел не найден','Выберите раздел в меню.')+'<a href="#overview">Вернуться к обзору</a>';return;}
    await render(epoch);
  } catch(err){
    if(epoch!==state.epoch)return;
    if(err.status===401){login('Сессия истекла. Войдите снова.');return;}
    main.innerHTML=heading(labels[section] || 'CRM', '')+`<section class="error-page"><h2>${err.status===403?'Нет доступа к разделу':'Не удалось загрузить данные'}</h2>${errorBox(err,true)}${err.status===503?'<p class="muted">CRM ещё не настроена или временно недоступна. Обратитесь к администратору.</p>':''}</section>`;
  }
}
function setPage(html, epoch) {if(epoch!==state.epoch)return false;document.querySelector('#main').innerHTML=html;return true;}

async function renderOverview(epoch) {
  const data=await api.get('dashboard');
  const counts=data?.counts || {};
  const metrics=[['partners','Партнёров'],['open_tasks','Открытых задач'],['documents','Документов']].filter(([key])=>Object.hasOwn(counts,key));
  setPage(heading('Обзор','Рабочие дела команды выставки.')+`<div class="metrics">${metrics.map(([key,label])=>`<div class="metric"><strong>${number(counts[key])}</strong><span>${label}</span></div>`).join('')}</div><section class="panel"><h2>Ближайшие задачи</h2>${taskRows(data?.recent_tasks || data?.tasks || [],false)}<div class="actions"><a href="#tasks">Все задачи</a></div></section><div class="actions"><a href="#tickets/scan">Проверить приглашение</a><a href="#documents">Найти документ</a></div>`,epoch);
}
async function renderPartners(epoch, query = {}) {
  const data=await api.get('partners',query);
  if(!setPage(heading('Партнёры','Организации, точки распространения и контакты.','<button class="primary" data-action="add-partner">Добавить партнёра</button>')+`<section class="panel"><form id="partner-search" class="toolbar"><label class="grow">Поиск<input name="q" value="${e(query.q)}" type="search" placeholder="Организация, контакт, телефон"></label><label>Статус<select name="status">${options(partnerStatuses,query.status,'Все статусы')}</select></label><button type="submit">Найти</button><div class="form-error span-2" hidden role="alert"></div></form>${items(data).length?`<div class="table-wrap"><table><thead><tr><th>Организация</th><th>Контакт</th><th>Статус</th><th><span class="sr-only">Действия</span></th></tr></thead><tbody>${items(data).map(p=>`<tr><td class="wrap"><strong>${e(p.name)}</strong><small>${e(p.address || '')}</small></td><td>${e(p.contact_name || '—')}<small>${e([p.phone,p.email].filter(Boolean).join(' · '))}</small></td><td>${status(p.status)}</td><td><button class="small" data-partner-id="${e(p.id)}">Изменить</button></td></tr>`).join('')}</tbody></table></div>`:empty(query.q || query.status?'Партнёры не найдены':'Партнёров пока нет', 'Добавьте организацию и контакт ответственного.')}${pagination(data)}</section>`,epoch))return;
  document.querySelector('#partner-search').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;formRun(form,()=>renderPartners(epoch,Object.fromEntries(new FormData(form))));});
  bindPagination(offset=>renderPartners(epoch,{...query,offset}));
  document.querySelectorAll('[data-partner-id]').forEach(button=>button.addEventListener('click',()=>editPartner(items(data).find(p=>String(p.id)===button.dataset.partnerId))));
}
function pagination(data){
  if(!Number.isFinite(data?.total))return '';
  const offset=Number(data.offset || 0),limit=Number(data.limit || items(data).length || 25);
  return `<div class="pagination">Показано ${number(items(data).length?offset+1:0)}–${number(offset+items(data).length)} из ${number(data.total)}<div class="actions"><button class="small" data-page-offset="${Math.max(0,offset-limit)}" ${offset===0?'disabled':''}>Назад</button><button class="small" data-page-offset="${offset+limit}" ${offset+items(data).length>=data.total?'disabled':''}>Далее</button></div></div>`;
}
function bindPagination(callback){document.querySelectorAll('[data-page-offset]').forEach(button=>button.addEventListener('click',async()=>{button.disabled=true;try{await callback(Number(button.dataset.pageOffset));}catch(err){if(err.status===401)login('Сессия истекла. Войдите снова.');else toast(err.message,true);}finally{if(button.isConnected)button.disabled=false;}}));}
function editPartner(partner = {}) {
  if(!canManageRecords()){toast('У вашей роли нет права изменять партнёров.',true);return;}
  const record={...partner};
  dialog(partner.id?'Изменить партнёра':'Добавить партнёра',`<form><div class="form-grid">${field('name','Название организации *',partner.name,'text','required maxlength="200"')}${field('contact_name','Контактное лицо',partner.contact_name,'text','maxlength="200"')}${field('phone','Телефон',partner.phone,'tel','maxlength="80"')}${field('email','Почта',partner.email,'email','maxlength="254"')}<div class="span-2">${field('address','Адрес точки',partner.address,'text','maxlength="500"')}</div><label>Статус<select name="status">${options(partnerStatuses,partner.status || 'new')}</select></label><label class="span-2">Заметка<textarea name="notes" maxlength="10000">${e(partner.notes)}</textarea></label></div>${formFooter()}</form>`,async form=>{
    await saveRecord(form,'partners',record,Object.fromEntries(new FormData(form)));modal.close();toast('Партнёр сохранён.');await navigate(false);
  });
}
function taskRows(rows, controls=true) {
  if(!Array.isArray(rows) || !rows.length)return empty('Задач пока нет','Создайте задачу и назначьте ответственного.');
  return rows.map(task=>`<div class="record-row"><div><strong>${e(task.title)}</strong><p class="meta">${task.due_date?'До '+e(date(task.due_date)):'Без срока'}${task.assignee_name?' · '+e(task.assignee_name):''}</p></div><div class="actions tight">${status(task.status)}${controls?`<button class="small" data-task-id="${e(task.id)}">Изменить</button>`:''}</div></div>`).join('');
}
async function renderTasks(epoch, query = {}) {
  const [data,users] = await Promise.all([api.get('tasks',query),api.get('users').catch(err=>{if(err.status===403)return {items:[]};throw err;})]);
  if(epoch!==state.epoch)return;state.users=items(users);
  setPage(heading('Задачи','Договорённости, сроки и ответственные.','<button class="primary" data-action="add-task">Добавить задачу</button>')+`<section class="panel"><form id="task-search" class="toolbar"><label class="grow">Поиск<input type="search" name="q" value="${e(query.q)}" placeholder="Название задачи"></label><label>Статус<select name="status">${options(taskStatuses,query.status,'Все задачи')}</select></label><button type="submit">Показать</button><div class="form-error" hidden role="alert"></div></form>${taskRows(items(data))}${pagination(data)}</section>`,epoch);
  document.querySelector('#task-search').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;formRun(form,()=>renderTasks(epoch,Object.fromEntries(new FormData(form))));});
  bindPagination(offset=>renderTasks(epoch,{...query,offset}));
  document.querySelectorAll('[data-task-id]').forEach(button=>button.addEventListener('click',()=>editTask(items(data).find(t=>String(t.id)===button.dataset.taskId))));
}
function editTask(task = {}) {
  const record={...task};
  const users=Object.fromEntries(state.users.filter(user=>canManageRecords() || user.id===state.user.id || user.id===task.assignee_id).map(user=>[user.id,user.name || user.email]));
  if(task.assignee_id && !Object.hasOwn(users,task.assignee_id))users[task.assignee_id]=task.assignee_name || 'Текущий ответственный';
  dialog(task.id?'Изменить задачу':'Новая задача',`<form>${field('title','Что нужно сделать *',task.title,'text','required maxlength="240"')}<div class="form-grid"><label>Статус<select name="status">${options(taskStatuses,task.status || 'todo')}</select></label>${field('due_date','Срок',task.due_date?.slice(0,10),'date')}<label class="span-2">Ответственный<select name="assignee_id">${options(users,task.assignee_id,'Не назначен')}</select></label></div>${state.users.length?'':'<p class="hint">Список сотрудников недоступен. Задачу можно сохранить без назначения.</p>'}${formFooter()}</form>`,async form=>{
    const payload=Object.fromEntries(new FormData(form));payload.due_date||=null;payload.assignee_id||=null;
    await saveRecord(form,'tasks',record,payload);modal.close();toast('Задача сохранена.');await navigate(false);
  });
}

async function renderSettings(epoch,query={}){
  if(!canManageUsers())throw new ApiError('Настройки сотрудников доступны только владельцу и администратору.',403,'FORBIDDEN');
  const data=await api.get('users',{...query,include_inactive:1});
  if(!setPage(heading('Настройки','Учётные записи сотрудников CRM.','<button class="primary" data-action="add-user">Добавить сотрудника</button>')+`<section class="panel"><h2>Сотрудники</h2>${items(data).length?`<div class="table-wrap"><table><thead><tr><th>Сотрудник</th><th>Роль</th><th>Доступ</th><th>Действия</th></tr></thead><tbody>${items(data).map(user=>`<tr><td class="wrap"><strong>${e(user.name)}</strong><small>${e(user.email)}</small></td><td>${e(staffRoles[user.role] || 'Не указана')}</td><td>${user.active?'<span class="badge good">Активен</span>':'<span class="badge">Отключён</span>'}</td><td>${state.user.role==='owner' || user.role!=='owner'?`<button class="small" data-user-id="${e(user.id)}">Изменить</button>`:''}</td></tr>`).join('')}</tbody></table></div>`:empty('Сотрудников не найдено')}${pagination(data)}</section>${state.user.role==='owner'?`<section class="panel"><h2>Резервное копирование</h2><a href="${e(new URL('maintenance.php',rootUrl).href)}">Открыть инструмент резервного копирования</a></section>`:''}`,epoch))return;
  bindPagination(offset=>renderSettings(epoch,{...query,offset}));
  document.querySelectorAll('[data-user-id]').forEach(button=>button.addEventListener('click',()=>editUser(items(data).find(user=>String(user.id)===button.dataset.userId))));
}
function editUser(user={}){
  if(!canManageUsers())return;
  const record={...user};const roles={...staffRoles};if(state.user.role!=='owner')delete roles.owner;
  dialog(user.id?'Изменить сотрудника':'Добавить сотрудника',`<form autocomplete="off">${field('name','Имя *',user.name,'text','required maxlength="200" autocomplete="off"')}${field('email','Электронная почта *',user.email,'email','required maxlength="254" autocomplete="off"')}<label>Роль<select name="role">${options(roles,user.role || 'reader')}</select></label><label class="check"><input type="checkbox" name="active" ${user.active!==false?'checked':''}><span>Сотрудник активен</span></label>${field('password',user.id?'Новый пароль':'Пароль *','','password',`${user.id?'':'required '}minlength="12" maxlength="72" autocomplete="new-password"`)}<p class="hint">${user.id?'Оставьте пароль пустым, чтобы сохранить текущий. ':''}От 12 символов, до 72 байт UTF-8. Изменение роли, активности или пароля завершит прежние сессии сотрудника.</p>${formFooter()}</form>`,async form=>{
    const values=Object.fromEntries(new FormData(form));values.active=form.elements.active.checked;
    const saved=await saveRecord(form,'users',record,values);modal.close();toast('Учётная запись сохранена.');
    if(saved.id===state.user.id){const me=await api.get('auth/me');if(!me?.user){api.clearSession();login('Параметры вашей учётной записи изменены. Войдите снова.');return;}state.user=me.user;shell();if(!canManageUsers()){location.hash='overview';return;}}
    await navigate(false);
  });
}

const ticketTabs = sub => `<nav class="section-links" aria-label="Работа с билетами">${[['scan','Проверка приглашения'],['statistics','Статистика'],['sources','Точки и партии'],['exchange','Обмен данными']].map(([key,label])=>`<a href="#tickets/${key}" ${key===sub?'aria-current="page"':''}>${label}</a>`).join('')}</nav>`;
async function renderTickets(sub) {
  const epoch=state.epoch; sub||='scan';
  const main=document.querySelector('#main'); main.innerHTML=heading('Билеты','Проверка приглашений и учёт проходов.')+ticketTabs(sub)+'<div id="ticket-page"><div class="loading" role="status">Загружаем…</div></div>';
  if(sub==='scan')return renderScan(epoch);
  if(sub==='statistics')return renderStats(epoch);
  if(sub==='sources')return renderSources(epoch);
  if(sub==='exchange')return renderExchange();
  document.querySelector('#ticket-page').innerHTML=empty('Раздел не найден');
}
function setTickets(html,epoch){if(epoch!==state.epoch)return false;document.querySelector('#ticket-page').innerHTML=html;return true;}
function statsMetrics(data) {
  return `<div class="metric"><strong>${number(data?.used_invitations)}</strong><span>Использовано приглашений</span></div><div class="metric"><strong>${e(typeof data?.top_source==='object'?data.top_source?.name || '—':data?.top_source || '—')}</strong><span>Ведущая точка распространения</span></div>`;
}
async function renderScan(epoch) {
  if(!setTickets(`<div class="scan-layout"><section class="panel"><form id="scan-form" class="scan-form"><h2>Проверить приглашение</h2><label>Код приглашения<input name="code" id="ticket-code" required maxlength="200" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Отсканируйте или введите код"></label><p class="hint">Сканер работает как клавиатура. Проверка кода ещё не регистрирует проход.</p><div class="form-error" hidden role="alert"></div><button type="submit" class="primary">Проверить</button></form><div id="ticket-result" aria-live="polite"></div></section><aside class="scan-side" id="scan-metrics" aria-label="Сегодня"><p class="muted" role="status">Загружаем показатели за сегодня…</p></aside></div>`,epoch))return;
  document.querySelector('#scan-form').addEventListener('submit',event=>{
    event.preventDefault();const form=event.currentTarget;formRun(form,async()=>{
      const code=new FormData(form).get('code').trim();if(!code)throw new Error('Введите код приглашения.');
      state.ticket=null;state.admission=null;document.querySelector('#ticket-result').innerHTML='';
      const data=await api.post('tickets/check',{code});if(epoch!==state.epoch || document.querySelector('#ticket-code').value.trim()!==code)return;
      state.ticket=data;state.ticketCode=code;state.requestId=crypto.randomUUID();showTicket();
    });
  });
  document.querySelector('#ticket-code').addEventListener('input',()=>{state.ticket=null;state.admission=null;const el=document.querySelector('#ticket-result');if(el)el.innerHTML='';});
  await refreshScanStats(epoch);
}
async function refreshScanStats(epoch){
  try{const data=await api.get('tickets/statistics',{period:'day'});if(epoch===state.epoch && document.querySelector('#scan-metrics'))document.querySelector('#scan-metrics').innerHTML='<div class="eyebrow">Сегодня</div>'+statsMetrics(data);}
  catch(err){if(epoch===state.epoch && document.querySelector('#scan-metrics'))document.querySelector('#scan-metrics').innerHTML=`<p class="hint">${e(err.status===403?'Показатели недоступны для вашей роли.':'Не удалось загрузить показатели за сегодня.')}</p>`;}
}
function showTicket() {
  const data=state.ticket,ticket=data?.ticket || data; if(!data)return;
  const allowed=canAdmit(data);const source=ticket.source_name || ticket.source?.name || (typeof ticket.source==='string'?ticket.source:'') || data.source?.name;
  document.querySelector('#ticket-result').innerHTML=`<section class="ticket-result"><p class="eyebrow">Результат проверки</p><h3 class="result-title">${allowed?'Приглашение найдено':e(data.message || 'Проход недоступен')}</h3>${status(data.state || data.status || ticket.status)}<dl><dt>Код</dt><dd>${e(state.ticketCode)}</dd><dt>Точка выдачи</dt><dd>${e(source || 'Не указана')}</dd>${ticket.admitted_at?`<dt>Проход</dt><dd>${e(date(ticket.admitted_at,true))}</dd>`:''}</dl>${allowed?`<form id="admit-form"><label class="check"><input type="checkbox" name="adult_payment_confirmed" required><span>Оплата взрослого подтверждена вручную</span></label><p class="hint">Отметьте только после проверки оплаты. CRM не проводит платёж через кассу.</p><div class="form-error" hidden role="alert"></div><button type="submit" class="primary" disabled>Подтвердить проход</button></form>`:`<p class="muted">${e(data.reason || (['used','admitted'].includes(data.state || data.status || ticket.status)?'Повторный проход по этому приглашению не регистрируется.':'Проверьте статус приглашения. При необходимости обратитесь к администратору.'))}</p>`}</section>`;
  const form=document.querySelector('#admit-form');if(!form)return;
  form.querySelector('input').addEventListener('change',event=>{form.querySelector('button').disabled=!event.target.checked;});
  form.addEventListener('submit',event=>{event.preventDefault();const epoch=state.epoch;formRun(form,async()=>{
    if(!form.querySelector('input').checked || !state.ticket || !canAdmit(state.ticket))throw new Error('Проверьте приглашение и подтвердите оплату взрослого.');
    const result=await api.post('tickets/admit',{code:state.ticketCode,adult_payment_confirmed:true,request_id:state.requestId});
    if(epoch!==state.epoch)return;state.admission=result.admission || {...result,id:result.admission_id};state.ticket=null;
    document.querySelector('#ticket-result').innerHTML=`<section class="ticket-result"><div class="alert success"><strong>Проход зарегистрирован</strong><p>${e(state.ticketCode)}</p></div><div class="actions"><button class="primary" data-action="next-ticket">Следующее приглашение</button>${state.admission?.id && (result.can_reverse || ['owner','admin','manager'].includes(state.user.role))?'<button data-action="reverse-ticket">Отменить ошибочный проход</button>':''}</div></section>`;
    await refreshScanStats(epoch);
  });});
}
function reverseTicket(){
  const admission=state.admission;if(!admission?.id)return;
  dialog('Отмена прохода',`<form><p>Укажите причину. Отмена останется в журнале.</p><label>Причина *<textarea name="reason" required maxlength="2000"></textarea></label>${formFooter('Отменить проход')}</form>`,async form=>{
    await api.post('tickets/reverse',{admission_id:admission.id,reason:new FormData(form).get('reason').trim()});modal.close();toast('Проход отменён.');await navigate(false);
  });
}
async function renderStats(epoch,period='day'){
  const data=await api.get('tickets/statistics',{period}); const sources=data?.sources || data?.by_source || [];
  const max=Math.max(1,...sources.map(source=>Number(sourceUsed(source)) || 0));
  if(!setTickets(`<section class="panel"><form id="statistics-filter" class="toolbar"><label>Период<select name="period">${options({day:'Сегодня',week:'Эта неделя',month:'Этот месяц',all:'Всё время'},period)}</select></label><button type="submit">Показать</button><div class="form-error" hidden role="alert"></div></form><div class="metrics">${statsMetrics(data)}</div><h2>По точкам распространения</h2><p class="hint">Точка — место выдачи приглашения, а не адрес проживания посетителя. Период: Екатеринбург.</p>${sources.length?sources.map(source=>`<div class="bar-row"><span>${e(source.name || source.source_name || 'Точка не указана')}</span><div class="bar-track"><div class="bar-fill" style="width:${Math.max(0,Math.min(100,Number(sourceUsed(source) ?? 0)/max*100))}%"></div></div><output>${number(sourceUsed(source))}</output></div>`).join(''):empty('За выбранный период проходов нет')}</section>`,epoch))return;
  document.querySelector('#statistics-filter').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;formRun(form,()=>renderStats(epoch,new FormData(form).get('period')));});
}
async function renderSources(epoch){
  const [sources,batches]=await Promise.all([api.get('tickets/sources'),api.get('tickets/batches')]);
  const sourceNames=new Map(items(sources).map(source=>[String(source.id),source.name]));
  batches.items=items(batches).map(batch=>({...batch,source_name:batch.source_name || sourceNames.get(String(batch.source_id)),status:batch.status || (Number(batch.active)===0?'blocked':Number(batch.active)===1?'active':null)}));
  setTickets(`<section class="panel"><h2>Точки распространения</h2>${items(sources).length?`<div class="table-wrap"><table><thead><tr><th>Название</th><th>Адрес</th><th>Идентификатор</th></tr></thead><tbody>${items(sources).map(source=>`<tr><td class="wrap">${e(source.name)}</td><td class="wrap">${e(source.address || '—')}</td><td>${e(source.id)}</td></tr>`).join('')}</tbody></table></div>`:empty('Точки ещё не загружены','Их можно перенести из билетной программы через обмен данными.')}</section><section class="panel"><h2>Партии приглашений</h2>${items(batches).length?`<div class="table-wrap"><table><thead><tr><th>Партия</th><th>Точка</th><th>Приглашений</th><th>Статус</th></tr></thead><tbody>${items(batches).map(batch=>`<tr><td>${e(batch.name || batch.id)}</td><td class="wrap">${e(batch.source_name || batch.source_id || '—')}</td><td>${number(batch.ticket_count ?? batch.quantity)}</td><td>${status(batch.status)}</td></tr>`).join('')}</tbody></table></div>`:empty('Партий пока нет')}</section>`,epoch);
}
function renderExchange(){
  document.querySelector('#ticket-page').innerHTML=`<section class="panel"><h2>Обмен с билетной программой</h2><p class="muted">Перенос данных выполняется вручную через JSON-файл. Импорт сохраняет идентификаторы и сообщает о конфликтах.</p><form id="ticket-import"><div class="upload-drop"><label>Файл экспорта билетной программы<input name="file" type="file" accept=".json,application/json" required></label></div><label class="check"><input type="checkbox" required><span>Я проверил файл и хочу импортировать данные в CRM.</span></label><div class="form-error" hidden role="alert"></div><div class="actions"><button class="primary" type="submit">Импортировать JSON</button><button type="button" data-action="export-tickets">Скачать экспорт CRM</button></div></form><div id="import-result" aria-live="polite"></div></section>`;
  document.querySelector('#ticket-import').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;formRun(form,async()=>{
    const file=form.elements.file.files[0];if(!file)throw new Error('Выберите JSON-файл.');if(file.size>20*1024*1024)throw new Error('Файл больше 20 МБ. Разделите экспорт на части.');
    let payload;try{payload=objectJson(await file.text());}catch{throw new Error('Не удалось прочитать JSON-объект. Проверьте файл экспорта.');}
    const target=document.querySelector('#import-result');if(target)target.innerHTML='';
    try{
      const result=await api.post('tickets/import',payload);
      if(target?.isConnected)target.innerHTML=`<div class="alert success">Импорт завершён.${result.added!==undefined?` Добавлено: ${number(result.added)}. Без изменений: ${number(result.unchanged)}.`:result.imported!==undefined?` Записей: ${number(result.imported)}.`:''}</div>`;
    }catch(err){
      if(err.status===409 && Array.isArray(err.details) && target?.isConnected){
        const collections={sources:'Точки',batches:'Партии',tickets:'Приглашения',admissions:'Проходы',events:'События'};
        target.innerHTML=`<div class="alert">Импорт отменён. Расхождения требуют разбора; существующие записи не перезаписаны.</div><div class="table-wrap"><table><thead><tr><th>Раздел</th><th>Идентификатор</th><th>Причина</th></tr></thead><tbody>${err.details.map(c=>`<tr><td>${e(collections[c.collection] || 'Записи')}</td><td class="wrap">${e(c.id)}</td><td>Запись отличается от существующей</td></tr>`).join('')}</tbody></table></div>`;
      }
      throw err;
    }
  });});
}

async function renderDocuments(epoch,query={}){
  const data=await api.get('documents',query);const categories=Array.isArray(data.categories)?data.categories.map(c=>typeof c==='string'?c:c.name):[];
  if(!setPage(heading('Документы','Корпоративные файлы и история версий.','<button class="primary" data-action="upload-document">Загрузить документ</button>')+`<section class="panel"><form id="document-search" class="toolbar"><label class="grow">Поиск<input type="search" name="q" value="${e(query.q)}" placeholder="Название документа"></label><label>Категория<input name="category" list="document-categories" value="${e(query.category)}" placeholder="Все категории"><datalist id="document-categories">${categories.map(c=>`<option value="${e(c)}"></option>`).join('')}</datalist></label><button type="submit">Найти</button><div class="form-error" hidden role="alert"></div></form>${items(data).length?`<div class="table-wrap"><table><thead><tr><th>Документ</th><th>Версия</th><th>Обновлён</th><th>Действия</th></tr></thead><tbody>${items(data).map(doc=>`<tr><td class="wrap"><strong>${e(doc.title)}</strong><small>${e(doc.category || 'Без категории')}</small></td><td>${e(doc.current_version ?? doc.version ?? '—')}</td><td>${e(date(doc.updated_at || doc.created_at,true))}<small>${e(doc.author_name || doc.created_by_name || '')}</small></td><td><div class="actions"><button class="small" data-download="${e(doc.id)}">Скачать</button><button class="small" data-versions="${e(doc.id)}">Версии</button><button class="small" data-version-upload="${e(doc.id)}">Новая версия</button></div></td></tr>`).join('')}</tbody></table></div>`:empty(query.q || query.category?'Документы не найдены':'Документов пока нет','Загрузите первый корпоративный файл.')}${pagination(data)}</section>`,epoch))return;
  document.querySelector('#document-search').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;formRun(form,()=>renderDocuments(epoch,Object.fromEntries(new FormData(form))));});
  bindPagination(offset=>renderDocuments(epoch,{...query,offset}));
  document.querySelectorAll('[data-version-upload]').forEach(button=>button.addEventListener('click',()=>uploadDocument(items(data).find(doc=>String(doc.id)===button.dataset.versionUpload))));
}
function uploadDocument(doc={}){
  dialog(doc.id?'Загрузить новую версию':'Загрузить документ',`<form>${field('title','Название *',doc.title,'text','required maxlength="255"')}${field('category','Категория',doc.category,'text','maxlength="80"')}<div class="upload-drop"><label>Файл *<input type="file" name="file" required></label></div><p class="hint">Доступные форматы и размер проверяет сервер. Файл будет доступен только авторизованным сотрудникам.</p>${doc.id?'<p class="hint">Предыдущие версии сохранятся в истории.</p>':''}${formFooter('Загрузить')}</form>`,async form=>{
    const payload=new FormData(form);if(doc.id){payload.set('document_id',doc.id);if(doc.current_version!==undefined)payload.set('expected_version',doc.current_version);}
    await api.post('documents/upload',payload);modal.close();toast(doc.id?'Новая версия загружена.':'Документ загружен.');await navigate(false);
  });
}
async function documentVersions(id){
  const token=dialog('Версии документа','<div class="loading" role="status">Загружаем версии…</div>');
  let data;
  try{data=await api.get('documents/versions',{id});}
  catch(err){
    if(!modal.open || modalId!==token)return;
    if(err.status===401){login('Сессия истекла. Войдите снова.');return;}
    modal.innerHTML=`<header><h2 id="modal-title">Версии документа</h2><button data-action="close-modal" aria-label="Закрыть">×</button></header>${errorBox(err)}<button data-versions="${e(id)}">Повторить загрузку</button>`;return;
  }
  if(!modal.open || modalId!==token)return;
  const list=items(data);modal.innerHTML=`<header><h2 id="modal-title">Версии документа</h2><button data-action="close-modal" aria-label="Закрыть">×</button></header>${list.length?`<ol class="version-list">${list.map(version=>`<li><strong>Версия ${e(version.version ?? version.version_number ?? version.id)}</strong><p class="meta">${e(date(version.created_at,true))}${version.author_name?' · '+e(version.author_name):''}</p><p class="hint">${e(version.filename || version.original_name || '')}</p><button class="small" data-download="${e(id)}" data-download-version="${e(version.version ?? version.version_number ?? version.id)}">Скачать эту версию</button></li>`).join('')}</ol>`:empty('История версий пуста')}`;
}
async function downloadDocument(id,version){const result=await api.download('documents/download',{id,version});download(result.blob,filename(result.disposition));}

async function renderWebsite(epoch){
  const data=await api.get('website/draft');
  const content=data?.content ?? data?.draft?.content;const revision=data?.revision ?? data?.draft?.revision;
  if(!content || typeof content!=='object' || revision===undefined)throw new ApiError('Редактор не получил содержимое или номер версии от сервера. Обратитесь к администратору.');
  if(epoch!==state.epoch)return;
  const model=splitWebsiteContent(content),text=JSON.stringify(content,null,2);
  state.editor={text,savedText:text,revision,conflict:false,valid:true,inputSignature:'',savedSignature:''};
  const basicFields=websiteFields.map(item=>`<div class="${['brand','event.address','contact.vk_url'].includes(item.path)?'span-2':''}">${field(item.path,item.label+(item.required?' *':''),model.values[item.path],item.type,`${item.required?'required ':''}${item.max?`maxlength="${item.max}" `:''}${item.type==='number'?'min="0" max="1000000" step="0.01" ':''}`)}</div>`).join('');
  setPage(heading('Сайт','Данные выставки и тексты для публикации.')+`<section class="panel"><div class="website-info"><span>Черновик · версия <strong id="website-revision">${e(revision)}</strong></span><span id="website-state">Все изменения сохранены</span></div><form id="website-form"><div class="form-grid">${basicFields}</div><p class="hint">Подтверждённый период выставки: 24.11.2026–18.01.2027. Пустая стоимость означает, что цена пока не указана.</p><details class="json-details"><summary>Тексты и другие настройки сайта (JSON)</summary><label>Содержимое сайта (JSON)<textarea class="json-editor" name="content" spellcheck="false">${e(model.extraText)}</textarea></label><p class="hint">Основные данные заполните в полях выше. Здесь остаются остальные тексты и настройки.</p></details><p id="website-json-state" class="hint" role="status"></p><div class="form-error" hidden role="alert"></div><div id="website-conflict"></div><div class="actions"><button type="submit" class="primary">Сохранить черновик</button><button type="button" data-action="preview-website">Просмотреть изменения</button><button type="button" data-action="publish-website" id="publish-button">Опубликовать</button><a href="${e(rootUrl)}" target="_blank" rel="noopener">Открыть сайт</a></div></form><p class="hint">Сохранение черновика не меняет публичный сайт.</p></section>`,epoch);
  const form=document.querySelector('#website-form');
  state.editor.inputSignature=websiteInput(form).signature;state.editor.savedSignature=state.editor.inputSignature;updateEditorState();
  form.addEventListener('input',()=>syncWebsiteEditor(form));
  form.addEventListener('submit',event=>{event.preventDefault();formRun(form,async()=>{
    const input=websiteInput(form);const content=composeWebsiteContent(input.extraText,input.values);
    const submitted=JSON.stringify(content,null,2),submittedSignature=input.signature,editor=state.editor;
    try{
      const result=await api.post('website/save',{content,expected_revision:editor.revision});
      const revision=result?.revision ?? result?.draft?.revision;
      if(revision===undefined)throw new ApiError('Сервер не вернул новую версию. Перед следующим сохранением перезагрузите черновик.');
      if(state.editor!==editor)return;editor.revision=revision;editor.savedText=submitted;editor.savedSignature=submittedSignature;editor.conflict=false;
      if(websiteInput(form).signature===submittedSignature){
        const normalized=splitWebsiteContent(result.content || content);
        for(const item of websiteFields)form.elements.namedItem(item.path).value=normalized.values[item.path];
        form.elements.content.value=normalized.extraText;
        syncWebsiteEditor(form);editor.savedSignature=editor.inputSignature;editor.savedText=editor.text;
      }
      document.querySelector('#website-revision').textContent=revision;document.querySelector('#website-conflict').innerHTML='';updateEditorState();toast('Черновик сохранён.');
    }catch(err){if(err.status===409 && state.editor===editor){editor.conflict=true;showConflict();}throw err;}
  });});
}
function websiteInput(form){
  const values=Object.fromEntries(websiteFields.map(item=>[item.path,form.elements.namedItem(item.path).value]));
  const extraText=form.elements.content.value;return {values,extraText,signature:JSON.stringify({values,extraText})};
}
function syncWebsiteEditor(form){
  const input=websiteInput(form),editor=state.editor;if(!editor)return;
  editor.inputSignature=input.signature;
  try{editor.text=JSON.stringify(composeWebsiteContent(input.extraText,input.values),null,2);editor.valid=true;editor.error='';}
  catch(err){editor.valid=false;editor.error=err.message;}
  document.querySelector('#website-json-state').textContent=editor.valid?'':editor.error;
  updateEditorState();
}
function updateEditorState(){
  const editor=state.editor;
  document.querySelector('#website-state').textContent=editor.conflict?'Конфликт версий':isDirty()?'Есть несохранённые изменения':Number(editor.revision)===0?'Сначала сохраните черновик':'Все изменения сохранены';
  document.querySelector('#publish-button').disabled=isDirty() || editor.conflict || !editor.valid || Number(editor.revision)===0;
}
function showConflict(){
  document.querySelector('#website-conflict').innerHTML='<div class="alert info"><p>Черновик изменён другим сотрудником. Ваш текст сохранён в этом окне. Скачайте свою копию перед загрузкой актуальной версии.</p><div class="actions"><button type="button" data-action="download-draft">Скачать мой JSON</button><button type="button" data-action="reload-draft">Загрузить актуальный черновик</button></div></div>';updateEditorState();
}
function previewWebsite(){
  if(!state.editor.valid){toast(state.editor.error || 'Исправьте поля и JSON перед просмотром.',true);return;}
  let content;try{content=objectJson(state.editor.text);}catch(err){toast('Сначала исправьте JSON: '+err.message,true);return;}
  // Preview uses textContent only: user-authored HTML never executes inside the staff session.
  dialog('Просмотр содержимого',`<p class="muted">Текущие значения черновика. Публичная версия не изменена.</p><div id="content-preview"></div><div class="actions"><button data-action="close-modal">Закрыть</button></div>`);
  function walk(value,parent,key=''){
    if(value && typeof value==='object'){
      const section=document.createElement('section');section.className='panel';
      if(key){const heading=document.createElement('h3');heading.textContent=key;section.append(heading);}
      for(const [childKey,childValue]of Object.entries(value))walk(childValue,section,childKey);
      parent.append(section);
    }else{const row=document.createElement('p');const label=document.createElement('strong');label.textContent=key+': ';row.append(label,document.createTextNode(value===null?'Не указано':String(value)));parent.append(row);}
  }
  walk(content,document.querySelector('#content-preview'));
}
function publishWebsite(){
  if(!state.editor || isDirty() || state.editor.conflict || !state.editor.valid || Number(state.editor.revision)===0){toast('Сначала сохраните актуальный черновик.',true);return;}
  const editor=state.editor;
  dialog('Опубликовать изменения?',`<form><p>Содержимое черновика версии <strong>${e(editor.revision)}</strong> станет доступно на сайте выставки.</p><label class="check"><input type="checkbox" required><span>Содержимое проверено и готово к публикации.</span></label>${formFooter('Опубликовать')}</form>`,async()=>{
    try{
      const result=await api.post('website/publish',{expected_revision:editor.revision});
      const revision=result?.revision ?? result?.draft?.revision;
      if(revision===undefined)throw new ApiError('Публикация отправлена, но сервер не вернул номер версии. Загрузите актуальный черновик перед следующим изменением.');
      if(state.editor===editor){editor.revision=revision;document.querySelector('#website-revision').textContent=revision;updateEditorState();}
      modal.close();toast('Изменения опубликованы.');
    }
    catch(err){if(err.status===409 && state.editor===editor){editor.conflict=true;showConflict();}throw err;}
  });
}

document.addEventListener('click',async event=>{
  const button=event.target.closest('button');if(!button || button.disabled)return;
  const action=button.dataset.action;
  try {
    if(button.dataset.download){button.disabled=true;await downloadDocument(button.dataset.download,button.dataset.downloadVersion);return;}
    if(button.dataset.versions){await documentVersions(button.dataset.versions);return;}
    if(action==='close-modal'){modal.close();return;}
    if(action==='reload'){await navigate(false);return;}
    if(action==='logout'){
      if(isDirty() && !window.confirm('В редакторе есть несохранённые изменения. Выйти?'))return;
      button.disabled=true;await api.post('auth/logout',{});api.clearSession();login();return;
    }
    if(action==='add-partner')editPartner();
    if(action==='add-task')editTask();
    if(action==='add-user')editUser();
    if(action==='upload-document')uploadDocument();
    if(action==='reverse-ticket')reverseTicket();
    if(action==='next-ticket'){await navigate(false);document.querySelector('#ticket-code')?.focus();}
    if(action==='preview-website')previewWebsite();
    if(action==='publish-website')publishWebsite();
    if(action==='download-draft'){
      const text=state.editor.valid?state.editor.text:JSON.stringify({format:'kosmoport.website-editor',expected_revision:state.editor.revision,...websiteInput(document.querySelector('#website-form'))},null,2);
      download(new Blob([text],{type:'application/json;charset=utf-8'}),'kosmoport-draft.json');
    }
    if(action==='reload-draft'){
      if(!window.confirm('Загрузить серверную версию? Текст в редакторе будет заменён. Сначала скачайте свою копию, если она нужна.'))return;
      state.editor=null;await navigate(false);
    }
    if(action==='export-tickets'){
      button.disabled=true;const data=await api.get('tickets/export');download(new Blob([JSON.stringify(data,null,2)],{type:'application/json;charset=utf-8'}),`kosmoport-tickets-${new Date().toISOString().slice(0,10)}.json`);
    }
  } catch(err){
    if(err.status===401)login('Сессия истекла. Войдите снова.');
    else if(modal.open){
      let alert=modal.querySelector('.action-error');if(!alert){alert=document.createElement('div');alert.className='form-error action-error';alert.setAttribute('role','alert');modal.append(alert);}
      alert.textContent=err.message || 'Действие не выполнено.';
    }else toast(err.message || 'Действие не выполнено.',true);
  }
  finally {if(button.isConnected)button.disabled=false;}
});
window.addEventListener('hashchange',()=>navigate());
window.addEventListener('beforeunload',event=>{if(isDirty()){event.preventDefault();event.returnValue='';}});
async function start(){
  try{const data=await api.get('auth/me');if(data?.user){state.user=data.user;shell();await navigate(false);}else login();}
  catch(err){app.innerHTML=`<main id="main" class="boot" tabindex="-1"><div class="wordmark">КОСМОПОРТ</div><section class="error-page"><h1>CRM недоступна</h1>${errorBox(err)}<p class="muted">Если система ещё не настроена, обратитесь к администратору.</p><button id="retry-start" class="primary">Повторить подключение</button></section></main>`;document.querySelector('#retry-start').addEventListener('click',start);}
}
start();
