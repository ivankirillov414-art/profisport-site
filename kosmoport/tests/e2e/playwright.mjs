import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {Blocked} from './harness.mjs';
const require=createRequire(import.meta.url);
export function loadPlaywright(){
 try{return require('playwright');}catch(error){if(error.code!=='MODULE_NOT_FOUND')throw error;}
 const candidates=[process.env.KCRM_QA_PLAYWRIGHT,path.join(process.env.USERPROFILE||process.env.HOME||'','.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')].filter(Boolean);
 for(const candidate of candidates)if(fs.existsSync(candidate))return require(candidate);
 throw new Blocked('Playwright unavailable. Set NODE_PATH or KCRM_QA_PLAYWRIGHT to an existing local installation; no browser tests were run.');
}
