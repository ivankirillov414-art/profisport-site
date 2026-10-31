// Read-only adapter. Run with the Node 24 runtime bundled with the offline app.
import {DatabaseSync} from 'node:sqlite';
import {writeFileSync,existsSync,realpathSync} from 'node:fs';
import {resolve} from 'node:path';
import {createHash} from 'node:crypto';
const [input,output]=process.argv.slice(2);
if(!input||!output)throw new Error('Usage: node export-snapshot.mjs BACKUP.sqlite NEW-snapshot.json');
if(existsSync(output)||resolve(input)===resolve(output))throw new Error('Output must be a NEW file; existing files are never overwritten');
const db=new DatabaseSync(realpathSync(input),{readOnly:true});
try{
 if(db.prepare('PRAGMA quick_check').get().quick_check!=='ok')throw new Error('SQLite integrity check failed');
 if(db.prepare('PRAGMA user_version').get().user_version>1)throw new Error('Unsupported offline schema version');
 const eventRow=db.prepare("SELECT value FROM settings WHERE key='event'").get();
 const event=eventRow?JSON.parse(eventRow.value):{};
 const sources=db.prepare('SELECT * FROM sources ORDER BY id').all().map(s=>{
  const meta=db.prepare('SELECT value FROM settings WHERE key=?').get('source:'+s.id);
  return {...s,...(meta?{legacy_metadata:JSON.parse(meta.value)}:{})};
 });
 const batches=db.prepare('SELECT * FROM batches ORDER BY id').all().map(b=>({...b,active:b.active??1,print_config:b.print_config?JSON.parse(b.print_config):event}));
 const tickets=db.prepare('SELECT * FROM tickets ORDER BY code').all();
 const events=db.prepare('SELECT * FROM events ORDER BY at,id').all();
 const audit=db.prepare('SELECT * FROM audit ORDER BY at,id').all();
 const ticketMap=new Map(tickets.map(t=>[t.code,t]));
 const byCode=new Map();for(const e of events){if(!byCode.has(e.code))byCode.set(e.code,[]);byCode.get(e.code).push(e);}
 const admissions=events.filter(e=>e.kind==='admit').map(e=>{
  const ticket=ticketMap.get(e.code);
  // Original undo records have no target id: the next undo before next admit closes it.
  const later=byCode.get(e.code).filter(x=>x.at>=e.at&&x.id!==e.id);
  const nextAdmission=later.find(x=>x.kind==='admit');
  const undo=later.find(x=>x.kind==='undo'&&(!nextAdmission||x.at<=nextAdmission.at));
  const live=ticket?.scan_id===e.id&&ticket.used_at;
  return {id:e.id,code:e.code,admitted_at:e.at,reversed_at:live?null:(undo?.at||e.at),origin:'offline',manual_adult_payment_confirmed:null,fiscal_payment_verified:false,legacy_event:e,legacy_reversal_inferred:!live&&!undo};
 });
 const admissionMap=new Map(admissions.map(a=>[a.id,a]));
 for(const t of tickets){const a=admissionMap.get(t.scan_id);if(t.used_at&&(!a||a.code!==t.code||a.reversed_at))throw new Error('Unresolved active admission '+t.code);}
 const history=[...events.map(e=>({id:e.id,kind:'offline.event',record:e})),...audit.map(a=>({id:'audit:'+createHash('sha256').update(a.id).digest('hex').slice(0,56),kind:'offline.audit',original_id:a.id,record:a}))];
 const doc={format:'kosmoport.tickets',version:1,exported_at:new Date().toISOString(),origin:'offline-app-1',sources,batches,tickets,admissions,events:history};
 writeFileSync(output,JSON.stringify(doc,null,2),{encoding:'utf8',flag:'wx'});
 console.log(JSON.stringify({output,sources:sources.length,batches:batches.length,tickets:tickets.length,admissions:admissions.length,events:history.length}));
}finally{db.close();}
