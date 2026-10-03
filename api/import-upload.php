<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/import-upload.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function upload_out(array $data,int $status=200): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function upload_fail(string $message,int $status=400): never { upload_out(['ok'=>false,'error'=>$message],$status); }
function upload_files_array(): array {
    $raw=$_FILES['files']??null;
    if(!$raw)return [];
    if(!is_array($raw['name']??null))return [[
        'name'=>$raw['name']??'','tmp_name'=>$raw['tmp_name']??'','error'=>$raw['error']??UPLOAD_ERR_NO_FILE,'size'=>$raw['size']??0
    ]];
    $out=[];foreach($raw['name'] as $i=>$name)$out[]=['name'=>$name,'tmp_name'=>$raw['tmp_name'][$i]??'','error'=>$raw['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$raw['size'][$i]??0];
    return $out;
}
function upload_image_valid(string $tmp,string $path): bool {
    $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
    $types=['jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp'],'gif'=>['image/gif'],'avif'=>['image/avif','image/avif-sequence']];
    $finfo=function_exists('finfo_open')?finfo_open(FILEINFO_MIME_TYPE):false;
    $mime=$finfo?(string)finfo_file($finfo,$tmp):'';
    if($finfo)finfo_close($finfo);
    if($mime!==''&&in_array($mime,$types[$ext]??[],true))return true;
    $info=@getimagesize($tmp);
    return is_array($info)&&str_starts_with((string)($info['mime']??''),'image/');
}

try{
    if($_SERVER['REQUEST_METHOD']!=='POST')upload_fail('Разрешён только POST.',405);
    if(($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site')upload_fail('Запрос с другого сайта отклонён.',403);
    $admin=require_admin();csrf_check();
    $action=(string)($_GET['action']??'');
    $importRoot=__DIR__.'/../import';
    import_upload_mkdir($importRoot);
    import_upload_mkdir($importRoot.'/.staging');

    if($action==='start'){
        $batch=bin2hex(random_bytes(16));
        $stage=import_upload_stage_dir($importRoot,$batch);import_upload_mkdir($stage);
        upload_out(['ok'=>true,'batch'=>$batch,'limits'=>import_upload_limits()]);
    }

    $batch=(string)($_GET['batch']??'');
    if(!import_upload_batch_valid($batch))upload_fail('Некорректный код загрузки.');
    $stage=import_upload_stage_dir($importRoot,$batch);
    if(!is_dir($stage))upload_fail('Загрузка не найдена или уже завершена.',404);

    if($action==='cancel'){
        import_upload_remove_tree($stage);
        upload_out(['ok'=>true]);
    }

    if($action==='upload'){
        $files=upload_files_array();$paths=$_POST['paths']??[];
        if(!$files||!is_array($paths)||count($files)!==count($paths)||count($files)>25)upload_fail('Некорректный пакет файлов.');
        $saved=[];$chunkBytes=0;
        foreach($files as $i=>$file){
            $error=(int)$file['error'];if($error!==UPLOAD_ERR_OK){
                $detail=in_array($error,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)?'Файл больше лимита хостинга.':'Ошибка загрузки файла.';
                throw new RuntimeException($detail);
            }
            $tmp=(string)$file['tmp_name'];if(!is_uploaded_file($tmp))throw new RuntimeException('Сервер не получил файл.');
            $relative=import_upload_sanitize_path((string)$paths[$i]);
            $size=(int)$file['size'];$ext=strtolower(pathinfo($relative,PATHINFO_EXTENSION));
            $limit=import_upload_limits()['max_file_bytes'];
            if($size<1||$size>$limit)throw new RuntimeException('Недопустимый размер файла '.$relative);
            $chunkBytes+=$size;if($chunkBytes>import_upload_limits()['max_batch_bytes'])throw new RuntimeException('Пакет слишком большой. Уменьшите количество файлов за одну загрузку.');
            if(import_upload_is_image($relative)&&!upload_image_valid($tmp,$relative))throw new RuntimeException('Файл не является допустимым изображением: '.$relative);
            $dest=$stage.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative).'.part';
            import_upload_mkdir(dirname($dest));
            if(is_file($dest))@unlink($dest);
            if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('Не удалось сохранить '.$relative);
            @chmod($dest,0644);$saved[]=$relative;
        }
        upload_out(['ok'=>true,'saved'=>count($saved),'bytes'=>$chunkBytes]);
    }

    if($action==='finalize'){
        $result=import_upload_finalize_stage($importRoot,$batch);
        $cache=__DIR__.'/../uploads/1c-image-index.json';if(is_file($cache))@unlink($cache);
        $meta=['source'=>'admin_upload','folder'=>$result['folder'],'files'=>$result['files'],'images'=>$result['images'],'bytes'=>$result['bytes'],'uploaded_at'=>date(DATE_ATOM)];
        $stmt=$pdo->prepare("INSERT INTO site_settings(setting_key,setting_value) VALUES('last_admin_1c_upload',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $stmt->execute([json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        audit($pdo,'admin_1c_upload','catalog',null,$meta);
        upload_out(['ok'=>true]+$result);
    }

    upload_fail('Неизвестная операция.',404);
}catch(Throwable $e){
    error_log('import_upload: '.$e->__toString());
    upload_out(['ok'=>false,'error'=>$e instanceof RuntimeException||$e instanceof InvalidArgumentException?$e->getMessage():'Ошибка сервера при загрузке.'],500);
}
