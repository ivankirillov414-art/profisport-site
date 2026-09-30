'use strict';
const assert=require('node:assert/strict');
const {create}=require('../autosave.js');
function fixture(){
 let draft='first',saved='',version=1,seq=0;
 const timers=new Map(),requests=[],events=[];
 const auto=create({
  snapshot:()=>draft===saved?null:{draft,version},
  save:sent=>new Promise((resolve,reject)=>requests.push({sent,resolve,reject})),
  acknowledge:(result,sent)=>{saved=sent.draft;version=result.version;},
  notify:(event)=>events.push(event),
  setTimer:fn=>{timers.set(++seq,fn);return seq;},clearTimer:id=>timers.delete(id)
 });
 return {auto,timers,requests,events,edit(value){draft=value;auto.schedule();},get version(){return version;},get draft(){return draft;}};
}
(async()=>{
 const f=fixture();f.edit('a');f.edit('b');assert.equal(f.timers.size,1,'Debounce keystrokes');
 const first=f.auto.flush();await Promise.resolve();assert.equal(f.requests.length,1);
 f.edit('new while saving');assert.equal(f.auto.flush(),first,'No overlapping write');
 assert.equal(f.requests[0].sent.draft,'b');f.requests[0].resolve({version:2});await first;
 assert.equal(f.draft,'new while saving','Response must not replace newer edits');
 assert.equal(f.timers.size,1,'Newer edits scheduled');
 const next=f.auto.flush();await Promise.resolve();assert.deepEqual(f.requests[1].sent,{draft:'new while saving',version:2});
 f.requests[1].resolve({version:3});await next;assert.equal(f.timers.size,0,'Clean state stops writes');
 await f.auto.flush();assert.equal(f.requests.length,2,'No empty revisions');
 f.edit('conflicting edit');const failed=f.auto.flush();await Promise.resolve();f.requests[2].reject(Error('409 conflict'));assert.equal(await failed,false);
 f.edit('still local');await f.auto.flush();assert.equal(f.requests.length,3,'Conflict suspends automatic writes');assert.equal(f.timers.size,0);
 assert.equal(f.draft,'still local');assert.equal(f.version,3);
 f.auto.reset();const retry=f.auto.flush();await Promise.resolve();f.requests[3].resolve({version:4});await retry;
 f.edit('before switching site');const switching=f.auto.flush();await Promise.resolve();f.edit('during request');
 const settled=f.auto.settle();f.requests[4].resolve({version:5});await switching;await settled;
 assert.equal(f.timers.size,0,'Site switch/manual action cancels successor after waiting');
 assert.equal(f.version,5);assert.equal(f.draft,'during request');
 const g=fixture();const offline=g.auto.flush();await Promise.resolve();g.requests[0].reject(Error('offline'));await offline;
 assert.equal(g.events.at(-1),'error');assert.equal(g.timers.size,0,'No retry loop after network error');
 console.log('Autosave: debounce, concurrent edits, version ordering, conflict, recovery, navigation and offline checks passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
