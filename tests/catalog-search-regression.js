const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const loader=fs.readFileSync('catalog-loader.js','utf8'),app=fs.readFileSync('app.js','utf8'),ctx={};vm.createContext(ctx);
vm.runInContext(loader.slice(0,loader.indexOf('// Bound both the request'))+'\n'+app.slice(0,app.indexOf('const productsEl=')),ctx);
const raw=[
 ['Трюковой самокат Zefir Lite V2 49','Самокаты'],
 ['Самокат трюковой TT Duker 3.0 black/green','Самокаты'],
 ['Грипсы 2шт с барендами Fish 170mm на трюковой самокат','Запчасти для самокатов'],
 ['Спортивный самокат для катания по снегу Sherp','Трюковые самокаты'],
 ['Спортивный самокат для катания по снегу WINDEGO','Трюковые самокаты'],
 ['Велосипед 29 STELS Navigator 710 MD','Горные 29'],
 ['Шлем велосипедный Vinca Sport','Шлемы'],
 ['Ролики Shimano, 11ск, верхн+нижн, к RD-R7000','Переключатели'],
 ['Ролики раздвижные детские 31-34','Роликовые коньки'],
 ['Ролик для пресса широкий','Ролики для пресса'],
 ['Лыжи беговые Fischer','Лыжи'],
 ['Ботинки лыжные SPINE NNN','Ботинки лыжные'],
 ['Сноуборд Atomic 155','Сноуборды'],
 ['Коньки фигурные 38','Коньки фигурные'],
 ['Гантель Starfit 5 кг','Гантели'],
 ['Палатка Deuter 3-местная','Палатки'],
 ['Насос велосипедный','Насосы'],
 ['Сапборд Koromo Orange 350','Сапборды'],
 ['Насос для сапбордов','Насосы'],
 ['Подшипник Shimano 6001','Подшипники'],
 ['Велотренажер магнитный','Велотренажеры'],
 ['Беговая дорожка электрическая','Беговые дорожки'],
 ['Эспандер лыжника','Эспандеры'],
 ['Мяч футбольный','Мячи'],
 ['Мяч для фитнеса','Мячи'],
 ['Клюшка хоккейная','Клюшки'],
 ['Спальный мешок Deuter','Спальники'],
 ['Самокат TechTeam городской','Самокаты'],
 ['Электросамокат Maxiscoo','Самокаты'],
 ['Беговел детский','Беговелы'],
 ['Гиря 16 кг','Гири'],
 ['Батут детский','Батуты'],
 ['Бассейн детский','Бассейны'],
 ['Ракетка теннисная','Ракетки'],
 ['Лодка надувная','Лодки'],
 ['Лонгборд','Лонгборды'],
 ['Скейтборд','Скейтборды'],
 ['Снегокат детский','Снегокаты'],
 ['Тюбинг 90 см','Тюбинги'],
 ['Зажим 34.9 для рулей трюковых самокатов HIC','Запчасти для самокатов'],
 ['Руль для трюкового самоката, вид5','Запчасти для самокатов'],
 ['Ролик массажный FA-507','Ролики для пресса'],
 ['Ролик гимнастический 2-х колесный','Ролики для пресса'],
 ['Ролик заднего переключателя 10T','Переключатели'],
 ['Велосипедный шлем SHARK NAVY-ORANGE','Шлемы'],
 ['Лыжероллеры коньковые ELVA SK100R','Лыжероллеры'],
 ['Термоаппликация световозвращающая Сноуборд','Наклейки'],
 ['Ролик для лыжероллеров конькового хода','Лыжероллеры'],
 ['Ролик классический Elpex каучук 70','Лыжероллеры'],
 ['Лыжа боковая к снегокату ЛБ1','Снегокаты'],
 ['Покрышка Kenda 29','Покрышки'],
 ['Куртка Nordski','Одежда'],
 ['Беговые лыжи+ крепл. FISCHER TWIN SKIN','Беговые лыжи'],
 ['Тюбинг 100см без камеры','Тюбинги'],
 ['Камера 26 Kenda','Камеры'],
 ['Грипстопы черные для самоката','Запчасти для самокатов'],
 ['Велокамера 27.5 Mitas','Камеры'],
 ['Велопокрышка 26 Kenda 1.75','Покрышки']
];
ctx.raw=raw.map(([name,leaf],i)=>({id:i+1,name,category_path:[leaf],price_rub:1000,sku:'SKU-'+String(i+1).padStart(4,'0'),stock_qty:2,availability:'in_stock'}));
vm.runInContext('products=raw.map(normalizeProduct)',ctx);
function ids(q){ctx.query=q;return vm.runInContext('products.filter(p=>productMatchesQuery(p,bestQuery(query))).map(p=>p.id).join(",")',ctx)}
function equal(q,expected){assert.equal(ids(q),expected.join(','),q)}
for(const q of ['трюковой самокат','самокат трюковой','трюковые самокаты'])equal(q,[1,2]);
equal('техтим',[2,28]);equal('тт',[2,28]);equal('самокаты',[1,2,28,29]);equal('грипсы самокат',[3]);equal('руль самокат',[41]);equal('зажим самокат',[40]);equal('самокат для снега',[4,5]);
equal('велосипеды',[6]);equal('шлем велосипедный',[7,45]);equal('ролики',[9]);equal('ролики для пресса',[10,43]);equal('роликовые коньки',[9]);equal('массажный ролик',[42]);equal('ролик лыжероллеров',[48,49]);equal('лыжа боковая',[50]);equal('гимнастический ролик',[43]);
equal('лыжи',[11,53]);equal('ботинки лыжные',[12]);equal('лыжные ботинки',[12]);equal('сноуборды',[13]);equal('коньки фигурные',[14]);
equal('гантели',[15]);equal('палатки',[16]);equal('насос',[17,19]);equal('сапборды',[18]);equal('насос сапборд',[19]);
equal('шимано',[8,20]);equal('кенда',[51,55,58]);equal('нордски',[52]);equal('stels',[6]);equal('стелс',[6]);equal('6001',[20]);equal('SKU-0003',[3]);equal('600',[ ]);
equal('велотренажеры',[21]);equal('беговые дорожки',[22]);equal('эспандеры',[23]);equal('мяч',[24,25]);equal('камеры',[55,57]);equal('велокамера',[55,57]);equal('покрышки',[51,58]);equal('клюшки',[26]);
equal('спальники',[27]);equal('беговелы',[30]);equal('гири',[31]);equal('батуты',[32]);equal('бассейны',[33]);equal('ракетки',[34]);
equal('лодки',[35]);equal('лонгборды',[36]);equal('скейтборды',[37]);equal('снегокаты',[38]);equal('тюбинги',[39,54]);
equal('dfkjcbgtl',[]);equal('dtkjcbgtl',[6]);
for(let i=0;i<raw.length;i++){ctx.query=raw[i][0];ctx.target=i+1;assert.ok(vm.runInContext('products.some(p=>p.id===target&&productMatchesQuery(p,bestQuery(query)))',ctx),'full name: '+raw[i][0]);equal('SKU-'+String(i+1).padStart(4,'0'),[i+1])}
assert.equal(vm.runInContext('products[0].rawCat',ctx),'Трюковые самокаты');
assert.equal(vm.runInContext('products[1].rawCat',ctx),'Трюковые самокаты');
assert.equal(vm.runInContext('products[3].rawCat',ctx),'Снегосамокаты');
assert.equal(vm.runInContext('products[3].department',ctx),'winter');
assert.equal(vm.runInContext('products.filter(p=>p.rawCat==="Трюковые самокаты").map(p=>p.id).join(",")',ctx),'1,2');
// Execute filter state transitions, including old section + brand + frame limits.
vm.runInContext(`const category={value:'bicycle'},brandFilter={value:'STELS'},facet1={value:'29',dataset:{}},facet2={value:'20',dataset:{}},minPrice={value:'50000'},maxPrice={value:'60000'},saleOnly={checked:true},sort={value:'priceDesc'},q={value:'трюковые самокаты'},mobileQ={value:''};function resetExtraFilters(){}function configureContextFilters(){}function render(list){view=list}function renderFilterChips(){}function renderCategoryShortcuts(){}function syncState(){} catalogComplete=true;`+app.slice(app.indexOf('function clearContextFilters('),app.indexOf('function renderFilterChips('))+app.slice(app.indexOf('function apply('),app.indexOf('window.apply=apply;')),ctx);
assert.equal(vm.runInContext('resetSearchFilters();apply();view.map(p=>p.id).join(",")',ctx),'1,2');
vm.runInContext(app.slice(app.indexOf('function suggestionItems('),app.indexOf('function bindSuggest(')),ctx);
assert.equal(vm.runInContext('suggestionItems(q.value).map(p=>p.id).join(",")',ctx),'1,2');
// Mentions of a pump/brake in a bicycle description must not promote it as a spare part.
vm.runInContext("products=products.map(p=>p.id===6?{...p,description:'Насос и грипсы продаются отдельно',specs:{Тормоза:'Гидравлические'}}:p)",ctx);
equal('насос',[17,19]);equal('грипсы',[3]);equal('велосипед гидравлические тормоза',[6]);
console.log('Catalog search: 58 products, full names and SKUs, query variants across all sections, category repair, primary/accessory intent, keyboard layout and stale-filter reset passed.');
