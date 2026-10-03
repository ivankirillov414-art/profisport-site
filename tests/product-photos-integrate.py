"""Idempotent bounded integration edits for the existing ProfiSport project."""
from pathlib import Path

def replace(path, old, new):
    p=Path(path);text=p.read_text()
    if new in text:return
    if old not in text:raise RuntimeError('Integration target changed: '+path)
    p.write_text(text.replace(old,new,1))

replace('api/import-apply.php',"require __DIR__.'/../server/import-single-csv.php';", "require __DIR__.'/../server/import-single-csv.php';\nrequire __DIR__.'/../server/product-photo-editor.php';")
replace('api/import-apply.php','$slice=array_slice($r,$offset,$limit);','$slice=array_slice($r,$offset,$limit);\n $manualPhotoOverrides=ppe_override_map($pdo);')
replace('api/import-apply.php',"$photoSet=single_csv_merge_photos($urls,$oldImgs,$oldMain);$final=$photoSet['images'];", "$photoSet=single_csv_merge_photos($urls,$oldImgs,$oldMain);if($ex&&isset($manualPhotoOverrides[(int)$ex['id']]))$photoSet=ppe_overlay($photoSet,$manualPhotoOverrides[(int)$ex['id']]);$final=$photoSet['images'];")
replace('admin/index.php','Диагностика без ручной загрузки','Поиск товаров и ручная загрузка фотографий')
replace('api/photo-health.php',"'admin_override'=>'disabled'","'admin_override'=>'explicit_product_selection'")
replace('api/photo-health.php',"'primary'=>'current_1c_mysql_only'","'primary'=>'current_1c_mysql_with_explicit_admin_photos'")
replace('.htaccess',"img-src 'self' data: https:;","img-src 'self' data: blob: https:;")
replace('server/product-photo-editor.php',r"/[\x00-\x20\x7f]/",r"/[\x00-\x1f\x7f]/")
for f in ['.github/workflows/store-tests.yml','.github/workflows/deploy-infinityfree.yml']:
    replace(f,'          node --check admin/orders.js','          node --check admin/photos.js\n          node --check admin/orders.js')
    replace(f,'          php tests/order-validation.php','          php tests/product-photos.php\n          php tests/order-validation.php')
    replace(f,'          python3 tests/orders-http.py','          python3 tests/orders-http.py\n          python3 tests/product-photos-http.py')
print('Integrated product photo ownership without changing unrelated catalogue behaviour.')
