import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {root} from './runtime.mjs';
export function sourceSnapshot(){
 const walk=relative=>fs.readdirSync(path.join(root,relative),{withFileTypes:true}).flatMap(e=>e.name.startsWith('.')?[]:e.isDirectory()?walk(relative+'/'+e.name):[relative+'/'+e.name]);
 const files=['app','public','migrations','bin'].filter(n=>fs.existsSync(path.join(root,n))).flatMap(walk);
 files.push('config/config.example.php',...fs.readdirSync(path.join(root,'tools')).filter(n=>/^(package|backup|restore).*\.php$/.test(n)).map(n=>'tools/'+n));
 return Object.fromEntries(files.sort().map(n=>[n,crypto.createHash('sha256').update(fs.readFileSync(path.join(root,n))).digest('hex')]));
}
export function snapshotChanges(before,after){return [...new Set([...Object.keys(before),...Object.keys(after)])].filter(n=>before[n]!==after[n]);}
