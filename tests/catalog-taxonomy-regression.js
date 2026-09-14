const fs=require('fs');
const vm=require('vm');
const path=require('path');

const root=path.resolve(__dirname,'..');
const source=fs.readFileSync(path.join(root,'catalog-loader.js'),'utf8');
const marker='function normalizeProduct';
const cut=source.indexOf(marker);
if(cut<0)throw new Error('catalog-loader taxonomy section not found');

const context={};
vm.createContext(context);
vm.runInContext(
  source.slice(0,cut)+'\nthis.__taxonomy={primaryProductType,departmentFor,resolveCatalogBrand,sanitizeCatalogSpecs,isPurchasableCatalogRow,catalogMatchesDepartment,catalogSectionFor,catalogSubcategory,CATALOG_DEPARTMENTS,CATALOG_SECTIONS};',
  context
);
const {primaryProductType,departmentFor,resolveCatalogBrand,sanitizeCatalogSpecs,isPurchasableCatalogRow}=context.__taxonomy;

function equal(actual,expected,label){
  if(actual!==expected){
    console.error(`CATALOG TAXONOMY REGRESSION: ${label}: expected ${expected}, got ${actual}`);
    process.exitCode=1;
  }
}

const shimano11='Ролики Shimano, 11ск, верхн+нижн, к RD-R7000';
const shimano12='Ролики Shimano, 12ск, верхн+нижн, к RD-M7100';
const cyclingPath=['Главная','Каталог товаров','Велозапчасти','Переключатели'];

equal(primaryProductType(shimano11),'','Shimano derailleur pulleys are not roller skates');
equal(primaryProductType(shimano12),'','Shimano 12-speed derailleur pulleys are not roller skates');
equal(departmentFor(shimano11,cyclingPath).key,'cycling','Shimano pulleys stay in cycling');
equal(departmentFor('Ролики раздвижные детские 31-34',['Ролики']).key,'rollers','actual roller skates stay in rollers');
equal(
  departmentFor('Велосипед 3-х кол WERTER BERGER TRIKE XG 11214-3 черный',['Хоккей, коньки','Коньки фигурные']).key,
  'bicycle',
  'product name overrides polluted source category for a bicycle'
);

equal(resolveCatalogBrand('Велосипед 24 STELS Turbo 470 MD','',{}).brand,'STELS','static fallback infers STELS');
equal(resolveCatalogBrand('Самокат трюковой Provokator 47 версия 2','',{}).brand,'Provokator','static fallback infers Provokator');
equal(resolveCatalogBrand('Ботинки лыжные NNN Comfort one size','',{}).brand,'','noise token is not a brand');
equal(resolveCatalogBrand('Любой товар','',{'Производитель':'Fischer'}).brand,'Fischer','static fallback uses manufacturer spec');

equal(isPurchasableCatalogRow({price_rub:0}),false,'zero-price static rows are hidden');
equal(isPurchasableCatalogRow({price_rub:1200}),true,'priced static rows remain visible');
const badSpecs=sanitizeCatalogSpecs('Палки лыжные TREK Snowline',{'Ростовка рамы':'Пластик','Материал':'Алюминий'});
equal(Object.prototype.hasOwnProperty.call(badSpecs,'Ростовка рамы'),false,'bad frame-size material is removed in static fallback');
equal(badSpecs['Материал'],'Алюминий','valid static spec remains');
const bikeSpecs=sanitizeCatalogSpecs('Велосипед STELS Navigator',{'Ростовка рамы':'18'});
equal(bikeSpecs['Ростовка рамы'],'18','bicycle frame size remains in static fallback');

