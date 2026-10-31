import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {Report,fixture,Session,Blocked} from '../tests/e2e/harness.mjs';
import {root,freePort,startApp,stopApp} from '../tests/e2e/runtime.mjs';
import {verify} from '../tests/e2e/browser-sections.mjs';
const report=new Report(path.join(root,'tests/e2e/artifacts/browser.json'));let child,context;
try{
 const previous=JSON.parse(fs.readFileSync(path.join(root,'tests/e2e/artifacts/integrated.json'),'utf8'));
 const id=process.argv.find(x=>x.startsWith('--fixture-id='))?.slice(13)||previous.fixture_id;
 if(!/^[a-z0-9_]+$/.test(id||''))throw new Blocked('A completed disposable backend fixture is required');
 context=fixture(path.join(root,'tests/e2e/.runtime/runs',id,'fixture.json'));
 context.base_url='http://127.0.0.1:'+await freePort()+'/kosmoport/';child=await startApp(context);
 const session=new Session(context.base_url);const login=await session.login(context.owner.email,context.owner.password);context.owner_id=login.user.id;
 const partners=await session.ok('partners',{query:{q:id}});context.partner=partners.items.find(x=>x.name.includes(id));if(!context.partner)throw new Blocked('Backend partner fixture missing');
 const tasks=await session.ok('tasks',{query:{partner_id:context.partner.id}});context.task=tasks.items[0];
 const documents=await session.ok('documents',{query:{q:id}});const doc=documents.items.find(x=>x.title.includes(id));if(!doc)throw new Blocked('Backend document fixture missing');
 const versions=await session.ok('documents/versions',{query:{id:doc.id}});context.document={id:doc.id,title:doc.title};
 for(const version of [1,2]){const metadata=versions.items.find(x=>Number(x.version)===version);if(!metadata)throw new Blocked('Immutable version '+version+' missing');const response=await session.request('documents/download',{query:{id:doc.id,version},expected:200});const sha=crypto.createHash('sha256').update(response.bytes).digest('hex');if(metadata.sha256&&metadata.sha256!==sha)throw Error('Source document metadata hash mismatch');context.document['version'+version+'_sha256']=sha;}
 const users=await session.ok('users');context.users=Object.fromEntries(users.items.map(x=>[x.role,x]));
 context.ticket_batch='qa-batch-'+id;const batches=await session.ok('tickets/batches');if(!batches.items.some(x=>x.id===context.ticket_batch))throw new Blocked('Backend ticket batch missing');
 await verify(context,report);
}catch(error){await report.check('browser environment',()=>{throw error;});}
finally{await stopApp(child);report.save({fixture_id:context?.id,fixture_database:context?.database.name,production:false,scope:'Real browser to actual PHP/API/MySQL using the completed disposable backend fixture'});process.exitCode=report.exitCode;}
