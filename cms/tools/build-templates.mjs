// Build connector snapshots from reviewed storefront source, never from remote URLs.
import fs from 'node:fs';
import {parseHTML} from 'linkedom';
const manifest=JSON.parse(fs.readFileSync('cms/private/bindings.json'));
const templates={};
for(const [path,page] of Object.entries(manifest.pages)){
 const {document}=parseHTML(fs.readFileSync(path,'utf8'));
 for(const f of page.fields)for(const node of document.querySelectorAll(f.selector))node.setAttribute('data-cms-fields',[node.getAttribute('data-cms-fields'),f.id].filter(Boolean).join(','));
 const styles=[...document.querySelectorAll('link[rel="stylesheet"]')].map(n=>n.getAttribute('href'));
 const css=[...document.querySelectorAll('style')].map(n=>n.textContent).join('\n');
 for(const n of document.querySelectorAll('script,iframe,object,embed,link,style'))n.remove();
 for(const n of document.querySelectorAll('*'))for(const a of [...n.attributes])if(/^on/i.test(a.name)||['srcdoc','autofocus'].includes(a.name))n.removeAttribute(a.name);
 const main=document.querySelector('main');if(!main)throw Error('Missing main '+path);
 const sections=[...main.children].map((node,i)=>{
  const selector=node.id?'#'+node.id:`main > ${node.tagName.toLowerCase()}:nth-child(${i+1})`;
  const legacy=page.blocks.find(b=>document.querySelector(b.selector)===node);
  return{id:'section'+i,selector,label:legacy?.label||node.querySelector('h1,h2,h3')?.textContent.trim().slice(0,80)||'Блок '+(i+1),legacy:legacy?.id||null,html:node.outerHTML};
 });
 templates[path]={styles,css,mainClass:main.className,sections};
}
fs.writeFileSync('cms/private/templates.json',JSON.stringify(templates,null,2)+'\n');
console.log(Object.fromEntries(Object.entries(templates).map(([k,v])=>[k,v.sections.length])));
