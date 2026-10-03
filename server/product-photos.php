<?php
declare(strict_types=1);

// Manual galleries are separate from products: imports and source reconciliation
// keep their own data. Resetting a gallery reveals the LATEST source photographs.
function pph_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_photo_overrides (
      product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      source_identity VARCHAR(255) NOT NULL DEFAULT '',
      enabled TINYINT(1) NOT NULL DEFAULT 0,
      gallery LONGTEXT NOT NULL,
      revision INT UNSIGNED NOT NULL DEFAULT 0,
      last_request CHAR(64) NULL,
      last_fingerprint CHAR(64) NULL,
      updated_by INT UNSIGNED NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function pph_error(string $message,int $status=400): never {throw new RuntimeException($message,$status);}
function pph_urls(array $row): array {
    $gallery=is_array($row['images']??null)?$row['images']:json_decode((string)($row['images']??'[]'),true);
    $urls=[];
    foreach(array_merge([$row['main_image']??''],is_array($gallery)?$gallery:[]) as $value){
        if(!is_string($value))continue;$url=trim($value);
        if($url===''||strlen($url)>2000||preg_match('/[\x00-\x1F\x7f<>"\x27]/',$url))continue;
        if(preg_match('~^https://[^/]+/~i',$url)||preg_match('~^/?(?:import|uploads|assets)/~',$url)||str_starts_with($url,'api/product-image.php?'))$urls[]=$url;
    }
    return array_values(array_unique($urls));
}
function pph_view(array $product,?array $override): array {
    $identity=(string)($product['source_id']??'');
    $manual=$override&&(int)$override['enabled']===1&&(string)$override['source_identity']===$identity;
    $images=$manual?pph_urls(['images'=>$override['gallery']]):pph_urls($product);
    return ['id'=>(int)($product['id']??$product['product_id']),'name'=>(string)($product['name']??''),
      'sku'=>(string)($product['sku']??''),'source_id'=>$identity,'category'=>(string)($product['category_path']??''),
      'active'=>(int)($product['is_active']??1)===1,'images'=>$images,'manual'=>(bool)$manual,
      'revision'=>(int)($override['revision']??0),'source_count'=>count(pph_urls($product))];
}
function pph_apply_rows(PDO $pdo,array $products): array {
    if(!$products)return [];
    $ids=array_values(array_unique(array_filter(array_map(fn($r)=>(int)($r['id']??$r['product_id']??0),$products))));$overrides=[];
    try{
        foreach(array_chunk($ids,500) as $chunk){
            $q=$pdo->prepare('SELECT * FROM product_photo_overrides WHERE product_id IN ('.implode(',',array_fill(0,count($chunk),'?')).')');$q->execute($chunk);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $o)$overrides[(int)$o['product_id']]=$o;
        }
    }catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;return $products;}
    foreach($products as &$p){
        $id=(int)($p['id']??$p['product_id']??0);$o=$overrides[$id]??null;
        if(!$o||(int)$o['enabled']!==1||(string)$o['source_identity']!==(string)($p['source_id']??''))continue;
        $v=pph_view($p,$o);$p['images']=json_encode($v['images'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $p['main_image']=$v['images'][0]??null;$p['_photo_manual']=true;$p['_photo_revision']=$v['revision'];
    }unset($p);return $products;
}
function pph_get(PDO $pdo,int $id): array {
    $q=$pdo->prepare('SELECT id,source_id,name,sku,category_path,is_active,main_image,images FROM products WHERE id=?');$q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
    if(!$p)pph_error('Товар не найден.',404);
    $q=$pdo->prepare('SELECT * FROM product_photo_overrides WHERE product_id=?');$q->execute([$id]);
    return pph_view($p,$q->fetch(PDO::FETCH_ASSOC)?:null);
}
function pph_ini_bytes(string $raw,int $unlimited=PHP_INT_MAX): int {
    $raw=trim($raw);if((float)$raw<=0)return $unlimited;
    $factor=['k'=>1024,'m'=>1048576,'g'=>1073741824][strtolower(substr($raw,-1))]??1;
    return (int)min(PHP_INT_MAX,(float)$raw*$factor);
}
function pph_limits(): array {
    return ['max_file_bytes'=>max(1,min(5*1048576,pph_ini_bytes((string)ini_get('upload_max_filesize')),pph_ini_bytes((string)ini_get('post_max_size'))-65536)),
      'formats'=>['image/jpeg','image/png','image/webp'],'max_photos'=>60,'image_processing'=>function_exists('imagecreatefromstring')&&function_exists('imagepng')&&class_exists('finfo')];
}
function pph_photo_path(string $url): ?string {
    if(!preg_match('~^/uploads/product-photos/[1-9][0-9]*/[a-f0-9]{64}\.(jpg|png|webp)$~D',$url))return null;
    return dirname(__DIR__).$url;
}
function pph_store_image(string $input,int $productId,?string $siteRoot=null): string {
    if(!pph_limits()['image_processing'])pph_error('На хостинге недоступна обработка фотографий (GD/Fileinfo).',503);
    if(!is_file($input)||filesize($input)<1||filesize($input)>pph_limits()['max_file_bytes'])pph_error('Фотография превышает допустимый размер.',413);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($input);
    if(!in_array($mime,pph_limits()['formats'],true))pph_error('Поддерживаются настоящие изображения JPG, PNG и WebP.');
    $info=@getimagesize($input);$w=(int)($info[0]??0);$h=(int)($info[1]??0);
    if($w<1||$h<1||$w>8000||$h>8000||$w*$h>20000000)pph_error('Слишком большое изображение. Уменьшите его до 20 мегапикселей.');
    if($w*$h*8+memory_get_usage(true)+24*1048576>pph_ini_bytes((string)ini_get('memory_limit')))pph_error('Изображение слишком велико для памяти хостинга. Уменьшите разрешение.');
    $image=@imagecreatefromstring((string)file_get_contents($input));if(!$image)pph_error('Не удалось прочитать изображение.');
    $small=null;$tmp=null;
    try{
        // Strip metadata and executable trailing content by decoding and re-encoding.
        if($mime==='image/jpeg'&&function_exists('exif_read_data')){
            $exif=@exif_read_data($input);$orientation=(int)($exif['Orientation']??1);
            if(in_array($orientation,[2,4,5,7],true))imageflip($image,in_array($orientation,[4,7],true)?IMG_FLIP_VERTICAL:IMG_FLIP_HORIZONTAL);
            $angle=[3=>180,4=>0,5=>-90,6=>-90,7=>-90,8=>90][$orientation]??0;
            if($angle){$rotated=imagerotate($image,$angle,0);if($rotated){imagedestroy($image);$image=$rotated;}}
        }
        $w=imagesx($image);$h=imagesy($image);$scale=min(1,2200/max($w,$h));
        $small=imagecreatetruecolor(max(1,(int)round($w*$scale)),max(1,(int)round($h*$scale)));
        imagealphablending($small,false);imagesavealpha($small,true);imagefill($small,0,0,imagecolorallocatealpha($small,0,0,0,127));
        if(!imagecopyresampled($small,$image,0,0,0,0,imagesx($small),imagesy($small),$w,$h))pph_error('Не удалось обработать фотографию.',503);
        $ext=$mime==='image/jpeg'?'jpg':($mime==='image/webp'&&function_exists('imagewebp')?'webp':'png');
        $tmp=tempnam(sys_get_temp_dir(),'psphoto-');if(!$tmp)pph_error('Не удалось создать временный файл.',503);
        $ok=match($ext){'jpg'=>imagejpeg($small,$tmp,90),'webp'=>imagewebp($small,$tmp,90),default=>imagepng($small,$tmp,6)};
        clearstatcache(true,$tmp);if(!$ok||!is_file($tmp)||filesize($tmp)<1||!@getimagesize($tmp))pph_error('Не удалось сохранить обработанную фотографию.',503);
        $base=($siteRoot??dirname(__DIR__)).'/uploads/product-photos';
        if(!is_dir($base)&&!mkdir($base,0755,true)&&!is_dir($base))pph_error('Нет доступа на запись в папку фотографий.',503);
        $rules="Options -Indexes\n<FilesMatch \"\\.(php[0-9]*|phtml|phar|cgi|shtml)(\\.|$)\">\nRequire all denied\n</FilesMatch>\n";
        if(!is_file($base.'/.htaccess')&&file_put_contents($base.'/.htaccess',$rules,LOCK_EX)===false)pph_error('Не удалось защитить папку фотографий.',503);
        $dir=$base.'/'.$productId;if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))pph_error('Не удалось создать папку товара.',503);
        $name=hash_file('sha256',$tmp).'.'.$ext;$target=$dir.'/'.$name;
        if(!is_file($target)&&!rename($tmp,$target))pph_error('Не удалось сохранить фотографию.',503);
        @chmod($target,0644);return '/uploads/product-photos/'.$productId.'/'.$name;
    }finally{if($tmp&&is_file($tmp))@unlink($tmp);if($small)imagedestroy($small);imagedestroy($image);}
}
function pph_mutate(PDO $pdo,int $id,int $actor,string $action,array $input,?string $upload=null): array {
    if(!in_array($action,['upload','replace','primary','remove','reset'],true))pph_error('Неизвестная операция.');
    $rev=filter_var($input['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    $request=(string)($input['request_id']??'');
    if($rev===false||!preg_match('/^[a-f0-9-]{36}$/iD',$request))pph_error('Обновите карточку перед изменением фотографий.');
    if(in_array($action,['upload','replace'],true)&&(!$upload||!is_file($upload)))pph_error('Выберите фотографию.');
    $target=(string)($input['target']??'');$key=hash('sha256',$actor.'|'.$request);
    $fingerprint=hash('sha256',json_encode([$id,$action,$rev,$target,$upload?hash_file('sha256',$upload):'']));
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id,source_id,name,sku,category_path,is_active,main_image,images FROM products WHERE id=? FOR UPDATE');$q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);if(!$p)pph_error('Товар не найден.',404);
        $q=$pdo->prepare("INSERT IGNORE INTO product_photo_overrides(product_id,gallery) VALUES(?,'[]')");$q->execute([$id]);
        $q=$pdo->prepare('SELECT * FROM product_photo_overrides WHERE product_id=? FOR UPDATE');$q->execute([$id]);$o=$q->fetch(PDO::FETCH_ASSOC);
        if(($o['last_request']??'')===$key){if(!hash_equals((string)$o['last_fingerprint'],$fingerprint))pph_error('Код операции уже использован для другого изменения.',409);$pdo->commit();return pph_view($p,$o);}
        if((int)$o['revision']!==$rev)pph_error('Фотографии уже изменены в другой вкладке. Обновите карточку.',409);
        $gallery=pph_view($p,$o)['images'];$index=array_search($target,$gallery,true);$enabled=1;
        if(in_array($action,['replace','primary','remove'],true)&&$index===false)pph_error('Этой фотографии больше нет в карточке. Обновите её.',409);
        if($action==='upload'||$action==='replace'){
            if($action==='upload'&&count($gallery)>=60)pph_error('В галерее уже 60 фотографий. Удалите лишние.');
            $url=pph_store_image((string)$upload,$id);
            if($action==='replace')$gallery[$index]=$url;elseif(!in_array($url,$gallery,true))$gallery[]=$url;
            $gallery=array_values(array_unique($gallery));
        }elseif($action==='primary'){unset($gallery[$index]);array_unshift($gallery,$target);$gallery=array_values($gallery);}
        elseif($action==='remove'){unset($gallery[$index]);$gallery=array_values($gallery);}
        elseif($action==='reset'){$enabled=0;$gallery=[];}
        $q=$pdo->prepare('UPDATE product_photo_overrides SET source_identity=?,enabled=?,gallery=?,revision=revision+1,last_request=?,last_fingerprint=?,updated_by=?,updated_at=NOW() WHERE product_id=?');
        $q->execute([(string)($p['source_id']??''),$enabled,json_encode($gallery,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$key,$fingerprint,$actor,$id]);
        $pdo->prepare('UPDATE products SET updated_at=NOW() WHERE id=?')->execute([$id]);
        $pdo->commit();
        if(function_exists('audit'))audit($pdo,'product_photo_'.$action,'products',(string)$id,['photos'=>count($gallery),'manual'=>(bool)$enabled,'revision'=>$rev+1]);
        return pph_get($pdo,$id);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