// Navigation coverage and real ambiguous names found in the source catalog.
const {catalogMatchesDepartment,catalogSectionFor,catalogSubcategory,CATALOG_DEPARTMENTS,CATALOG_SECTIONS}=context.__taxonomy;
for(const key of Object.keys(CATALOG_DEPARTMENTS)){
  equal(Object.values(CATALOG_SECTIONS).filter(s=>s.departments.includes(key)).length,1,`one home section for ${key}`);
}
const cases=[
  ['Сапборд Koromo Orange 350x84x15',['Сапборды'],'water','tourism'],
  ['Надувная доска для SUP (САП) серфинга DZL-320',[],'water','tourism'],
  ['Сиденье на сапборд',['Сапборды'],'water','tourism'],
  ['Насос для сапбордов двухконтурный',['Аксессуары','Насосы'],'water','tourism'],
  ['Очки для плавания',['Плавание'],'water','tourism'],
  ['Боксерские перчатки BoyBo',['Единоборства','Перчатки'],'combat','fitness'],
  ['Эспандер лыжника 1 резинка',['Фитнес','Эспандеры'],'fitness','fitness'],
  ['Ролик для пресса, широкий',['Фитнес','Ролики для пресса'],'fitness','fitness'],
  ['Мяч для фитнеса',['Фитнес','Мячи'],'fitness','fitness'],
  ['Мяч хоккейный VM-BDB1',['Хоккей, коньки'],'hockey','skiing'],
  ['Колеса для самокатов',['Самокаты','Запасные части'],'scooter','scooter'],
  ['Набор подшипников RIDEX ABEC-7, металлический бокс',['Самокаты','Подшипники ABEC'],'scooter','scooter'],
  ['Коляска Nika, скандинавский зеленый',['Сани, снегокаты'],'winter','skiing'],
  ['Вело 27,5 VARMA CONRAD',['Велосипеды','Горные 27,5'],'bicycle','bicycle'],
  ['Вело аксессуары/руль/BBB',['Велозапчасти','Рули'],'cycling','cycling'],
  ['Колодки тормозные BARADINE',['Велозапчасти','Тормозные колодки'],'cycling','cycling'],
  ['Шайба для каретки',['Велозапчасти','Каретки'],'cycling','cycling'],
  ['Штанга для велокресла HAMAX KISS',['Аксессуары'],'accessories','cycling'],
  ['Термоаппликация «Горнолыжник»',['Аксессуары','Наклейки'],'accessories','cycling'],
  ['Бинт боксерский',['Единоборства','Бинты'],'combat','fitness'],
  ['1020 Съёмник кассеты',['Веломастерская','Инструменты'],'cycling','cycling'],
  ['Палки для скандинавской ходьбы',['Скандинавская ходьба'],'walking','tourism'],
  ['Неизвестный товар',[],'other','other']
];
for(const [name,sourcePath,department,section] of cases){
  const tax=departmentFor(name,sourcePath);
  equal(tax.key,department,name);
  equal(catalogSectionFor(tax.key),section,`section: ${name}`);
  equal(catalogMatchesDepartment({department:tax.key},section),true,`click reaches ${name}`);
}
equal(primaryProductType('Насос для сапбордов'),'','SUP accessories are not boards');
equal(catalogSubcategory('Велосипед', ['Велосипеды','Горные 29'],departmentFor('Велосипед',['Велосипеды','Горные 29'])),'Горные 29','valid bicycle subcategory retained');
equal(catalogSubcategory('Велосипед', ['Хоккей, коньки','Коньки фигурные'],departmentFor('Велосипед',['Хоккей, коньки','Коньки фигурные'])),'Велосипеды','polluted bicycle subcategory repaired');
const app=fs.readFileSync(path.join(root,'app.js'),'utf8');
vm.runInContext(app.slice(0,app.indexOf('const productsEl='))+';this.__search={canonicalQuery,isPrimaryProduct,productText}',context);
for(const query of ['sup','сапборды','саббордов','сап-борд'])equal(context.__search.canonicalQuery(query),'сап',`SUP search alias: ${query}`);
equal(context.__search.isPrimaryProduct({name:'Насос для сапбордов',productType:'sup_accessory'},'sup'),false,'SUP search excludes accessory');

// Exercise the real filter path used by category tiles and restored URL state.
vm.runInContext(`
const category={value:'tourism'},minPrice={value:''},maxPrice={value:''},q={value:''},mobileQ={value:''},sort={value:'popular'},brandFilter={value:''},stockOnly={checked:false},saleOnly={checked:false},facet1={value:'',dataset:{}},facet2={value:'',dataset:{}};
function configureContextFilters(){} function render(list){view=list} function renderFilterChips(){} function renderCategoryShortcuts(){} function syncState(){}
products=[
 {id:1,name:'Надувная доска SUP',productType:'sup',department:'water',rawCat:'SUP-борды',price:25000,specs:{}},
 {id:2,name:'Насос для сапбордов',productType:'sup_accessory',department:'water',rawCat:'SUP-аксессуары',price:2000,specs:{}},
 {id:3,name:'Палатка',productType:'tent',department:'tourism',rawCat:'Палатки',price:7000,specs:{}},
 {id:4,name:'Палки для ходьбы',department:'walking',rawCat:'Скандинавская ходьба',price:3000,specs:{}},
 {id:5,name:'Колодки тормозные',department:'cycling',rawCat:'Тормозные колодки',price:500,specs:{}}
]; catalogComplete=true;
`+app.slice(app.indexOf('function apply('),app.indexOf('window.apply=apply;')),context);
const filtered=code=>vm.runInContext(code+';apply();view.map(p=>p.id).join(\",\")',context);
equal(filtered("category.value='tourism'"),'1,2,3,4','Tourism tile includes water, camping and walking');
equal(filtered("selectedSubcategory='SUP-борды'"),'1','SUP subcategory reaches boards');
equal(filtered("selectedSubcategory='';minPrice.value='2500';sort.value='priceAsc'"),'4,3,1','price filters and sorting work inside a broad section');
equal(filtered("minPrice.value='';sort.value='popular';q.value='сапборды'"),'1','SUP search finds boards through Russian plural alias');
equal(filtered("q.value='';mobileQ.value='';category.value='cycling'"),'5','bike parts exclude SUP equipment');

if(!process.exitCode)console.log('Catalog taxonomy and fallback quality regression checks passed.');

