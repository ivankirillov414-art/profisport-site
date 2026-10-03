<?php
declare(strict_types=1);

/** Manual catalogue photos are public product assets, never customer documents. */
function ppe_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_photo_overrides (product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,manual_urls LONGTEXT NOT NULL,primary_url TEXT NULL,updated_by INT UNSIGNED NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function ppe_urls(mixed $value): array {
    if(is_string($value))$value=json_decode($value,true);
    if(!is_array($value))return [];
    return array_values(array_unique(array_filter($value,fn($v)=>is_string($v)&&trim($v)!=='')));
}
function ppe_candidates(array $p): array {
    return ppe_urls(array_merge([(string)($p['main_image']??'')],ppe_urls($p['images']??[])));
}
function ppe_safe_url(string $url): string {
    $url=trim($url);
    if(preg_match('/[\x00-\x20\x7f]/',$url))return '';
    if(preg_match('~^https://[^/]+/~i',$url))return filter_var($url,FILTER_VALIDATE_URL)?$url:'';
    if(str_starts_with($url,'import/'))$url='/'.$url;
    if(!str_starts_with($url,'/import/'))return '';
    $path=rawurldecode((string)parse_url($url,PHP_URL_PATH));
    if(str_contains($path,"\0")||str_contains($path,'\\')||in_array('..',explode('/',$path),true))return '';
    // Escape spaces and non-ASCII characters for browser image URLs.
    return implode('/',array_map('rawurlencode',explode('/',$path)));
}
function ppe_working(string $url,?string $root=null): bool {
    $url=ppe_safe_url($url);if($url==='')return false;
    $path=rawurldecode((string)parse_url($url,PHP_URL_PATH));
    if(preg_match('~(?:default-no-image|no-image|placeholder)\.[a-z]+$~i',$path))return false;
    if(str_starts_with($url,'https://'))return true; // A reference, not a network availability guarantee.
    $root=realpath($root??__DIR__.'/../import');if($root===false)return false;
    $file=realpath($root.'/'.substr($path,8));
    return $file!==false&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&is_file($file);
}
function ppe_manual_url(string $url): bool {
    return preg_match('~^/import/curated-photos/[1-9][0-9]*/[a-f0-9]{64}\.(jpg|png|webp)$~D',$url)===1;
}
function ppe_revision(array $p,array $override=[]): string {
    return hash('sha256',json_encode([(string)($p['main_image']??''),ppe_urls($p['images']??[]),ppe_urls($override['manual_urls']??[]),(string)($override['primary_url']??'')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
/** Applied AFTER source-image merge, so later CSV imports cannot undo an explicit choice. */
function ppe_overlay(array $source,array $override): array {
    $manual=ppe_urls($override['manual_urls']??[]);
    $primary=trim((string)($override['primary_url']??''));
    $images=ppe_urls(array_merge($manual,ppe_urls($source['images']??[])));
    $main=$primary!==''?$primary:($source['main']??($images[0]??null));
    if($main!==null&&$main!=='')$images=ppe_urls(array_merge([$main],$images));
    return ['images'=>$images,'main'=>$main];
}
function ppe_override_map(PDO $pdo): array {
    ppe_schema($pdo);$map=[];
    foreach($pdo->query('SELECT product_id,manual_urls,primary_url FROM product_photo_overrides') as $r)$map[(int)$r['product_id']]=$r;
    return $map;
}
function ppe_limit_bytes(): int {
    $parse=static function(string $v):int{$v=trim($v);$n=(float)$v;if($n<=0)return 8*1048576;return (int)($n*(['k'=>1024,'m'=>1048576,'g'=>1073741824][strtolower(substr($v,-1))]??1));};
    return max(1,min(8*1048576,$parse((string)ini_get('upload_max_filesize')),$parse((string)ini_get('post_max_size'))-65536));
}
/** Decode and re-encode; client MIME and filenames never determine the stored path. */
function ppe_image_bytes(string $file,int $limit): array {
    if(!is_file($file)||filesize($file)<1||filesize($file)>$limit)throw new InvalidArgumentException('Файл пуст или превышает допустимый размер.');
    if(!function_exists('imagecreatefromstring'))throw new RuntimeException('На хостинге недоступна обработка изображений GD.');
    $info=@getimagesize($file);
    $allowed=[IMAGETYPE_JPEG=>'jpg',IMAGETYPE_PNG=>'png',IMAGETYPE_WEBP=>'webp'];
    if(!$info||!isset($allowed[$info[2]]))throw new InvalidArgumentException('Выберите настоящее изображение JPG, PNG или WebP.');
    $w=(int)$info[0];$h=(int)$info[1];
    if($w<1||$h<1||$w>10000||$h>10000||$w*$h>12000000)throw new InvalidArgumentException('Изображение слишком большое: максимум 12 мегапикселей.');
    $raw=file_get_contents($file);$im=$raw!==false?@imagecreatefromstring($raw):false;
    if(!$im)throw new InvalidArgumentException('Изображение повреждено.');
    if($info[2]===IMAGETYPE_JPEG&&function_exists('exif_read_data')){
        $exif=@exif_read_data($file);$orientation=(int)($exif['Orientation']??1);
        if(in_array($orientation,[2,4,5,7],true))imageflip($im,IMG_FLIP_HORIZONTAL);
        $angle=[3=>180,4=>180,5=>90,6=>-90,7=>-90,8=>90][$orientation]??0;
        if($angle){$rotated=imagerotate($im,$angle,0);if($rotated){imagedestroy($im);$im=$rotated;}}
    }
    $w=imagesx($im);$h=imagesy($im);$scale=min(1,2000/max($w,$h));
    $copy=imagecreatetruecolor(max(1,(int)round($w*$scale)),max(1,(int)round($h*$scale)));
    imagealphablending($copy,false);imagesavealpha($copy,true);
    imagefilledrectangle($copy,0,0,imagesx($copy),imagesy($copy),imagecolorallocatealpha($copy,0,0,0,127));
    imagecopyresampled($copy,$im,0,0,0,0,imagesx($copy),imagesy($copy),$w,$h);imagedestroy($im);
    $ext=$allowed[$info[2]];ob_start();
    try{$ok=match($ext){'jpg'=>imagejpeg($copy,null,90),'png'=>imagepng($copy,null,6),'webp'=>function_exists('imagewebp')?imagewebp($copy,null,88):false};$bytes=(string)ob_get_contents();}
    finally{ob_end_clean();imagedestroy($copy);}
    if(!$ok||$bytes==='')throw new RuntimeException('Не удалось подготовить изображение.');
    return ['bytes'=>$bytes,'ext'=>$ext,'hash'=>hash('sha256',$bytes)];
}
function ppe_store_image(int $id,array $image,?string $root=null): string {
    if($id<=0)throw new InvalidArgumentException('Неверный товар.');
    $root=$root??__DIR__.'/../import';$base=$root.'/curated-photos';$dir=$base.'/'.$id;
    if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Нет доступа к папке фотографий.');
    $rules="Options -Indexes\n<FilesMatch \"\\.(php[0-9]*|phtml|phar|cgi|pl|shtml)(\\.|$)\">\nRequire all denied\n</FilesMatch>\n";
    if(!is_file($base.'/.htaccess')&&file_put_contents($base.'/.htaccess',$rules,LOCK_EX)===false)throw new RuntimeException('Не удалось защитить папку фотографий.');
    $name=$image['hash'].'.'.$image['ext'];$target=$dir.'/'.$name;
    if(!is_file($target)){
        $temp=tempnam($dir,'.upload-');if($temp===false)throw new RuntimeException('Нет места для фотографии.');
        try{if(file_put_contents($temp,$image['bytes'],LOCK_EX)!==strlen($image['bytes']))throw new RuntimeException('Фотография записана не полностью.');if(!rename($temp,$target))throw new RuntimeException('Не удалось сохранить фотографию.');chmod($target,0644);}finally{if(is_file($temp))unlink($temp);}
    }
    return '/import/curated-photos/'.$id.'/'.$name;
}
