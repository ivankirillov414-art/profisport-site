const fs=require('fs'),vm=require('vm'),assert=require('assert');
const root=require('path').join(__dirname,'..');
const app=fs.readFileSync(root+'/app.js','utf8'),loader=fs.readFileSync(root+'/catalog-loader.js','utf8'),filters=fs.readFileSync(root+'/catalog-filter-panel.js','utf8');
const context={plain:s=>String(s||'').toLowerCase().replace(/ё/g,'е'),isPrimaryProduct:(p,type)=>p.productType===type};
vm.createContext(context);
vm.runInContext(loader.slice(0,loader.indexOf('// Bound both the request')),context);
vm.runInContext(filters.slice(filters.indexOf('function mountainFrameHint'),filters.indexOf("document.addEventListener('DOMContentLoaded'")),context);
vm.runInContext(app.slice(app.indexOf('function pickerRideScore'),app.indexOf('function renderPickerResults')),context);
const bike=(id,overrides={})=>({id,name:'Велосипед горный',productType:'bicycle',department:'bicycle',price:30000,stockCode:'in',stockQty:2,facets:{wheel:'29″',frame:'XXL'},specs:{},...overrides});
const candidates=[bike('adult'),bike('child',{name:'Велосипед 20 FAT',facets:{wheel:'20″'}}),bike('small',{facets:{wheel:'29″',frame:'S'}}),bike('city',{name:'Велосипед городской'}),bike('expensive',{price:60000}),bike('sold',{stockQty:0}),bike('part',{productType:'',name:'Рама горного велосипеда'}),bike('handlebar',{name:'Руль велосипедный MTB d=25.4, L=640 мм RUSH HOUR',department:'cycling',productType:''}),bike('unknown',{facets:{wheel:'29″'}})];
const results=context.selectPickerProducts(candidates,195,50000,'Бездорожье');
assert.deepEqual(Array.from(results,x=>x.product.id),['adult','unknown','city']);
assert.equal(results[0].fit.confirmed,true);assert.equal(results[1].fit.confirmed,false);
assert.deepEqual(Array.from(context.selectPickerProducts(candidates,175,50000,'Город'),x=>x.product.id),['city','unknown']);
assert.equal(context.pickerAssessment(bike('range',{specs:{'Рекомендуемый рост':'160–180 см'}}),195,'Бездорожье'),null);
assert.equal(context.pickerAssessment(bike('range',{specs:{'Рекомендуемый рост':'190–205 см'}}),195,'Бездорожье').confirmed,true);
assert.equal(context.selectPickerProducts(candidates,195,5000,'Бездорожье').length,0);
// The user's 160 cm / 20,000 RUB query must retain every type with a matching size.
const range={'Рекомендуемый рост':'150–170 см'};
const variety=['городской','горный','шоссейный','спортивный','гибридный','BMX','подростковый'].map((type,i)=>bike('type-'+i,{name:'Велосипед '+type,price:20000,facets:{wheel:type==='BMX'?'20″':'24″'},specs:range}));
const pool=[...variety,
  bike('too-expensive',{price:20001,specs:range}),
  bike('wrong-height',{price:20000,specs:{'Рекомендуемый рост':'180–190 см'}}),
  bike('out',{price:20000,stockCode:'out',specs:range}),
  bike('no-stock',{price:20000,stockQty:0,specs:range}),
  bike('accessory',{price:1000,department:'cycling',productType:'',specs:range})];
for(const height of [150,160,170])for(const ride of ['Город','Город + грунт','Бездорожье']){
  const found=context.selectPickerProducts(pool,height,20000,ride);
  assert.deepEqual(Array.from(found,x=>x.product.id).sort(),variety.map(x=>x.id).sort());
  assert.ok(found.every(x=>x.fit.confirmed));
}
assert.equal(context.selectPickerProducts(variety,149,20000,'Город').length,0);
assert.equal(context.selectPickerProducts(variety,171,20000,'Город').length,0);
assert.equal(context.selectPickerProducts(variety,160,19999,'Город').length,0);
assert.equal(context.selectPickerProducts(variety,160,20001,'Город').length,variety.length);
assert.equal(context.selectPickerProducts(variety,160,20000,'Город')[0].product.id,'type-0');
assert.equal(context.selectPickerProducts(variety,160,20000,'Бездорожье')[0].product.id,'type-1');
const crowded=[...Array.from({length:20},(_,i)=>bike('city-'+i,{name:'Велосипед городской',price:10000+i,specs:range})),...variety];
const firstPage=context.selectPickerProducts(crowded,160,20000,'Город').slice(0,12);
assert.ok(firstPage.some(x=>x.product.id==='type-1'),'mountain bike appears on the first page');
assert.ok(firstPage.some(x=>x.product.id==='type-5'),'BMX appears on the first page');
assert.ok(context.pickerAssessment(bike('teen',{name:'Велосипед подростковый',facets:{wheel:'24″'}}),160,'Город'));
assert.ok(context.pickerAssessment(bike('fold',{name:'Велосипед складной',facets:{wheel:'20″'}}),160,'Город'));
assert.ok(context.pickerAssessment(bike('bmx',{name:'Велосипед BMX',facets:{wheel:'20″'}}),160,'Город'));
assert.equal(context.pickerAssessment(bike('mtb-small',{facets:{wheel:'26″',frame:'S'}}),160,'Город').confirmed,true);
assert.equal(context.pickerAssessment(bike('mtb-large',{facets:{wheel:'26″',frame:'XL'}}),160,'Город'),null);
// Result pages expose all matches, including the fifth and last model, without dropping height filters.
let rendered='',moreRemoved=false;
const more={insertAdjacentHTML:(where,html)=>{assert.equal(where,'beforebegin');rendered+=html},remove:()=>{moreRemoved=true}};
const box={innerHTML:'',querySelector:()=>more};
Object.assign(context,{$:()=>box,imageCandidates:()=>[],esc:s=>String(s),rub:n=>String(n)});
vm.runInContext(app.slice(app.indexOf('function renderPickerResults'),app.indexOf("$('#pick')?.addEventListener")),context);
const many=Array.from({length:29},(_,i)=>({product:bike('page-'+i,{name:'Model '+i}),fit:{confirmed:false,label:'Уточнить ростовку'}}));
context.renderPickerResults(many,50000);
assert.equal((rendered.match(/pickerResultCard/g)||[]).length,12);
assert.match(box.innerHTML,/Найдено вариантов: 29/);
more.onclick();assert.equal((rendered.match(/pickerResultCard/g)||[]).length,24);
more.onclick();assert.equal((rendered.match(/pickerResultCard/g)||[]).length,29);
assert.match(rendered,/product.html\?id=page-28/);assert.equal(moreRemoved,true);
rendered='';context.renderPickerResults([],50000);assert.match(box.innerHTML,/не найдено/);assert.equal(rendered,'');
for(const suffix of ['10% НДС','10% (НДС)','(НДС) 10%','НДС 10%'])assert.equal(context.cleanCatalogLabel('Малышам '+suffix),'Малышам');
assert.equal(context.cleanCatalogLabel('VATAN'),'VATAN');assert.equal(context.cleanCatalogLabel('Скидка 10%'),'Скидка 10%');
assert.equal(context.catalogSubcategory('Велосипед',['Велосипеды','Малышам 10%'],{key:'bicycle',type:'bicycle'}),'Малышам');
console.log('Bike picker: all bicycle types, ranking, height/budget boundaries, stock, incomplete specs and all result pages passed.');
