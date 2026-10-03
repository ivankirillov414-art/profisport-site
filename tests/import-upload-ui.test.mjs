import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../admin/import-upload.js',import.meta.url),'utf8');
function harness({failUpload=false,shortAck=false}={}){
  const nodes=new Map(),calls=[],forms=[];
  class Element{
    constructor(){this.files=[];this.checked=false;this.disabled=false;this.events={};this.style={};this.clicks=0;}
    set innerHTML(html){for(const match of html.matchAll(/<[^>]+id="([^"]+)"[^>]*>/g)){const e=new Element();e.checked=/\schecked(?:[\s>])/.test(match[0]);e.required=/\srequired(?:[\s>])/.test(match[0]);nodes.set(match[1],e);}}
    addEventListener(name,fn){this.events[name]=fn;}
    insertBefore(e){nodes.set(e.id,e);}
    click(){this.clicks++;return this.events.click?.();}
  }
  for(const id of ['catalog','refreshImports','run1cImportBtn'])nodes.set(id,new Element());
  class Form {constructor(){this.items=[];}append(key,value){this.items.push([key,value]);}}
  const fetch=async(url,opt)=>{
    calls.push(url);let data={ok:true};
    if(url.includes('action=start'))data.batch='a'.repeat(32);
    if(url.includes('action=upload')){
      forms.push(opt.body);const paths=opt.body.items.filter(([k])=>k==='paths[]');
      data=failUpload?{ok:false,error:'test transport failure'}:{ok:true,saved:paths.length-(shortAck?1:0)};
    }
    if(url.includes('action=finalize')){const paths=forms.flatMap(f=>f.items.filter(([k])=>k==='paths[]').map(([,p])=>p));data.files=paths.length;data.images=paths.filter(p=>p.startsWith('images/')).length;}
    return {ok:data.ok,status:data.ok?200:500,text:async()=>JSON.stringify(data)};
  };
  const context=vm.createContext({document:{getElementById:id=>nodes.get(id),createElement:()=>new Element()},window:{getProfisportCsrf:()=> 'test-csrf'},fetch,FormData:Form,setTimeout:fn=>{fn();}});
  vm.runInContext(source,context);
  const file=(name,path)=>({name,size:30,webkitRelativePath:path||''});
  nodes.get('adminProductsCsv').files=[file('products.csv')];nodes.get('adminCategoriesCsv').files=[file('categories.csv')];
  return {nodes,calls,forms,file,run:()=>nodes.get('adminUploadUpdateBtn').click(),paths:()=>forms.flatMap(f=>f.items.filter(([k])=>k==='paths[]').map(([,p])=>p))};
}
test('two tables and zero pictures complete upload and start catalog update',async()=>{const h=harness();await h.run();assert.deepEqual(h.paths(),['Tovary.csv','Categories.csv']);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/без новых картинок/);assert.equal(h.nodes.get('run1cImportBtn').clicks,1);});
test('explicit no-images mode ignores a previously selected folder and files',async()=>{const h=harness();h.nodes.get('adminImagesFolder').files=[h.file('old.jpg','set/old.jpg')];h.nodes.get('adminImageFiles').files=[h.file('extra.png')];h.nodes.get('adminUploadWithoutImages').checked=true;h.nodes.get('adminUploadWithoutImages').events.change();assert.equal(h.nodes.get('adminOptionalImages').disabled,true);await h.run();assert.deepEqual(h.paths(),['Tovary.csv','Categories.csv']);});
test('only selected individual photos are uploaded, no folder required',async()=>{const h=harness();h.nodes.get('adminImageFiles').files=[h.file('one.jpg'),h.file('two.webp')];await h.run();assert.deepEqual(h.paths(),['Tovary.csv','Categories.csv','images/one.jpg','images/two.webp']);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/картинок: 2/);});
test('nested folder keeps paths and ignores non-image sidecar files with a warning',async()=>{const h=harness();h.nodes.get('adminImagesFolder').files=[h.file('one.jpg','small/bikes/one.jpg'),h.file('Thumbs.db','small/Thumbs.db')];await h.run();assert.deepEqual(h.paths(),['Tovary.csv','Categories.csv','images/small/bikes/one.jpg']);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/Пропущено.*1/);});
test('folder containing no images still allows table update',async()=>{const h=harness();h.nodes.get('adminImagesFolder').files=[h.file('notes.txt','set/notes.txt')];await h.run();assert.deepEqual(h.paths(),['Tovary.csv','Categories.csv']);assert.equal(h.nodes.get('run1cImportBtn').clicks,1);});
test('two CSV tables remain required, but photos do not',async()=>{const h=harness();h.nodes.get('adminCategoriesCsv').files=[];await h.run();assert.equal(h.calls.length,0);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/Картинки необязательны/);assert.equal(h.nodes.get('adminImagesFolder').required,false);});
test('photo failure never masquerades as a successful update',async()=>{const h=harness({failUpload:true});h.nodes.get('adminImageFiles').files=[h.file('one.jpg')];await h.run();assert.equal(h.calls.some(u=>u.includes('action=finalize')),false);assert.equal(h.nodes.get('run1cImportBtn').clicks,0);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/остановлена/);assert.equal(h.nodes.get('adminUploadUpdateBtn').disabled,false);});
test('truncated upload acknowledgement does not activate incomplete batch',async()=>{const h=harness({shortAck:true});await h.run();assert.equal(h.calls.some(u=>u.includes('action=finalize')),false);assert.equal(h.nodes.get('run1cImportBtn').clicks,0);});
test('duplicate paths do not silently replace another selected picture',async()=>{const h=harness();h.nodes.get('adminImageFiles').files=[h.file('one.jpg'),h.file('ONE.JPG')];await h.run();assert.equal(h.calls.length,0);assert.match(h.nodes.get('adminUploadUpdateMsg').textContent,/одинаковый путь/);});
test('double click creates only one upload batch',async()=>{const h=harness();await Promise.all([h.run(),h.run()]);assert.equal(h.calls.filter(u=>u.includes('action=start')).length,1);});
