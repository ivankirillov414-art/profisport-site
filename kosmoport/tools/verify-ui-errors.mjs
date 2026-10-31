import path from 'node:path';
import {Report} from '../tests/e2e/harness.mjs';
import {root,createRun,startApp,stopApp} from '../tests/e2e/runtime.mjs';
import {verifyBrowserErrors} from '../tests/e2e/browser-errors.mjs';
const report=new Report(path.join(root,'tests/e2e/artifacts/ui-errors.json'));let child;
try{const fixture=await createRun();child=await startApp(fixture);await verifyBrowserErrors(fixture,report);}
catch(error){await report.check('UI error harness environment',()=>{throw error;});}
finally{await stopApp(child);report.save({kind:'UI error-injection fixtures; not an integrated acceptance result'});process.exitCode=report.exitCode;}
