// Final CI gate for the photo-source repair package.
// Guards the live-DB photo source contract and prevents parser-mirror counts from being treated as missing DB photos.
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
if(manifest.image_coverage_scope!=='parser_static_mirror')fail('static manifest must identify parser-only image coverage');
if(manifest.authoritative_photo_source!=='mysql.products.main_image/images')fail('static manifest must name live MySQL as photo source of truth');
if('with_images' in manifest)fail('ambiguous with_images metric must not be used for parser coverage');
console.log(`Parser mirror image coverage is ${manifest.parser_rows_with_images}/${manifest.products}; this is not live DB photo health.`);
if(loader.includes("window.CATALOG_SOURCE='static';"))fail('base loader must not expose raw parser static photos');
if(!loader.includes("window.CATALOG_SOURCE='static-db-photo-resolver'"))fail('base loader emergency fallback must resolve photos against MySQL');
if(!loader.includes('map(staticRowWithDbPhotoFallback).map(normalizeProduct)'))fail('base loader must prepend the DB photo resolver before parser images');
if(api.includes("$images[]='api/product-fallback-image.php"))fail('catalog API must not mix fallback images into DB source images');
if(!api.includes("'fallback_image'=>$sourceImageMissing?$fallbackImage:null"))fail('catalog API must expose fallback separately and only for missing DB images');
if(!api.includes("'image_source'=>$imageSource"))fail('catalog API image source diagnostic is missing');
if(!loader.includes("const sourceMissing=p.image_source_missing===true"))fail('loader must make fallback eligibility explicit');
if(!loader.includes("const finalImages=sourceMissing?"))fail('loader must only append fallback when the source is missing');
if(importer.includes('$final=$urls?:$oldImgs'))fail('1C import must never replace historical DB image arrays with a smaller current export');
if(!importer.includes('array_merge($urls,$oldImgs,$oldMain!=='))fail('1C import must union new image references with historical DB image references');
if(!paged.includes("window.CATALOG_PHOTO_SOURCE='mysql'"))fail('live catalog must expose MySQL as authoritative photo source');
if(!paged.includes("window.CATALOG_PHOTO_SOURCE='mysql-resolver+parser-emergency'"))fail('static metadata fallback must resolve photos against MySQL before parser images');
if(!process.exitCode)console.log('Catalog live source regression checks passed.');
