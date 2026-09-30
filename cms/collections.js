'use strict';
(function(root){
 function pageKey(c,e){return 'c-'+c.id.length+'-'+c.id+'-'+e.slug+'.html';}
 function display(c,e,key,collections){const value=e.values[key];if(value==null)return '';const field=c.fields.find(f=>f.key===key);if(field?.type==='reference'){const target=collections.find(c=>c.id===field.target),entry=target?.entries.find(e=>e.id===value);return entry?.status==='published'?String(entry.values[target.titleField]??''):'';}return typeof value==='boolean'?(value?'Да':'Нет'):String(value);}
 function render(block,collections=[]){if(block.type!=='collection')return structuredClone(block);const c=collections.find(c=>c.id===block.collection);if(!c)throw Error('Коллекция списка не найдена.');const q=block.query,entries=c.entries.filter(e=>e.status==='published').slice();
  if(q.sortField){const key=q.sortField,type=c.fields.find(f=>f.key===key)?.type;entries.sort((a,b)=>{const av=a.values[key],bv=b.values[key];if(av==null||bv==null)return av==null&&bv==null?a.id.localeCompare(b.id):av==null?1:-1;const x=display(c,a,key,collections),y=display(c,b,key,collections),result=['number','boolean'].includes(type)?Number(av)-Number(bv):x<y?-1:x>y?1:0;return (q.direction==='desc'?-result:result)||a.id.localeCompare(b.id);});}
  const items=entries.map(e=>{const item={title:'',text:'',image:'',alt:''};for(const [prop,key] of Object.entries(block.mapping))item[prop]=display(c,e,key,collections);item.recordPage=c.template?.length?pageKey(c,e):'';item.filter=q.filterField?display(c,e,q.filterField,collections):'';return item;});
  return {id:block.id,type:'collection-list',props:structuredClone(block.props),items,pageSize:q.pageSize,filterLabel:c.fields.find(f=>f.key===q.filterField)?.label||''};
 }
 const api={render,display,pageKey};if(typeof module==='object'&&module.exports)module.exports=api;else root.CMSCollections=api;
})(globalThis);
