// Browser fixture, intentionally outside public/. No real credentials or records.
(() => {
  const calls=[];let user=null;let revision=1;let forceConflict=true;let admitted=false;
  const staff=[{id:'test-user',name:'Тестовый сотрудник',email:'test@example.invalid',role:'owner',active:true,revision:1}];
  const ticket={code:'TEST-001',source_name:'Тестовая точка',status:'available'};
  const content={brand:'Космопорт — тестовый черновик',event:{opening_date:'2026-11-24',closing_date:'2027-01-18',venue:'Тестовая площадка',address:'Тестовый адрес',city:'Тестовый город',hours:''},tickets:{price:null,sales_open:false,checkout_url:''},contact:{phone:'',email:'',vk_url:''},fields:{intro:'Проверка редактора. Это не публичный контент.'}};
  const tasks=[{id:'task-test',revision:1,created_by:'test-user',title:'Тестовая задача <img src=x onerror=alert(1)>',status:'todo',due_date:'2026-11-01',assignee_name:'Тестовый сотрудник'}];
  const partners=[];
  const documents=[{id:'doc-test',title:'Тестовый акт',category:'Договоры',current_version:2,updated_at:'2026-10-31T06:00:00Z',author_name:'Тестовый сотрудник'}];
  window.__uiFixture={calls,get revision(){return revision;},get admitted(){return admitted;}};
  window.fetch=async(input,init={})=>{
    const url=new URL(input,location.href);if(url.pathname!=='/kosmoport/api/index.php')throw new Error('Fixture blocks all non-test requests.');
    const route=url.searchParams.get('r');const method=init.method || 'GET';let body={};
    if(init.body instanceof FormData)body=Object.fromEntries(init.body);else if(init.body)body=JSON.parse(init.body);
    calls.push({route,method,body,csrf:init.headers?.['X-CSRF-Token']});
    const result=(data,status=200)=>new Response(JSON.stringify(status===200?{ok:true,data}:{ok:false,error:{code:'TEST',message:data}}),{status,headers:{'Content-Type':'application/json'}});
    if(route==='auth/me')return result({user,csrf:'fixture-csrf'});
    if(route==='auth/login'){user=staff[0];return result({user,csrf:'fixture-logged-in'});}
    if(route==='auth/logout'){user=null;return result({});}
    if(!user)return result('Сессия истекла',401);
    if(method!=='GET' && !init.headers?.['X-CSRF-Token'])return result('CSRF не передан',403);
    if(route==='dashboard')return result({counts:{partners:partners.length,open_tasks:tasks.length,documents:documents.length},recent_tasks:tasks});
    if(['users','partners','tasks'].includes(route)){
      const rows={users:staff,partners,tasks}[route];
      if(method==='POST'){
        const current=body.id?rows.find(row=>row.id===body.id):null;
        if(body.id && body.expected_revision!==current?.revision)return new Response(JSON.stringify({ok:false,error:{code:'REVISION_CONFLICT',message:'Карточка уже изменена.'}}),{status:409,headers:{'Content-Type':'application/json'}});
        const saved={...(current||{}),...body,id:current?.id || route+'-'+rows.length,revision:(current?.revision || 0)+1};delete saved.password;
        if(current)Object.assign(current,saved);else rows.push(saved);return result(saved);
      }
      return result({items:rows,total:rows.length,offset:0,limit:100});
    }
    if(route==='tickets/check')return body.code==='TEST-001'?(admitted?result('Приглашение уже использовано',409):result({status:'valid',code:body.code,source:ticket.source_name,consumed:false,requires_manual_adult_payment_confirmation:true})):result('Приглашение не найдено',404);
    if(route==='tickets/admit'){
      if(!body.adult_payment_confirmed)return result('Подтвердите оплату взрослого',422);
      if(admitted)return result('Приглашение уже использовано',409);
      admitted=true;return result({status:'accepted',admission_id:'admission-test',manual_adult_payment_confirmed:true,fiscal_payment_verified:false});
    }
    if(route==='tickets/reverse'){admitted=false;return result({reversed:true});}
    if(route==='tickets/statistics')return result({used_invitations:admitted?1:0,top_source:admitted?{name:'Тестовая точка'}:null,sources:admitted?[{name:'Тестовая точка',used:1}]:[]});
    if(route==='tickets/sources')return result({items:[{id:'source-test',name:'Тестовая точка',address:'Тестовый адрес'}]});
    if(route==='tickets/batches')return result({items:[]});
    if(route==='tickets/export')return result({format:'kosmoport.tickets',version:1,sources:[],batches:[],tickets:[],admissions:[],events:[]});
    if(route==='tickets/import')return result({added:0,unchanged:0,conflicts:[]});
    if(route==='documents')return result({items:documents,categories:['Договоры']});
    if(route==='documents/versions')return result({items:[{version:2,created_at:'2026-10-31T06:00:00Z',author_name:'Тестовый сотрудник',filename:'test.txt'}]});
    if(route==='documents/download')return new Response('Local test document',{headers:{'Content-Type':'text/plain','Content-Disposition':'attachment; filename="test.txt"'}});
    if(route==='documents/upload')return result({id:'doc-test'});
    if(route==='website/draft')return result({content,revision});
    if(route==='website/save'){
      if(forceConflict){forceConflict=false;revision++;return result('Черновик изменён другим сотрудником.',409);}
      if(body.expected_revision!==revision)return result('Конфликт версий',409);
      Object.assign(content,body.content);revision++;return result({content,revision});
    }
    if(route==='website/publish'){
      if(body.expected_revision!==revision)return result('Конфликт версий',409);
      revision++;return result({published:true,revision});
    }
    return result('Нет тестового обработчика',404);
  };
})();
