const fs=require('fs');
const path=require('path');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const fail=msg=>{console.error('CATALOG LIVE SOURCE REGRESSION:',msg);process.exitCode=1};

const index=read('index.html');
const loader=read('catalog-loader.js');
const paged=read('catalog-live-paged.js');
const api=read('api/catalog.php');
const manifest=JSON.parse(read('data/manifest.json'));

const basePos=index.indexOf('catalog-loader.js');
const pagedPos=index.indexOf('catalog-live-paged.js');
const appPos=index.indexOf('app.js');
if(basePos<0||pagedPos<0||appPos<0||!(basePos<pagedPos&&pagedPos<appPos))fail('paged live loader must be loaded between catalog-loader.js and app.js');
if(!/catalog-live-paged\.js\?v=\d+/.test(index))fail('paged live loader needs a cache version');
if(!/limit=\$\{limit\}&offset=\$\{offset\}/.test(paged))fail('live catalog must be requested in bounded pages');
if(!paged.includes('&count=1'))fail('paged loader must request the authoritative live row count');
if(!paged.includes("window.CATALOG_SOURCE='live-paged'"))fail('live paged source must be observable for diagnostics');
if(!paged.includes('live.length!==total'))fail('paged loader must reject incomplete live catalogs');
if(!/max\(0,min\(500/.test(api))fail('catalog API must enforce a bounded page limit');
if((manifest.with_images??manifest.products)>=manifest.products)console.log('Static fallback currently has full image coverage; live paging remains valid.');
else console.log(`Static fallback is sparse (${manifest.with_images}/${manifest.products} rows with images); authoritative live paging is required.`);
if(!loader.includes("window.CATALOG_SOURCE='static'"))fail('base loader static fallback diagnostic is missing');
if(!process.exitCode)console.log('Catalog live source regression checks passed.');
