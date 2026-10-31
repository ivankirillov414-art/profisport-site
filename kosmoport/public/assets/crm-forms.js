import {objectJson} from './crm-utils.js';
export const canManageStaff = role => ['owner','admin'].includes(role);

export function recordPayload(values, record = {}) {
  const payload = {...values};
  if (record.id) {
    const revision = Number(record.revision);
    if (!Number.isInteger(revision) || revision < 1) throw new Error('Загрузите актуальную карточку: отсутствует номер версии.');
    payload.id = record.id; payload.expected_revision = revision;
  }
  return payload;
}

export function staffPayload(values, record = {}) {
  const payload = recordPayload({...values, active: values.active === true}, record);
  if (!payload.password) {
    delete payload.password;
    if (!record.id) throw new Error('Задайте пароль нового сотрудника.');
  } else if ([...payload.password].length < 12 || new TextEncoder().encode(payload.password).length > 72 || payload.password.includes('\0')) {
    throw new Error('Пароль: не менее 12 символов и не более 72 байт UTF-8.');
  }
  return payload;
}

export const websiteFields = [
  {path:'brand', label:'Название выставки', type:'text', required:true, max:120},
  {path:'event.opening_date', label:'Дата открытия', type:'date', required:true},
  {path:'event.closing_date', label:'Дата закрытия', type:'date'},
  {path:'event.venue', label:'Площадка', type:'text', max:240},
  {path:'event.address', label:'Адрес', type:'text', max:400},
  {path:'tickets.price', label:'Стоимость билета, ₽', type:'number'},
  {path:'contact.phone', label:'Телефон', type:'tel', max:100},
  {path:'contact.email', label:'Электронная почта', type:'email', max:254},
  {path:'contact.vk_url', label:'Сообщество ВКонтакте', type:'url', max:1500},
];
function getPath(object, path) {
  let current=object;
  for (const key of path.split('.')) {
    if (!current || typeof current !== 'object' || !Object.hasOwn(current,key)) return undefined;
    current=current[key];
  }
  return current;
}
function setPath(object,path,value) {
  const keys=path.split('.');let current=object;
  for(const key of keys.slice(0,-1)) {
    if(current[key]===undefined)current[key]={};
    if(!current[key] || typeof current[key]!=='object' || Array.isArray(current[key])) throw new Error(`Раздел «${key}» в JSON должен быть объектом.`);
    current=current[key];
  }
  current[keys.at(-1)]=value;
}
export function splitWebsiteContent(content) {
  const extra=JSON.parse(JSON.stringify(content));const values={};
  for(const {path} of websiteFields) {
    const value=getPath(content,path);values[path]=value===null || value===undefined ? '' : String(value);
    const keys=path.split('.');const parent=keys.length===1?extra:extra[keys[0]];
    if(parent && typeof parent==='object')delete parent[keys.at(-1)];
  }
  return {values,extraText:JSON.stringify(extra,null,2)};
}
export function composeWebsiteContent(extraText, values) {
  const content=objectJson(extraText);
  for(const field of websiteFields) {
    if(getPath(content,field.path)!==undefined)throw new Error(`Поле «${field.label}» заполняется выше. Уберите его из JSON.`);
    let value=String(values[field.path] ?? '').trim();
    if(field.required && !value)throw new Error(`Заполните поле «${field.label}».`);
    if(field.type==='date') {
      if(value && (!/^\d{4}-\d\d-\d\d$/.test(value) || Number.isNaN(Date.parse(value+'T00:00:00Z')) || new Date(value+'T00:00:00Z').toISOString().slice(0,10)!==value))throw new Error(`Проверьте поле «${field.label}».`);
      value ||= null;
    }
    if(field.path==='tickets.price') {
      if(value==='')value=null;
      else {value=Number(value.replace(',','.'));if(!Number.isFinite(value) || value<0 || value>1000000 || Math.abs(value*100-Math.round(value*100))>0.000001)throw new Error('Стоимость билета должна быть неотрицательным числом с точностью до копеек.');}
    }
    setPath(content,field.path,value);
  }
  if(content.event.closing_date && content.event.closing_date<content.event.opening_date)throw new Error('Дата закрытия не может быть раньше открытия.');
  return content;
}
