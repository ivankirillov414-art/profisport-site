import fs from 'node:fs';
import path from 'node:path';
import {pathToFileURL} from 'node:url';
import {Report,Blocked} from '../tests/e2e/harness.mjs';
import {root,createRun,startApp,stopApp} from '../tests/e2e/runtime.mjs';
import {sourceSnapshot,snapshotChanges} from '../tests/e2e/source-snapshot.mjs';
const report=new Report(path.join(root,'tests/e2e/artifacts/integrated.json'));
const required=['app/bootstrap.php','public/api/index.php','public/setup.php'];
let context,server;const source=sourceSnapshot();
try {
 const ready=await report.check('real entry points available',()=>{const missing=required.filter(p=>!fs.existsSync(path.join(root,p)));if(missing.length)throw new Blocked('Not implemented yet: '+missing.join(', '));return {entry_points:required};});
 if(ready){context=await createRun();server=await startApp(context);
  const file=path.join(root,'tests/e2e/api-flow.mjs');
  if(fs.existsSync(file)){const {verifyApi}=await import(pathToFileURL(file).href);await verifyApi(context,report);
   for(const name of ['backup-flow.mjs','browser-sections.mjs']){const module=path.join(root,'tests/e2e',name);if(fs.existsSync(module)&&context.owner_id){const {verify}=await import(pathToFileURL(module).href);await verify(context,report);}}
  }
  else await report.check('integrated API cases',()=>{throw new Blocked('QA API suite is being adapted to actual core setup fields');});
 }
} catch(error) {await report.check('integration environment',()=>{throw error;});}
finally {await stopApp(server);const end=sourceSnapshot(),changed=snapshotChanges(source,end);fs.writeFileSync(path.join(root,'tests/e2e/artifacts/integrated-source-hashes.json'),JSON.stringify({start:source,end,changed},null,2));await report.check('all deployable sources unchanged during integrated test',()=>{if(changed.length)throw new Blocked('Runtime source changed; fresh run required: '+changed.join(', '));return {files:Object.keys(source).length,changed:[]};});report.save({fixture_database:context?.database.name,fixture_id:context?.id,production:false});process.exitCode=report.exitCode;}
