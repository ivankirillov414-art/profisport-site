'use strict';
(function(root){
 function render(recipe,components=[],collections=[]){
  if(recipe.type==='collection')return root.CMSCollections.render(recipe,collections);
  if(recipe.type!=='global')return {id:recipe.id,type:recipe.type,...(recipe.props?{props:structuredClone(recipe.props)}:{visible:recipe.visible})};
  const component=components.find(c=>c.id===recipe.component);if(!component)throw Error('Общий блок не найден в этом сайте.');
  return {id:recipe.id,type:component.block.type,props:{...structuredClone(component.block.props),...structuredClone(recipe.overrides||{})}};
 }
 function expand(recipe,components=[],collections=[]){
  if(recipe.type==='collection')return {...render(recipe,components,collections),_collectionRecipe:structuredClone(recipe)};
  if(recipe.type!=='global')return structuredClone(recipe);
  const result=render(recipe,components),component=components.find(c=>c.id===recipe.component);
  return {...result,_componentId:component.id,_componentName:component.name,_componentBase:structuredClone(component.block.props),_componentOverrides:structuredClone(recipe.overrides||{})};
 }
 function reference(recipe){
  if(!recipe)return recipe;
  if(recipe._collectionRecipe)return {...structuredClone(recipe._collectionRecipe),id:recipe.id};
  if(!recipe._componentId)return structuredClone(recipe);
  const overrides={...structuredClone(recipe._componentOverrides)};
  for(const [key,value] of Object.entries(recipe.props))if(JSON.stringify(value)!==JSON.stringify(recipe._componentBase[key]))overrides[key]=structuredClone(value);
  // Existing explicit exceptions remain explicit even when equal to the new base.
  for(const key of Object.keys(overrides))overrides[key]=structuredClone(recipe.props[key]);
  return {id:recipe.id,type:'global',component:recipe._componentId,overrides};
 }
 function uses(draft,id){const out=[];for(const [page,data] of Object.entries(draft.pages))for(const block of data.layout||[])if(block.type==='global'&&block.component===id)out.push({page,id:block.id});return out;}
 const api={render,expand,reference,uses};if(typeof module==='object'&&module.exports)module.exports=api;else root.CMSComponents=api;
})(globalThis);
