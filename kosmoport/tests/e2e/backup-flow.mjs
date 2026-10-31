import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {Session,Blocked} from './harness.mjs';
import {root,phpRun,phpResult,writeConfiguration,freePort,startApp,stopApp} from './runtime.mjs';
const snapshot=c=>JSON.parse(phpRun(path.join(root,'tests/e2e/fixture.php'),[c.fixture_file,'snapshot'])).tables;
async function target(context,suffix){
 const database={...context.database,name:context.database.name+'_'+suffix};phpRun(path.join(root,'tests/e2e/db-admin.php'),['create',database.name]);
 const configuration={...context.configuration,database,storage_path:path.join(context.directory,suffix+'-storage')};
 fs.mkdirSync(configuration.storage_path,{recursive:true});
 const config_file=path.join(context.directory,suffix+'-config.php');writeConfiguration(config_file,configuration);
 const fixture={...context,database,configuration,config_file,base_url:'http://127.0.0.1:'+await freePort()+'/kosmoport/'};delete fixture.session;delete fixture.cashier;
 fixture.fixture_file=path.join(context.directory,suffix+'-fixture.json');fs.writeFileSync(fixture.fixture_file,JSON.stringify(fixture,null,2));
 phpRun(path.join(root,'tests/e2e/fixture.php'),[fixture.fixture_file,'migrate']);return fixture;
}
export async function verify(context,report){
 const archive=path.join(context.directory,'full-backup.zip');let restored;
 const created=await report.check('backup: actual application tables and all document versions produce verified ZIP',async()=>{
  if(!context.document)throw new Blocked('Document upload must pass before full-data backup acceptance');
  const output=JSON.parse(phpRun(path.join(root,'tools/backup.php'),['--config='+context.config_file,'--output='+archive]));assert.equal(output.ok,true);
  const verify=JSON.parse(phpRun(path.join(root,'tools/backup.php'),['--verify='+archive]));assert.equal(verify.ok,true);assert(output.tables>=10);context.backup_sha256=crypto.createHash('sha256').update(fs.readFileSync(archive)).digest('hex');return {tables:output.tables,entries:output.entries,sha256:context.backup_sha256};
 });
 if(!created)return;
 const valid=await report.check('restore: actual trusted schema into separate empty database, rows and file versions match',async()=>{
  restored=await target(context,'restore');const before=snapshot(context);
  const output=JSON.parse(phpRun(path.join(root,'tools/restore.php'),['--config='+restored.config_file,'--input='+archive,'--target-db='+restored.database.name,'--confirm-empty-target='+restored.database.name]));assert.equal(output.ok,true);
  const after=snapshot(restored);assert.deepEqual(Object.keys(after),Object.keys(before));for(const table of Object.keys(before))if(table!=='kcrm_migrations')assert.deepEqual(after[table],before[table],table+' restored rows differ');
  return {source:context.database.name,target:restored.database.name,tables:Object.keys(after).length,data_hashes_match:true};
 });
 if(valid)await report.check('restore: authenticated download of both immutable document versions',async()=>{
  const child=await startApp(restored);try{const session=new Session(restored.base_url);await session.login(context.owner.email,context.owner.password);
   for(const [version,sha]of [[1,context.document.version1_sha256],[2,context.document.version2_sha256]]){const response=await session.request('documents/download',{query:{id:context.document.id,version},expected:200});assert.equal(crypto.createHash('sha256').update(response.bytes).digest('hex'),sha);}
   return {versions:[1,2],authorized_real_downloads:true};
  }finally{await stopApp(child);}
 });
 await report.check('restore: corrupt archive is rejected before changing empty target',async()=>{
  const empty=await target(context,'corrupt_target'),before=snapshot(empty),broken=path.join(context.directory,'tampered-backup.zip');phpRun(path.join(root,'tests/e2e/tamper.php'),[archive,broken]);
  const result=phpResult(path.join(root,'tools/restore.php'),['--config='+empty.config_file,'--input='+broken,'--target-db='+empty.database.name,'--confirm-empty-target='+empty.database.name]);assert.notEqual(result.status,0);assert.match(result.stderr,/контрольн|целостн|checksum|поврежд|хеш|sha/i);assert.deepEqual(snapshot(empty),before);return {rejected_for_integrity:true,target_unchanged:true};
 });
 if(valid)await report.check('restore: nonempty and wrong-name targets rejected without writes',async()=>{
  const before=snapshot(restored);for(const name of [restored.database.name,context.database.name]){
   const result=phpResult(path.join(root,'tools/restore.php'),['--config='+restored.config_file,'--input='+archive,'--target-db='+name,'--confirm-empty-target='+name]);assert.notEqual(result.status,0);assert.deepEqual(snapshot(restored),before);
  }return {nonempty_rejected:true,database_identity_checked:true};
 });
}
