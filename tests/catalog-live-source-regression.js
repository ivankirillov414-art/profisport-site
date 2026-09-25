// Final CI gate for the live MySQL catalog contract.
// ProfiSport must not depend on the retired parser/static catalog.
const fs=require('fs');
const path=require('path');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const fail=msg=>{console.error('CATALOG LIVE SOURCE REGRESSION:',msg);process.exitCode=1};

const index=read('index.html');
const loader=read('catalog-loader.js');
const paged=read('catalog-live-paged.js');
const api=read('api/catalog.php');
const importer=read('api/import-apply.php');

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
if(loader.includes('data/manifest.json')||paged.includes('data/manifest.json'))fail('storefront must not load parser/static manifest data');
if(loader.includes('data/catalog-')||paged.includes('data/catalog-'))fail('storefront must not load parser/static catalog chunks');
if(loader.includes('static-db-photo-resolver')||paged.includes('static-db-photo-resolver'))fail('retired static catalog fallback is still present');
if(paged.includes('loadStaticCatalogFallback')||loader.includes('staticRowWithDbPhotoFallback'))fail('retired parser fallback functions are still present');
if(!paged.includes("window.CATALOG_SOURCE='live-cache-stale'"))fail('emergency fallback must use a cached live MySQL catalog');
if(!paged.includes("window.CATALOG_PHOTO_SOURCE='mysql'"))fail('live and cached catalog must keep MySQL as photo source');
if(api.includes("$images[]='api/product-fallback-image.php"))fail('catalog API must not mix fallback images into DB source images');
if(!api.includes("'fallback_image'=>null"))fail('catalog API must not offer unverified fallback photos');
if(!api.includes("'image_source'=>$imageSource"))fail('catalog API image source diagnostic is missing');
if(importer.includes('$final=$urls?:$oldImgs'))fail('1C import must never replace historical DB image arrays with a smaller current export');
if(!importer.includes('array_merge($urls,$oldImgs,$oldMain!=='))fail('1C import must union new image references with historical DB image references');
if(!importer.includes("catalog_snapshot"))fail('1C import must tag every accepted product with its catalog snapshot');
if(!importer.includes("catalog_snapshot<>?"))fail('completed 1C import must hide products absent from the current snapshot');
if(!importer.includes("Выгрузка изменилась во время обновления"))fail('1C import must stop if the source file changes between batches');
if(!process.exitCode)console.log('Catalog live MySQL source regression checks passed.');
