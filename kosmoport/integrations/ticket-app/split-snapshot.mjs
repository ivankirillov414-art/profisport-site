// Produce topologically ordered browser requests below the core's 1 MiB body cap.
import {readFileSync,writeFileSync,mkdirSync} from 'node:fs';import {join} from 'node:path';
const [input,folder]=process.argv.slice(2);if(!input||!folder)throw Error('Usage: node split-snapshot.mjs snapshot.json NEW-folder');
const doc=JSON.parse(readFileSync(input,'utf8'));if(doc.format!=='kosmoport.tickets'||doc.version!==1)throw Error('Unsupported format');
mkdirSync(folder);const files=[];const limit=800000;
function blank(){return {format:doc.format,version:1,origin:doc.origin,exported_at:doc.exported_at,sources:[],batches:[],tickets:[],admissions:[],events:[]};}
let part=blank(),bytes=Buffer.byteLength(JSON.stringify(part));function flush(){if(!['sources','batches','tickets','admissions','events'].some(k=>part[k].length))return;const name=String(files.length+1).padStart(4,'0')+'.json';const text=JSON.stringify(part);if(Buffer.byteLength(text)>limit)throw Error('Single record exceeds browser part limit');writeFileSync(join(folder,name),text,{flag:'wx'});files.push({file:name,bytes:Buffer.byteLength(text)});part=blank();bytes=Buffer.byteLength(JSON.stringify(part));}
function add(records){let extra=records.reduce((n,[k,v])=>n+Buffer.byteLength(JSON.stringify(v))+(part[k].length?1:0),0);if(bytes+extra>limit){flush();extra=records.reduce((n,[k,v])=>n+Buffer.byteLength(JSON.stringify(v)),0);}if(bytes+extra>limit)throw Error('Single record exceeds browser part limit');for(const [k,v]of records)part[k].push(v);bytes+=extra;}
const admissionMap=new Map(doc.admissions.map(a=>[a.id,a]));const included=new Set();
for(const key of ['sources','batches']){for(const row of doc[key])add([[key,row]]);flush();}
for(const t of doc.tickets){const records=[['tickets',t]];if(t.used_at){const a=admissionMap.get(t.scan_id);if(!a||a.code!==t.code||a.reversed_at)throw Error('Invalid live admission');records.push(['admissions',a]);included.add(a.id);}add(records);}flush();
for(const a of doc.admissions)if(!included.has(a.id))add([['admissions',a]]);flush();for(const e of doc.events)add([['events',e]]);flush();
writeFileSync(join(folder,'IMPORT-ORDER.txt'),'Import numbered JSON files in order, while BOTH admission stations are stopped. Each file is atomic; the whole sequence is NOT one transaction. On ANY conflict stop and reconcile, do not skip failed parts. Keep original snapshot.\n'+files.map(x=>x.file+' '+x.bytes+' bytes').join('\n'),{flag:'wx'});console.log(JSON.stringify({parts:files.length,maxBytes:Math.max(...files.map(x=>x.bytes))}));
