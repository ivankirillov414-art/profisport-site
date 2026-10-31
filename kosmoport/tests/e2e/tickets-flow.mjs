import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import {Session,Blocked} from './harness.mjs';
import {freePort,startApp,stopApp} from './runtime.mjs';
const empty=()=>({format:'kosmoport.tickets',version:1,sources:[],batches:[],tickets:[],admissions:[],events:[]});
export async function verify(context,report){
 const owner=context.session,source='qa-source-'+context.id,batch='qa-batch-'+context.id,prefix='86'+Date.now().toString(),code=n=>prefix+String(n).padStart(3,'0');
 const doc=empty();doc.sources=[{id:source,name:'QA Точка распространения',category:'QA',address:'QA Оренбург'}];doc.batches=[{id:batch,source_id:source,active:1,name:'QA партия',ticket_count:3,print_config:{startDate:'2020-01-01',endDate:'2099-12-31'}}];doc.tickets=[0,1,2].map(n=>({code:code(n),batch_id:batch,used_at:null,scan_id:null}));
 const imported=await report.check('tickets: real versioned import, repeat and all-or-nothing conflict',async()=>{
  const result=await owner.ok('tickets/import',{method:'POST',body:doc});assert.equal(result.added,5);
  const repeat=await owner.ok('tickets/import',{method:'POST',body:doc});assert.equal(repeat.added,0);assert.equal(repeat.unchanged,5);
  const conflict=structuredClone(doc);conflict.sources[0].name='QA conflicting name';await owner.request('tickets/import',{method:'POST',body:conflict,expected:409});
  const exported=await owner.ok('tickets/export');assert.equal(exported.sources.find(x=>x.id===source).name,doc.sources[0].name);context.ticket_code=code(2);context.ticket_source=source;context.ticket_batch=batch;return {imported:5,repeat_added:0,conflict:409};
 });
 if(!imported){await report.check('dependent ticket scenarios',()=>{throw new Blocked('Ticket import unavailable');});return;}
 await report.check('tickets: check does not consume; manual confirmation, replay and duplicate',async()=>{
  const check=await owner.ok('tickets/check',{method:'POST',body:{code:code(0)}});assert.equal(check.consumed,false);assert.equal(check.status,'valid');
  await owner.request('tickets/admit',{method:'POST',body:{code:code(0),adult_payment_confirmed:false,request_id:crypto.randomUUID()},expected:422});
  assert.equal((await owner.ok('tickets/export')).tickets.find(t=>t.code===code(0)).used_at,null);
  const input={code:code(0),adult_payment_confirmed:true,request_id:crypto.randomUUID()};const accepted=await owner.ok('tickets/admit',{method:'POST',body:input});assert(accepted.admission_id);assert.equal(accepted.fiscal_payment_verified,false);
  const retry=await owner.ok('tickets/admit',{method:'POST',body:input});assert.equal(retry.admission_id,accepted.admission_id);
  await owner.request('tickets/admit',{method:'POST',body:{...input,request_id:crypto.randomUUID()},expected:409});
  const used=await owner.ok('tickets/export');assert.equal(used.admissions.filter(a=>a.code===code(0)&&!a.reversed_at).length,1);
  await owner.ok('tickets/reverse',{method:'POST',body:{admission_id:accepted.admission_id,reason:'QA ошибочный проход'}});
  await owner.request('tickets/reverse',{method:'POST',body:{admission_id:accepted.admission_id,reason:'QA duplicate reversal'},expected:409});
  assert.equal((await owner.ok('tickets/check',{method:'POST',body:{code:code(0)}})).consumed,false);
  const audit=await owner.ok('audit');assert(audit.items.some(x=>x.action==='ticket.admit'));assert(audit.items.some(x=>x.action==='ticket.reverse'));
  return {manual_confirmation_only:true,real_fiscal_payment:false,one_admission:true,reversal_audited:true};
 });
 await report.check('tickets: cashier can check/admit while manager-only import/reversal stays denied',async()=>{
  if(!context.cashier)throw new Blocked('Cashier fixture account missing');
  await context.cashier.ok('tickets/check',{method:'POST',body:{code:code(2)}});
  const accepted=await context.cashier.ok('tickets/admit',{method:'POST',body:{code:code(2),adult_payment_confirmed:true,request_id:crypto.randomUUID()}});
  await context.cashier.request('tickets/reverse',{method:'POST',body:{admission_id:accepted.admission_id,reason:'QA forbidden'},expected:403});
  await context.cashier.request('tickets/import',{method:'POST',body:empty(),expected:403});
  await owner.ok('tickets/reverse',{method:'POST',body:{admission_id:accepted.admission_id,reason:'QA cleanup fixture admission'}});
 });
 await report.check('tickets: two actual PHP workers race to admit once',async()=>{
  const second={...context,base_url:'http://127.0.0.1:'+await freePort()+'/kosmoport/'};const child=await startApp(second);
  try{const session=new Session(second.base_url);await session.login(context.owner.email,context.owner.password);
   const responses=await Promise.all([owner,session].map(s=>s.request('tickets/admit',{method:'POST',body:{code:code(1),adult_payment_confirmed:true,request_id:crypto.randomUUID()}})));
   assert.deepEqual(responses.map(r=>r.status).sort(),[200,409]);const data=await owner.ok('tickets/export');assert.equal(data.admissions.filter(a=>a.code===code(1)&&!a.reversed_at).length,1);return {php_workers:2,active_admissions:1,statuses:[200,409]};
  }finally{await stopApp(child);}
 });
 await report.check('tickets: day boundaries in Asia/Yekaterinburg, week/month/all and source attribution',async()=>{
  const timezone=empty(),sid='qa-tz-'+context.id,bid='qa-tz-batch-'+context.id;
  timezone.sources=[{id:sid,name:'QA Граница суток',category:'QA',address:'Точка распространения'}];timezone.batches=[{id:bid,source_id:sid,active:1,name:'QA TZ',print_config:{}}];
  const times=['2026-10-30T18:59:59.000Z','2026-10-30T19:00:00.000Z','2026-10-31T18:59:59.000Z','2026-10-31T19:00:00.000Z'];
  for(let n=0;n<times.length;n++){const id='qa-tz-admit-'+context.id+'-'+n;timezone.tickets.push({code:code(10+n),batch_id:bid,used_at:times[n],scan_id:id});timezone.admissions.push({id,code:code(10+n),admitted_at:times[n],reversed_at:null,origin:'qa_fixture'});}
  await owner.ok('tickets/import',{method:'POST',body:timezone});
  const day=await owner.ok('tickets/statistics',{query:{period:'day',date:'2026-10-31'}});assert.equal(day.timezone,'Asia/Yekaterinburg');assert.equal(day.from,'2026-10-30T19:00:00.000Z');assert.equal(day.to,'2026-10-31T19:00:00.000Z');assert.equal(day.sources.find(x=>x.id===sid).used,2);
  for(const period of ['week','month','all']){const stats=await owner.ok('tickets/statistics',{query:{period,date:'2026-10-31'}});assert(stats.used_invitations>=2);assert(stats.sources.some(x=>x.id===sid));}
  await owner.request('tickets/statistics',{query:{period:'day',date:'2026-02-30'},expected:422});return {inside_local_day:2,outside_local_day:2,source_is_distribution_point:true};
 });
}
