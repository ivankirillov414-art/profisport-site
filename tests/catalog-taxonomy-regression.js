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
vm.runInContext(source.slice(0,cut)+'\nthis.__taxonomy={primaryProductType,departmentFor};',context);
const {primaryProductType,departmentFor}=context.__taxonomy;

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

if(!process.exitCode)console.log('Catalog taxonomy regression checks passed.');
