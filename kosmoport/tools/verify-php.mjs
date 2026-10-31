import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.KCRM_QA_PHP||'C:/Users/Izop300520251/Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/php.exe';
function walk(dir){if(!fs.existsSync(dir))return [];return fs.readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.name.startsWith('.')||e.name.includes('runtime')?[]:e.isDirectory()?walk(path.join(dir,e.name)):e.name.endsWith('.php')?[path.join(dir,e.name)]:[]);}
const files=['app','public','tools','tests'].flatMap(d=>walk(path.join(root,d))),results=[];
for(const file of files){const r=spawnSync(php,['-n','-l',file],{encoding:'utf8',windowsHide:true,timeout:20000});results.push({file:path.relative(root,file).replaceAll('\\','/'),sha256:crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex'),status:r.status===0?'pass':'fail',message:r.status===0?undefined:String(r.stderr||r.stdout||r.error?.message)});}
const report={checked_at:new Date().toISOString(),php:spawnSync(php,['-n','-r','echo PHP_VERSION;'],{encoding:'utf8',windowsHide:true}).stdout,scope:'Syntax only; no business-flow or hosting compatibility claim',results};
fs.mkdirSync(path.join(root,'tests/e2e/artifacts'),{recursive:true});fs.writeFileSync(path.join(root,'tests/e2e/artifacts/php-lint.json'),JSON.stringify(report,null,2));
console.log(JSON.stringify({files:results.length,passed:results.filter(x=>x.status==='pass').length,failed:results.filter(x=>x.status==='fail')}));process.exitCode=results.some(x=>x.status==='fail')?1:0;
