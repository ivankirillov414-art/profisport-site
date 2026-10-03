import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../admin/import-upload.js',import.meta.url),'utf8');
function harness({failUpload=false,shortAck=false,badFinal=false}={}){
  const nodes=new Map(),calls=[],forms=[];
  class Element{
    constructor(){this.files=[];this.checked=false;this.disabled=false;this.events={};this.style={};this.clicks=0;}
    set innerHTML(html){for(const match of html.matchAll(/<[^>]+id="([^"]+)"[^>]*>/g)){const e=new Element();e.checked=/\schecked(?:[\s>])/.test(match[0]);e.multiple=/\smultiple(?:[\s>])/.test(match[0]);e.tag=match[0];nodes.set(match[1],e);}}
    addEventListener(name,fn){this.events[name]=fn;}
    insertBefore(e){nodes.set(e.id,e);}
    click(){this.clicks++;return this.events.click?.()??this.onclick?.();}
  }
  for(const id of ['catalog','refreshImports','run1cImportBtn'])nodes.set(id,new Element());
  class Form {constructor(){this.items=[];}append(key,value){this.items.push([key,value]);}}
  const fetch=async(url,opt)=>{
    calls.push(url);let data={ok:true};
    if(url.includes('action=start'))data.batch='a'.repeat(32);
    if(url.includes('action=upload')){forms.push(opt.body);const paths=opt.body.items.filter(([k])=>k==='paths[]');data=failUpload?{ok:false,error:'test transport failure'}:{ok:true,saved:paths.length-(shortAck?1:0)};}
    if(url.includes('action=finalize')){const paths=forms.flatMap(f=>f.items.filter(([k])=>k==='paths[]').map(([,p])=>p));data.files=paths.length-(badFinal?1:0);data.images=paths.filter(p=>p.startsWith('images/')).length;}
    return {ok:data.ok,status:data.ok?200:500,json:async()=>data};
  };
  vm.runInNewContext(source,{document:{getElementById:id=>nodes.get(id),createElement:()=>new Element()},window:{getProfisportCsrf:()=> 'test-csrf'},fetch,FormData:Form});
  const file=(name,size=30,lastModified=1)=>({name,size,lastModified});
  nodes.get('adminProductsCsv').files=[file('Любое название.CSV')];
  const select=files=>{const n=nodes.get('adminImagesFiles');n.files=files;n.events.change();};
  return {nodes,calls,forms,file,select,run:()=>nodes.get('adminUploadUpdateBtn').click(),paths:()=>forms.flatMap(f=>f.items.filter(([k])=>k==='paths[]').map(([,p])=>p))};
}
test('exactly one CSV field; photos use ordinary multi-file selection',()=>{const h=harness();assert.equal(h.nodes.has('adminCategoriesCsv'),false);assert.equal(h.nodes.get('adminImagesFiles').multiple,true);assert.doesNotMatch(h.nodes.get('adminImagesFiles').tag,/webkitdirectory/);});
test('one arbitrarily named CSV works without pictures',async()=>{const h=harness();await h.run();assert.deepEqual(h.paths(),['Любое название.CSV']);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/одна таблица.*0 фото/);assert.equal(h.nodes.get('run1cImportBtn').clicks,1);});
test('only selected pictures are sent, no folder or full image set required',async()=>{const h=harness();h.select([h.file('one.jpg'),h.file('two.webp')]);await h.run();assert.deepEqual(h.paths(),['Любое название.CSV','images/one.jpg','images/two.webp']);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/2 фото/);});
test('another selection adds photos; clearing selection sends CSV only',async()=>{const h=harness();h.select([h.file('one.jpg')]);h.select([h.file('two.webp')]);assert.match(h.nodes.get('adminImagesCount').textContent,/2/);h.nodes.get('adminClearImages').click();await h.run();assert.deepEqual(h.paths(),['Любое название.CSV']);});
test('CSV is required and no second CSV is requested',async()=>{const h=harness();h.nodes.get('adminProductsCsv').files=[];await h.run();assert.equal(h.calls.length,0);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/одну CSV/);});
test('photo transfer failure stops before activation',async()=>{const h=harness({failUpload:true});h.select([h.file('one.jpg')]);await h.run();assert.equal(h.calls.some(u=>u.includes('action=finalize')),false);assert.equal(h.nodes.get('run1cImportBtn').clicks,0);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/остановлена/);assert.equal(h.nodes.get('adminUploadUpdateBtn').disabled,false);});
test('short server acknowledgement does not activate incomplete files',async()=>{const h=harness({shortAck:true});await h.run();assert.equal(h.calls.some(u=>u.includes('action=finalize')),false);assert.equal(h.nodes.get('run1cImportBtn').clicks,0);});
test('conflicting photo filenames are reported rather than overwritten',()=>{const h=harness();h.select([h.file('one.jpg'),h.file('ONE.JPG',99)]);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/одинаково/);assert.equal(h.calls.length,0);});
test('double click creates only one batch',async()=>{const h=harness();await Promise.all([h.run(),h.run()]);assert.equal(h.calls.filter(u=>u.includes('action=start')).length,1);});
test('catalog update can be left manual',async()=>{const h=harness();h.nodes.get('adminRunAfterUpload').checked=false;await h.run();assert.equal(h.nodes.get('run1cImportBtn').clicks,0);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/Загружено/);});
