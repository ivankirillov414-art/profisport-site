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
  source.slice(0,cut)+'\nthis.__taxonomy={primaryProductType,departmentFor,resolveCatalogBrand,sanitizeCatalogSpecs,isPurchasableCatalogRow,catalogMatchesDepartment,catalogSectionFor,catalogSubcategory,CATALOG_DEPARTMENTS,CATALOG_SECTIONS,CATALOG_CATEGORY_ART};',
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

equal(resolveCatalogBrand('Велосипед 24 STELS Turbo 470 MD','',{}).brand,'STELS','catalog normalization infers STELS');
equal(resolveCatalogBrand('Самокат трюковой Provokator 47 версия 2','',{}).brand,'Provokator','catalog normalization infers Provokator');
equal(resolveCatalogBrand('Ботинки лыжные NNN Comfort one size','',{}).brand,'','noise token is not a brand');
equal(resolveCatalogBrand('Любой товар','',{'Производитель':'Fischer'}).brand,'Fischer','catalog normalization uses manufacturer spec');

equal(isPurchasableCatalogRow({price_rub:0}),false,'zero-price catalog rows are hidden');
equal(isPurchasableCatalogRow({price_rub:1200}),true,'priced catalog rows remain visible');
const badSpecs=sanitizeCatalogSpecs('Палки лыжные TREK Snowline',{'Ростовка рамы':'Пластик','Материал':'Алюминий'});
equal(Object.prototype.hasOwnProperty.call(badSpecs,'Ростовка рамы'),false,'bad frame-size material is removed in catalog normalization');
equal(badSpecs['Материал'],'Алюминий','valid catalog spec remains');
const bikeSpecs=sanitizeCatalogSpecs('Велосипед STELS Navigator',{'Ростовка рамы':'18'});
equal(bikeSpecs['Ростовка рамы'],'18','bicycle frame size remains in catalog normalization');

// Navigation coverage and real ambiguous names found in the source catalog.
const {catalogMatchesDepartment,catalogSectionFor,catalogSubcategory,CATALOG_DEPARTMENTS,CATALOG_SECTIONS}=context.__taxonomy;
for(const key of Object.keys(CATALOG_DEPARTMENTS)){
  equal(Object.values(CATALOG_SECTIONS).filter(s=>s.departments.includes(key)).length,key==='other'?0:1,`one home section for ${key}`);
}
equal(catalogSectionFor('scooter'),'scooter','Scooters have a dedicated home section');
equal(catalogSectionFor('rollers'),'rollers','Roller skates have their own combined skating section');
equal(catalogSectionFor('boards'),'rollers','Skateboards stay with roller skates');
equal(catalogMatchesDepartment({department:'rollers'},'scooter'),false,'Scooter category excludes rollers');
equal(catalogMatchesDepartment({department:'scooter'},'rollers'),false,'Skating category excludes scooters and scooter parts');
equal(departmentFor('Шлем для самоката',['Аксессуары','Шлемы']).key,'scooter','Explicit scooter protection stays with scooter equipment');
equal(departmentFor('Подшипники ABEC',['Ролики и самокаты','Самокаты','Запчасти']).key,'scooter','Explicit scooter branch wins over a mixed roller parent');
equal(departmentFor('Комплект колес',['Ролики и самокаты','Запчасти для роликов']).key,'rollers','Roller parts follow their leaf branch under mixed parent');
equal(departmentFor('Подвеска',['Самокаты','Запчасти для скейтбордов']).key,'boards','Skateboard parts follow their leaf branch under scooter parent');
const cases=[
  ['Адаптер диск. торм. BENGAL ADU3',['Адаптеры'],'cycling','cycling'],
  ['Каретка Shimano',['Каретки'],'cycling','cycling'],
  ['Насос велосипедный',['Велосипеды'],'accessories','accessories'],
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
  ['Штанга для велокресла HAMAX KISS',['Аксессуары'],'accessories','accessories'],
  ['Термоаппликация «Горнолыжник»',['Аксессуары','Наклейки'],'accessories','accessories'],
  ['Бинт боксерский',['Единоборства','Бинты'],'combat','fitness'],
  ['1020 Съёмник кассеты',['Веломастерская','Инструменты'],'accessories','accessories'],
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
vm.runInContext("products.push({id:6,name:'Велосипед FORMAT 1412',department:'bicycle',productType:'bicycle',price:42000,specs:{}})",context);
equal(filtered("category.value='bicycle'"),'6','Bicycle entry excludes hubs and other cycling parts');
vm.runInContext("products.push({id:7,name:'Самокат трюковой',department:'scooter',productType:'scooter',price:5000,specs:{}},{id:8,name:'Колеса для самокатов',department:'scooter',price:500,specs:{}},{id:9,name:'Ролики детские',department:'rollers',price:3000,specs:{}},{id:10,name:'Скейтборд',department:'boards',price:4000,specs:{}})",context);
equal(filtered("category.value='scooter'"),'7,8','Scooter tile includes scooters and their parts only');
equal(filtered("category.value='rollers'"),'9,10','Skating tile includes roller skates and skateboards only');
vm.runInContext("const cardCollator=new Intl.Collator('ru',{numeric:true,sensitivity:'base'});"+app.slice(app.indexOf('function compareProductCards('),app.indexOf('function render(list=')),context);
vm.runInContext("this.ranked=[{id:1,name:'Беговел A',department:'bicycle',image:'photo'},{id:2,name:'Велосипед 16 Детский',department:'bicycle',image:'photo'},{id:3,name:'Велосипед 29 Горный',department:'bicycle',image:'photo'},{id:4,name:'Велосипед 26 Без фото',department:'bicycle'}].sort(compareProductCards).map(p=>p.id).join(',')",context);
equal(context.ranked,'3,2,4,1','Recommended bicycle entry leads with full bicycles and verified photos, before balance bikes');

// Animated startup must not replace the catalog-owned artwork or section labels.
const motion=fs.readFileSync(path.join(root,'psf-final-motion.js'),'utf8');
equal(/restoreRideCategory|patchMenuCopy|option.value==='scooter'/.test(motion),false,'Motion script does not rewrite category tiles or scooter menu');
const home=fs.readFileSync(path.join(root,'index.html'),'utf8');
const tileHtml=home.slice(home.indexOf('<div id="categoryTiles"'),home.indexOf('<section id="catalogProducts"'));
for(const [key,url] of Object.entries(context.__taxonomy.CATALOG_CATEGORY_ART)){
  equal(tileHtml.includes(`data-department="${key}"`),true,`Static home includes ${key}`);
  const tile=tileHtml.match(new RegExp(`data-department="${key}"[\\s\\S]*?</a>`))?.[0]||'';
  equal(tile.includes(`src="${url}"`),true,`Static and loaded ${key} tiles share the same image`);
  equal(fs.existsSync(path.join(root,url.split('?')[0])),true,`Category artwork exists for ${key}`);
}
if(!process.exitCode)console.log('Catalog taxonomy and normalization regression checks passed.');
