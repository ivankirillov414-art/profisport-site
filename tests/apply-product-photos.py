from pathlib import Path

def patch(path, old, new):
    p=Path(path); s=p.read_text()
    if old in s:
        p.write_text(s.replace(old,new))
    elif new not in s:
        raise RuntimeError('Unexpected source change in '+path+': '+old[:90])

patch('server/product-photos.php', r'\x00-\x20', r'\x00-\x1F')
patch('api/catalog.php', "require __DIR__.'/../server/catalog-quality.php';", "require __DIR__.'/../server/catalog-quality.php';\nrequire_once __DIR__.'/../server/product-photos.php';")
patch('api/catalog.php', "header('Cache-Control: public, max-age=60, stale-while-revalidate=300');", "header('Cache-Control: no-cache, must-revalidate');")
patch('api/catalog.php', "  while($p=$stmt->fetch()){", "  foreach(pph_apply_rows($pdo,$stmt->fetchAll(PDO::FETCH_ASSOC)) as $p){")
patch('api/catalog.php', "    $local=local_import_file($url);", "    $manual=pph_photo_path($url);\n    if($manual!==null){if(is_file($manual))return true;$brokenLocal++;return false;}\n    $local=local_import_file($url);")
patch('api/catalog.php', "    $specs=json_decode", "    if(!empty($p['_photo_manual'])&&!$sourceImageMissing)$imageSource='manual';\n    $specs=json_decode")
patch('api/catalog.php', "      'image_source'=>$imageSource,", "      'image_source'=>$imageSource,\n      'manual_photo_override'=>!empty($p['_photo_manual']),\n      'photo_revision'=>(int)($p['_photo_revision']??0),")
patch('api/product-db-image.php', "$config=require $configFile;", "$config=require $configFile;\nrequire_once __DIR__.'/../server/product-photos.php';")
patch('api/product-db-image.php', 'SELECT name,brand,model,category_path,main_image,images', 'SELECT id,source_id,name,brand,model,category_path,main_image,images')
patch('api/product-db-image.php', "$rows=$st->fetchAll();", "$rows=pph_apply_rows($pdo,$st->fetchAll(PDO::FETCH_ASSOC));")
patch('api/product-db-image.php', "        $rel=pdi_local_rel($url);", "        $manual=pph_photo_path($url);\n        if($manual!==null){if(is_file($manual))return ['kind'=>'remote','value'=>$url];continue;}\n        $rel=pdi_local_rel($url);")
patch('api/product-db-image.php', "header('Cache-Control: public, max-age=1800, stale-while-revalidate=86400');", "header('Cache-Control: no-store');")
patch('api/photo-health.php', "require __DIR__.'/../server/bootstrap.php';", "require __DIR__.'/../server/bootstrap.php';\nrequire_once __DIR__.'/../server/product-photos.php';")
patch('api/photo-health.php', "  if($url==='')return false;", "  if($url==='')return false;\n  $manual=pph_photo_path($url);if($manual!==null)return is_file($manual);")
patch('api/photo-health.php', 'SELECT id,name,main_image,images,category_path', 'SELECT id,source_id,name,main_image,images,category_path')
patch('api/photo-health.php', "    'active_products'=>0,", "    'active_products'=>0,\n    'products_with_manual_gallery'=>0,")
patch('api/photo-health.php', "  while($p=$st->fetch(PDO::FETCH_ASSOC)){", "  foreach(pph_apply_rows($pdo,$st->fetchAll(PDO::FETCH_ASSOC)) as $p){\n    if(!empty($p['_photo_manual']))$stats['products_with_manual_gallery']++;")
patch('api/photo-health.php', "'primary'=>'current_1c_mysql_only'", "'primary'=>'manual_override_or_current_1c_mysql'")
patch('api/photo-health.php', "'admin_override'=>'disabled'", "'admin_override'=>'enabled'")
patch('admin/index.php','Диагностика без ручной загрузки','Просмотр, загрузка и замена фото товаров')
# A primary action for the already-primary image remains disabled after async refresh.
patch('admin/photos.js', "  $('photoFile').disabled=busy;", "  $('photoFile').disabled=busy;\n  const primary=$('photoGallery').querySelector('[data-photo=\"primary\"][data-index=\"0\"]');if(primary)primary.disabled=true;")
# Existing importer remains untouched. Manual gallery data is independent of its writes.
for filename in ['.github/workflows/store-tests.yml','.github/workflows/deploy-infinityfree.yml']:
    patch(filename,'          node --check admin/orders.js','          node --check admin/photos.js\n          php tests/product-photos-domain.php\n          node --check admin/orders.js')
patch('.github/workflows/deploy-infinityfree.yml', 'admin/customer-qr.php; do', 'admin/customer-qr.php admin/photos.php admin/photos.js api/product-photos.php server/product-photos.php; do')
print('Product photo integration applied')
