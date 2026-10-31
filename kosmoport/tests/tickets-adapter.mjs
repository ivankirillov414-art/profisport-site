import {DatabaseSync} from 'node:sqlite';
import {mkdtempSync,readFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';import {join} from 'node:path';import assert from 'node:assert/strict';import {createHash} from 'node:crypto';
const dir=mkdtempSync(join(tmpdir(),'kosmo-tickets-'));const file=join(dir,'fixture.sqlite'),out=join(dir,'snapshot.json');
try{
 const db=new DatabaseSync(file);db.exec(`PRAGMA user_version=1;
 CREATE TABLE sources(id TEXT,name TEXT,category TEXT,address TEXT,created_at TEXT);
 CREATE TABLE batches(id TEXT,source_id TEXT,name TEXT,quantity INTEGER,delivered INTEGER,created_at TEXT,active INTEGER,print_config TEXT);
 CREATE TABLE tickets(code TEXT,batch_id TEXT,used_at TEXT,scan_id TEXT);
 CREATE TABLE events(id TEXT,code TEXT,kind TEXT,at TEXT,station TEXT);
 CREATE TABLE audit(id TEXT,kind TEXT,details TEXT,at TEXT);
 CREATE TABLE settings(key TEXT,value TEXT);`);
 db.prepare('INSERT INTO settings VALUES(?,?)').run('adminPin',JSON.stringify({secret:'MUST_NOT_EXPORT'}));db.prepare('INSERT INTO settings VALUES(?,?)').run('event',JSON.stringify({startDate:'2026-11-24'}));
 db.prepare('INSERT INTO sources VALUES(?,?,?,?,?)').run('source-original','Школа','Школы','Оренбург','2026-10-31T00:00:00.000Z');db.prepare('INSERT INTO settings VALUES(?,?)').run('source:source-original',JSON.stringify({legacyCode:'SCH-001'}));
 db.prepare('INSERT INTO batches VALUES(?,?,?,?,?,?,?,?)').run('batch-original','source-original','Партия',2,2,'2026-10-31T00:00:00.000Z',1,'');
 db.prepare('INSERT INTO tickets VALUES(?,?,?,?)').run('999999999000000001','batch-original','2026-11-24T06:00:00.000Z','admit-original');db.prepare('INSERT INTO tickets VALUES(?,?,?,?)').run('999999999000000002','batch-original',null,null);
 db.prepare('INSERT INTO events VALUES(?,?,?,?,?)').run('admit-original','999999999000000001','admit','2026-11-24T06:00:00.000Z','Вход 1');db.close();
 const hash=()=>createHash('sha256').update(readFileSync(file)).digest('hex');const before=hash();process.argv=[process.argv[0],'export-snapshot.mjs',file,out];await import('../integrations/ticket-app/export-snapshot.mjs');
 assert.equal(hash(),before);const text=readFileSync(out,'utf8');assert(!text.includes('MUST_NOT_EXPORT'));const snapshot=JSON.parse(text);assert.equal(snapshot.tickets[0].code,'999999999000000001');assert.equal(snapshot.sources[0].id,'source-original');assert.equal(snapshot.sources[0].legacy_metadata.legacyCode,'SCH-001');assert.equal(snapshot.admissions[0].id,'admit-original');assert.equal(snapshot.admissions[0].manual_adult_payment_confirmed,null);assert.equal(snapshot.admissions[0].fiscal_payment_verified,false);assert.equal(snapshot.batches[0].print_config.startDate,'2026-11-24');
 console.log('TICKETS_ADAPTER_PASS: original database hash unchanged; IDs/metadata/dates preserved; PIN excluded; no fiscal claim');
}finally{rmSync(dir,{recursive:true,force:true});}
