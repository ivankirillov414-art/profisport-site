import assert from 'node:assert/strict';
const base=process.argv[2] || 'http://127.0.0.1:8769/kosmoport/';
const target=new URL(base);
if(!['127.0.0.1','localhost','[::1]'].includes(target.hostname) || target.protocol!=='http:')throw new Error('Only loopback fixture is allowed.');
for(const [path,type] of [['crm/','text/html'],['assets/crm.js','application/javascript'],['assets/crm-api.js','application/javascript'],['assets/crm-utils.js','application/javascript'],['assets/crm-forms.js','application/javascript'],['assets/crm.css','text/css']]){
 const response=await fetch(new URL(path,target));const content=await response.text();
 assert.equal(response.status,200,path);assert.ok(response.headers.get('content-type').startsWith(type),path+' MIME');assert.ok(content.length>100,path+' content');
 if(path==='crm/')assert.ok(content.includes('Локальный тестовый стенд') && !content.includes('<?php'));
 console.log('PASS fixture HTTP '+path);
}
const forbidden=await fetch(new URL('../app/bootstrap.php',target));assert.equal(forbidden.status,404);
console.log('PASS fixture denies private paths; this is a transport check, not browser/production QA.');
