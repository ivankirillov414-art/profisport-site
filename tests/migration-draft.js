const assert=require('node:assert/strict'),fs=require('fs'),vm=require('vm');
const source=fs.readFileSync('admin/migration.js','utf8').split('/* Migration request controller */')[0];
function boot({draft=null,setup=null,hash=null,denyStorage=false}={}){
 const controls={},handlers={};for(const name of ['target_url','ftp_host','ftp_port','ftp_user','ftp_root','db_host','db_port','db_name','db_user','ftp_pass','db_pass','current_password'])controls[name]={value:name.endsWith('pass')||name==='current_password'?'KEEP-SECRET':'',type:name.endsWith('pass')||name==='current_password'?'password':'text'};
 const storage=new Map(draft?[['profisport-migration-connection-v1',JSON.stringify(draft)]]:[]),note={textContent:''},calls=[];
 const form={elements:{namedItem:n=>controls[n]},addEventListener:(n,f)=>handlers[n]=f};
 const context={document:{getElementById:id=>id==='migrationForm'?form:note},localStorage:{getItem:k=>{if(denyStorage)throw Error('denied');return storage.get(k)},setItem:(k,v)=>{if(denyStorage)throw Error('denied');storage.set(k,v)}},location:{hash:hash??(setup?'#setup='+encodeURIComponent(JSON.stringify(setup)):''),pathname:'/admin/migration.php',search:''},history:{replaceState:(...args)=>calls.push(args)},URLSearchParams};
 vm.runInNewContext(source,context);return {controls,handlers,storage,note,calls};
}
let b=boot({draft:{ftp_host:'old.example',db_name:'old'},setup:{ftp_host:'ftp.example.test',ftp_user:'test-user',db_host:'mysql.example.test',db_name:'new',db_port:'3306',ftp_pass:'DO-NOT-SAVE',db_pass:'DO-NOT-SAVE',current_password:'DO-NOT-SAVE'}});
assert.equal(b.controls.ftp_host.value,'ftp.example.test');assert.equal(b.controls.db_name.value,'new');
for(const n of ['ftp_pass','db_pass','current_password'])assert.equal(b.controls[n].value,'KEEP-SECRET');
assert.equal(b.calls[0][2],'/admin/migration.php');
b.controls.db_user.value='new-user';b.handlers.input();
const saved=[...b.storage.values()][0];assert(!saved.includes('SECRET')&&!saved.includes('password')&&!saved.includes('db_pass'));
const restored=boot({draft:JSON.parse(saved)});assert.equal(restored.controls.db_user.value,'new-user');
assert.equal(boot({setup:{db_port:'99999'}}).controls.db_port.value,'');
assert.doesNotThrow(()=>boot({hash:'#setup=%7Bbroken'}));assert.doesNotThrow(()=>boot({denyStorage:true,setup:{ftp_host:'valid.example'}}));
assert(!source.includes('fetch(')&&!source.includes('.submit('),'Draft must not test, authenticate or schedule a migration');
console.log('Migration connection prefill, draft restoration, password exclusion and no-action checks passed.');
