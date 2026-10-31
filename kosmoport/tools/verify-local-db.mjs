import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {spawn,spawnSync} from 'node:child_process';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..'),runtime=path.join(root,'tests/e2e/.runtime');
const php=process.env.KCRM_QA_PHP||'C:/Users/Izop300520251/Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/php.exe';
const binary=path.join(runtime,'server/mariadb-11.4.13-winx64/bin'),credential=path.join(runtime,'local-db.json');
const pause=ms=>new Promise(r=>setTimeout(r,ms));
const phpArgs=['-n','-d','extension_dir='+path.join(path.dirname(php),'ext'),'-d','extension=pdo_mysql'];
function probe(){return spawnSync(php,[...phpArgs,path.join(root,'tests/e2e/db-admin.php'),'probe'],{env:{...process.env,KCRM_QA_DB_FILE:credential},encoding:'utf8',windowsHide:true,timeout:8000});}
if(fs.existsSync(credential)&&probe().status===0){console.log('PASS existing disposable MariaDB is reachable; private connection file: tests/e2e/.runtime/local-db.json');process.exit(0);}
if(fs.existsSync(credential))throw Error('Recorded QA server is unavailable. Inspect its PID/log before creating another instance.');
if(!fs.existsSync(path.join(binary,'mariadbd.exe')))throw Error('Verified MariaDB portable package missing; see handoffs/qa.md');
fs.mkdirSync(runtime,{recursive:true});
const port=await new Promise((resolve,reject)=>{const server=net.createServer();server.once('error',reject);server.listen(0,'127.0.0.1',()=>{const p=server.address().port;server.close(()=>resolve(p));});});
const password=crypto.randomBytes(24).toString('hex'),data=path.join(runtime,'database');
if(fs.existsSync(data))throw Error('Refusing to reinitialize an existing datadir');
const init=spawnSync(path.join(binary,'mariadb-install-db.exe'),['--datadir='+data,'--port='+port,'--password='+password,'--silent'],{encoding:'utf8',windowsHide:true,timeout:120000});
if(init.status!==0)throw Error('MariaDB disposable initialization failed; '+String(init.stderr||init.stdout).replaceAll(password,'[redacted]').slice(-1500));
fs.writeFileSync(credential,JSON.stringify({marker:'KCRM_QA_LOCAL_SERVER',host:'127.0.0.1',port,username:'root',password,data_path:data},null,2));
const fd=fs.openSync(path.join(runtime,'mariadb.log'),'a');
const child=spawn(path.join(binary,'mariadbd.exe'),['--defaults-file='+path.join(data,'my.ini'),'--datadir='+data,'--port='+port,'--bind-address=127.0.0.1','--skip-name-resolve','--max-connections=20','--innodb-buffer-pool-size=64M','--performance-schema=OFF','--console'],{detached:true,windowsHide:true,stdio:['ignore',fd,fd]});
child.on('error',()=>{});fs.closeSync(fd);child.unref();
fs.writeFileSync(path.join(runtime,'server-process.json'),JSON.stringify({pid:child.pid,port,binary:path.join(binary,'mariadbd.exe'),started_at:new Date().toISOString()},null,2));
for(let n=0;n<50;n++){if(probe().status===0){console.log('PASS MariaDB 11.4.13 started on loopback port '+port+'; no Windows service. Connection stays in tests/e2e/.runtime/local-db.json');process.exit(0);}await pause(300);}
throw Error('Disposable MariaDB did not become ready. Inspect tests/e2e/.runtime/mariadb.log; do not reuse a business database.');
