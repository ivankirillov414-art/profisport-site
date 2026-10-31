import {test} from 'node:test';
import assert from 'node:assert/strict';
import {localBase,fixture} from './harness.mjs';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
test('mutation harness refuses production hosts, credentials and implicit ports',()=>{
 for(const url of ['https://example.com/','http://example.com:8080/','http://127.0.0.1/','http://u:p@localhost:8080/','http://localhost:8080/?r=delete'])assert.throws(()=>localBase(url));
 assert.equal(localBase('http://127.0.0.1:48213/kosmoport').href,'http://127.0.0.1:48213/kosmoport/');
});
test('fixture loader refuses unmarked or business database names',()=>{
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'kcrmqa-harness-')),file=path.join(dir,'fixture.json');
 try { for(const name of ['production','crm','kcrm_users']){fs.writeFileSync(file,JSON.stringify({marker:'KCRM_QA_DISPOSABLE_V1',environment:'test',base_url:'http://127.0.0.1:48123/kosmoport/',database:{name}}));assert.throws(()=>fixture(file));} }
 finally {fs.rmSync(file,{force:true});fs.rmdirSync(dir);}
});
