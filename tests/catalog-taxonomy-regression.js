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
  source.slice(0,cut)+'\nthis.__taxonomy={primaryProductType,departmentFor,resolveCatalogBrand,sanitizeCatalogSpecs,isPurchasableCatalogRow};',
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

if(!process.exitCode)console.log('Catalog taxonomy and fallback quality regression checks passed.');
