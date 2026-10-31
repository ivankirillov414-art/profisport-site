import assert from 'node:assert/strict';
import {Session,Blocked} from './harness.mjs';
export async function verify(context,report){
 const owner=context.session;let staff,admin;
 const post=(session,body)=>session.ok('users',{method:'POST',body});
 const fresh=async id=>(await owner.ok('users',{query:{include_inactive:1,limit:100}})).items.find(x=>x.id===id);
 const ready=await report.check('staff: owner creates account, edits with blank password, rejects stale revision',async()=>{
  staff=await post(owner,{name:'QA сотрудник',email:'staff.'+context.id+'@qa.invalid',password:context.owner.password,role:'reader',active:true});
  const session=new Session(context.base_url);await session.login(staff.email,context.owner.password);
  const old=staff;staff=await post(owner,{id:staff.id,name:'QA сотрудник изменён',password:'',expected_revision:staff.revision});assert.equal(staff.revision,old.revision+1);assert.equal((await session.ok('auth/me')).user.name,staff.name);
  await owner.request('users',{method:'POST',body:{id:staff.id,name:'QA stale',expected_revision:old.revision},expected:409});assert.equal((await fresh(staff.id)).name,staff.name);
  assert(!/password_hash|session_version/.test(JSON.stringify(staff)));return {blank_password_preserved:true,session_retained_for_name_edit:true,stale_revision:409};
 });
 if(!ready){await report.check('staff dependent security cases',()=>{throw new Blocked('Staff fixture unavailable');});return;}
 await report.check('staff: role, deactivation and replacement password revoke old sessions',async()=>{
  let session=new Session(context.base_url);await session.login(staff.email,context.owner.password);
  staff=await post(owner,{id:staff.id,role:'editor',expected_revision:staff.revision});await session.request('partners',{expected:401});await session.login(staff.email,context.owner.password);assert.equal((await session.ok('auth/me')).user.role,'editor');
  staff=await post(owner,{id:staff.id,active:false,expected_revision:staff.revision});await session.request('partners',{expected:401});assert.equal((await session.ok('auth/me')).user,null);await session.request('auth/login',{method:'POST',body:{email:staff.email,password:context.owner.password},expected:401});
  assert(!(await owner.ok('users',{query:{limit:100}})).items.some(x=>x.id===staff.id));assert.equal((await fresh(staff.id)).active,false);
  staff=await post(owner,{id:staff.id,active:true,expected_revision:staff.revision});await session.login(staff.email,context.owner.password);
  const replacement=context.owner.password+'-new';staff=await post(owner,{id:staff.id,password:replacement,expected_revision:staff.revision});await session.request('partners',{expected:401});assert.equal((await session.ok('auth/me')).user,null);await session.request('auth/login',{method:'POST',body:{email:staff.email,password:context.owner.password},expected:401});await session.login(staff.email,replacement);
  return {role_change_revoked:true,deactivation_revoked:true,password_change_revoked:true,new_password_login:true};
 });
 await report.check('staff: admin manages ordinary users but cannot create or edit owners',async()=>{
  admin=await post(owner,{name:'QA администратор',email:'admin.'+context.id+'@qa.invalid',password:context.owner.password,role:'admin',active:true});context.users.admin=admin;
  const session=new Session(context.base_url);await session.login(admin.email,context.owner.password);context.admin=session;
  const created=await post(session,{name:'QA создан администратором',email:'admincreated.'+context.id+'@qa.invalid',password:context.owner.password,role:'reader',active:true});await post(session,{id:created.id,name:'QA изменён администратором',expected_revision:created.revision});
  await session.request('users',{method:'POST',body:{name:'QA forbidden',email:'denied.'+context.id+'@qa.invalid',password:context.owner.password,role:'owner'},expected:403});
  const primary=await fresh(context.owner_id);await session.request('users',{method:'POST',body:{id:primary.id,name:'QA forbidden owner edit',expected_revision:primary.revision},expected:403});assert.equal((await fresh(primary.id)).name,context.owner.name);
  return {ordinary_staff_crud:true,owner_write_denied:403};
 });
 await report.check('staff: last active owner cannot be disabled or demoted',async()=>{
  const primary=await fresh(context.owner_id);
  for(const change of [{active:false},{role:'admin'}]){const r=await owner.request('users',{method:'POST',body:{id:primary.id,expected_revision:primary.revision,...change},expected:409});assert.equal(r.json.error.code,'LAST_OWNER');}
  const after=await fresh(primary.id);assert.equal(after.role,'owner');assert.equal(after.active,true);assert.equal(after.revision,primary.revision);assert.equal((await owner.ok('auth/me')).user.role,'owner');return {both_denied_before_write:true,owner_session_retained:true};
 });
 await report.check('security fixes: 72-byte password suffix and NUL fail with generic 401',async()=>{
  const password='QA'+('x'.repeat(70)),user=await post(owner,{name:'QA граница bcrypt',email:'boundary.'+context.id+'@qa.invalid',password,role:'reader',active:true});
  const session=new Session(context.base_url);await session.ok('auth/me');
  for(const candidate of [password+'suffix',password.slice(0,60)+'\0']){const r=await session.request('auth/login',{method:'POST',body:{email:user.email,password:candidate},expected:401});assert.equal(r.json.error.code,'LOGIN_FAILED');assert(!/TypeError|ValueError|password_hash|stack|trace/i.test(r.bytes.toString()));}
  await session.login(user.email,password);assert.equal((await session.ok('auth/me')).user.id,user.id);return {exact_72_bytes_login:true,suffix_rejected:true,nul_rejected:true};
 });
 await report.check('maintenance: owner-only real wrapper; anonymous/admin/all other roles denied',async()=>{
  const page=await owner.page('maintenance.php');assert.equal(page.status,200);assert.match(page.text,/name="csrf"/);assert.match(page.headers.get('cache-control'),/no-store/);
  const noCsrf=await owner.page('maintenance.php',{method:'POST',csrf:'',form:{action:'backup',maintenance_confirmed:'yes'}});assert.equal(noCsrf.status,403);assert(!noCsrf.headers.get('content-type')?.includes('zip'));
  const anonymous=new Session(context.base_url);assert.equal((await anonymous.page('maintenance.php')).status,401);
  for(const user of Object.values(context.users)){const session=user.role==='cashier'?context.cashier:user.role==='admin'?context.admin:context.role_sessions?.[user.role];if(!session)throw new Blocked('Existing role session unavailable: '+user.role);const denied=await session.page('maintenance.php');assert.equal(denied.status,403,user.role+' maintenance allowed');assert(!/name="csrf"|application\/zip|password_hash/.test(denied.text));}
  return {owner_html:true,anonymous:401,other_roles:403,csrf_rejected:403,no_backup_downloaded:true};
 });
}
