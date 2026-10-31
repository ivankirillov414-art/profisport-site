import assert from 'node:assert/strict';
import fs from 'node:fs';
import {recordPayload,staffPayload,splitWebsiteContent,composeWebsiteContent,canManageStaff} from '../../public/assets/crm-forms.js';
let passed=0;function test(name,fn){fn();passed++;console.log('PASS '+name);}
test('partner/task update includes numeric loaded revision without mutating input',()=>{
 const old=Object.freeze({id:'test-id',revision:4,name:'Старое'}),values=Object.freeze({name:'Моё изменение'});
 assert.deepEqual(recordPayload(values,old),{name:'Моё изменение',id:'test-id',expected_revision:4});assert.equal(old.revision,4);
});
test('missing update revision never turns into a blind write',()=>{
 for(const revision of [undefined,null,0,-1,'oops',1.1])assert.throws(()=>recordPayload({title:'Тест'},{id:'t',revision}));
 assert.deepEqual(recordPayload({title:'Новая'}),{title:'Новая'});
});
test('optional staff password omitted on edit; active remains a JSON boolean',()=>{
 const p=staffPayload({name:'Тест',email:'test@example.invalid',role:'reader',active:false,password:''},{id:'u',revision:2});
 assert.equal(p.active,false);assert.equal(p.expected_revision,2);assert.equal(Object.hasOwn(p,'password'),false);
});
test('new staff password enforces the actual core length and UTF-8 limit',()=>{
 assert.throws(()=>staffPayload({active:true,password:''}));assert.throws(()=>staffPayload({password:'short'}));
 assert.throws(()=>staffPayload({password:'я'.repeat(37)}));assert.throws(()=>staffPayload({password:'a'.repeat(73)}));
 assert.equal(staffPayload({password:'Я'.repeat(12),active:true}).password,'Я'.repeat(12));
});
test('only owner/admin have staff settings in the UI',()=>{
 for(const role of ['owner','admin'])assert.equal(canManageStaff(role),true);
 for(const role of ['manager','cashier','editor','document_manager','reader',null,undefined])assert.equal(canManageStaff(role),false);
});
const content=JSON.parse(fs.readFileSync(new URL('../../app/website/default-content.json',import.meta.url),'utf8'));
test('split and recombine the actual site content without losing other text',()=>{
 const original=JSON.stringify(content),split=splitWebsiteContent(content);
 assert.deepEqual(composeWebsiteContent(split.extraText,split.values),content);assert.equal(JSON.stringify(content),original);
 assert.equal(split.values['event.opening_date'],'2026-11-24');assert.equal(split.values['event.closing_date'],'2027-01-18');
 const extra=JSON.parse(split.extraText);assert.equal(Object.hasOwn(extra,'brand'),false);assert.equal(Object.hasOwn(extra.event,'opening_date'),false);assert.ok(extra.fields);
});
test('ordinary fields update nested content and leave JSON-owned fields intact',()=>{
 const split=splitWebsiteContent(content),values={...split.values,brand:'Тестовое название','event.venue':'Тестовая площадка','contact.phone':'+70000000000','tickets.price':'250,50'};
 const full=composeWebsiteContent(split.extraText,values);assert.equal(full.brand,'Тестовое название');assert.equal(full.event.venue,'Тестовая площадка');assert.equal(full.contact.phone,'+70000000000');assert.equal(full.tickets.price,250.5);assert.deepEqual(full.fields,content.fields);
});
test('empty price is unknown, zero is a real explicit price, invalid values rejected',()=>{
 const split=splitWebsiteContent(content),run=price=>composeWebsiteContent(split.extraText,{...split.values,'tickets.price':price}).tickets.price;
 assert.equal(run(''),null);assert.equal(run('0'),0);for(const value of ['-1','NaN','2.001','1000001'])assert.throws(()=>run(value));
});
test('invalid and reversed calendar dates cannot be silently normalized',()=>{
 const split=splitWebsiteContent(content);
 for(const date of ['2026-02-30','2026-99-12','not-date'])assert.throws(()=>composeWebsiteContent(split.extraText,{...split.values,'event.opening_date':date}));
 assert.throws(()=>composeWebsiteContent(split.extraText,{...split.values,'event.closing_date':'2026-01-01'}));
});
test('duplicate basic fields and invalid JSON are rejected without editing existing values',()=>{
 const split=splitWebsiteContent(content),before=JSON.stringify(split.values);
 assert.throws(()=>composeWebsiteContent('{',split.values));assert.throws(()=>composeWebsiteContent('[]',split.values));
 const extra=JSON.parse(split.extraText);extra.brand='Скрытая подмена';assert.throws(()=>composeWebsiteContent(JSON.stringify(extra),split.values));
 assert.equal(JSON.stringify(split.values),before);
});
test('owned CRM interface contains no arrow glyphs',()=>{
 for(const file of ['index.php','../assets/crm.js','../assets/crm-api.js','../assets/crm-utils.js','../assets/crm-forms.js','../assets/crm.css']){
  const source=fs.readFileSync(new URL('../../public/crm/'+file,import.meta.url),'utf8');assert.doesNotMatch(source,/[\u2190-\u21ff\u27f0-\u27ff]/u,file);
 }
});
console.log(`${passed} form/schema checks passed. No backend or production records changed.`);
