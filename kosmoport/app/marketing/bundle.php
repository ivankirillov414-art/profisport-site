<?php
declare(strict_types=1);

const MK_BUNDLE_MAX_BYTES=8*1024*1024;
const MK_BUNDLE_PREVIEWS=['simaz-cmyk.jpg','liaz-nefaz-cmyk.jpg','poster-a3-cmyk.jpg','poster-a2-cmyk.jpg'];

function mk_bundle_owner(): array {
    $actor=crm_require_roles(KCRM_ROLES);
    if (($actor['role']??'')!=='owner') crm_fail('FORBIDDEN','Загрузить подготовленную базу может только владелец.',403);
    mk_actor(mk_read_file(),true,true);
    return $actor;
}

function mk_upload_bundle(array $files,array $fields): array {
    mk_bundle_owner();
    $upload=$files['bundle']??null;
    if (!is_array($upload)||!is_int($upload['error']??null)||$upload['error']!==UPLOAD_ERR_OK) crm_fail('BUNDLE_UPLOAD_FAILED','Не удалось получить ZIP. Выберите подготовленный файл ещё раз.',422);
    if (!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name'])) crm_fail('BUNDLE_UPLOAD_FAILED','Нужен ZIP, загруженный через форму CRM.',422);
    $revision=$fields['expected_revision']??null;
    if (!is_string($revision)||!preg_match('/^[1-9][0-9]{0,8}$/D',$revision)) crm_fail('REVISION_REQUIRED','Обновите раздел перед загрузкой ZIP.',422);
    return mk_stage_bundle($upload['tmp_name'],(int)$revision);
}

function mk_bundle_cleanup(string $stage): void {
    $parent=crm_storage_path('marketing');
    if (dirname($stage)!==$parent||!preg_match('/^bundle-stage-[a-f0-9]{24}$/D',basename($stage))) return;
    foreach (['workspace.json',...array_map(static fn(string $name): string=>'previews/'.$name,MK_BUNDLE_PREVIEWS)] as $relative) {
        $file=$stage.'/'.$relative;
        if (is_file($file)||is_link($file)) @unlink($file);
    }
    if (is_dir($stage.'/previews')) @rmdir($stage.'/previews');
    if (is_dir($stage)) @rmdir($stage);
}

function mk_stage_bundle(string $archive,int $expectedRevision): array {
    $actor=mk_bundle_owner();
    if (!class_exists('ZipArchive')) crm_fail('ZIP_UNAVAILABLE','На сервере недоступно безопасное чтение ZIP. База не изменена.',503);
    if (!is_file($archive)||is_link($archive)||filesize($archive)<22||filesize($archive)>MK_BUNDLE_MAX_BYTES) crm_fail('BUNDLE_TOO_LARGE','ZIP должен быть не больше 8 МБ.',413);
    $zip=new ZipArchive();
    if ($zip->open($archive,ZipArchive::RDONLY)!==true) crm_fail('INVALID_BUNDLE','Не удалось прочитать ZIP. База не изменена.',422);
    $payload=[];$seen=[];$expanded=0;
    try {
        if($zip->numFiles<1||$zip->numFiles>10)crm_fail('BUNDLE_FILE_LIMIT','В пакете допускается не более 10 файлов.',422);
        $allowed=['workspace.json',...array_map(static fn(string $name): string=>'previews/'.$name,MK_BUNDLE_PREVIEWS)];
        for($i=0;$i<$zip->numFiles;$i++){
            $stat=$zip->statIndex($i);if(!is_array($stat)||!is_string($stat['name']??null))crm_fail('INVALID_BUNDLE','Не удалось прочитать состав ZIP.',422);
            $name=$stat['name'];$folded=strtolower($name);
            if(isset($seen[$folded]))crm_fail('BUNDLE_DUPLICATE','В ZIP повторяется имя файла.',422);$seen[$folded]=true;
            if(!in_array($name,$allowed,true))crm_fail('BUNDLE_PATH_REJECTED','ZIP должен содержать только workspace.json и утверждённые JPEG-превью, без дополнительных папок и файлов.',422);
            $opsys=0;$attributes=0;
            if(!$zip->getExternalAttributesIndex($i,$opsys,$attributes))crm_fail('INVALID_BUNDLE','Не удалось проверить тип файла ZIP.',422);
            $mode=($attributes>>16)&0170000;
            if($mode!==0&&$mode!==0100000)crm_fail('BUNDLE_SPECIAL_FILE','Ссылки и специальные файлы в ZIP запрещены.',422);
            if(!empty($stat['encryption_method']))crm_fail('BUNDLE_ENCRYPTED','ZIP с паролем не поддерживается.',422);
            $limit=$name==='workspace.json'?MK_MAX_BYTES:2*1024*1024;
            if(!is_int($stat['size']??null)||$stat['size']<1||$stat['size']>$limit||($expanded+=$stat['size'])>MK_BUNDLE_MAX_BYTES)crm_fail('BUNDLE_TOO_LARGE','Распакованный пакет превышает допустимый размер 8 МБ.',413);
            $bytes=$zip->getFromIndex($i,$limit+1);
            if(!is_string($bytes)||strlen($bytes)!==$stat['size']||sprintf('%u',crc32($bytes))!==sprintf('%u',$stat['crc']))crm_fail('INVALID_BUNDLE','Один из файлов ZIP повреждён.',422);
            $payload[$name]=$bytes;
        }
    } finally {$zip->close();}
    if(!isset($payload['workspace.json']))crm_fail('BUNDLE_SEED_MISSING','В ZIP отсутствует workspace.json.',422);
    try{$seed=mk_validate_seed(json_decode($payload['workspace.json'],true,32,JSON_THROW_ON_ERROR));}
    catch(JsonException){crm_fail('INVALID_IMPORT','workspace.json содержит некорректный JSON.',422);}
    mk_actor($seed,true,true);
    $required=['workspace.json'];
    foreach($seed['assets'] as $asset){
        $preview=$asset['preview_file']??'';
        if(!is_string($preview)||!in_array($preview,array_map(static fn(string $name): string=>'previews/'.$name,MK_BUNDLE_PREVIEWS),true))crm_fail('BUNDLE_PREVIEW_REFERENCE','Карточка макета должна ссылаться на утверждённое JPEG-превью.',422);
        if(in_array($preview,$required,true))crm_fail('BUNDLE_PREVIEW_REFERENCE','Несколько макетов ссылаются на одно превью.',422);
        $required[]=$preview;
    }
    $actual=array_keys($payload);sort($required);sort($actual);
    if($required!==$actual)crm_fail('BUNDLE_PREVIEW_MISSING','Состав превью должен точно соответствовать карточкам макетов.',422);
    $trusted=crm_config('marketing.approved_preview_sha256');
    if($trusted===null)$trusted=require __DIR__.'/approved-previews.php';
    if(!is_array($trusted))crm_fail('PREVIEW_CONFIG_INVALID','Не настроена проверка утверждённых превью.',503);
    foreach($payload as $name=>$bytes)if($name!=='workspace.json'){
        $image=@getimagesizefromstring($bytes);
        if(!is_array($image)||($image[2]??null)!==IMAGETYPE_JPEG||($image['mime']??'')!=='image/jpeg'||$image[0]<16||$image[1]<16||$image[0]>2400||$image[1]>2400||$image[0]*$image[1]>4000000||!str_starts_with($bytes,"\xFF\xD8")||!str_ends_with($bytes,"\xFF\xD9"))crm_fail('BUNDLE_INVALID_IMAGE','Превью должно быть завершённым JPEG размером от 16 до 2400 пикселей, не более 4 млн пикселей.',422);
        $expectedHash=$trusted[basename($name)]??null;
        if(!is_string($expectedHash)||!preg_match('/^[a-fA-F0-9]{64}$/D',$expectedHash)||!hash_equals(strtolower($expectedHash),hash('sha256',$bytes)))crm_fail('BUNDLE_UNAPPROVED_IMAGE','Превью не совпадает с утверждённым файлом. Используйте исходный подготовленный пакет без перекодирования изображений.',422);
    }
    $directory=crm_storage_path('marketing');
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))crm_fail('STORAGE_UNAVAILABLE','Приватное хранилище недоступно.',503);
    $stage=crm_storage_path('marketing/bundle-stage-'.bin2hex(random_bytes(12)));
    if(!mkdir($stage,0700))crm_fail('STORAGE_UNAVAILABLE','Не удалось подготовить приватную загрузку.',503);
    $lock=null;$committed=false;$previewPublished=false;
    try {
        foreach($payload as $name=>$bytes){
            if(str_starts_with($name,'previews/')&&!is_dir($stage.'/previews')&&!mkdir($stage.'/previews',0700))crm_fail('STORAGE_UNAVAILABLE','Не удалось подготовить превью.',503);
            if(file_put_contents($stage.'/'.$name,$bytes)!==strlen($bytes))crm_fail('STORAGE_UNAVAILABLE','Не удалось сохранить пакет. База не изменена.',503);
        }
        $lock=fopen(crm_storage_path('marketing/workspace.lock'),'c+');if($lock===false)crm_fail('STORAGE_UNAVAILABLE','Не удалось заблокировать хранилище.',503);
        $deadline=microtime(true)+3;while(!flock($lock,LOCK_EX|LOCK_NB)){if(microtime(true)>$deadline)crm_fail('WORKSPACE_BUSY','Хранилище занято. Повторите через несколько секунд.',409);usleep(20000);}
        $current=mk_read_file();mk_actor($current,true,true);
        if(file_exists(crm_storage_path('marketing/workspace.json')))crm_fail('WORKSPACE_EXISTS','Рабочая база уже создана. ZIP не перезаписывает её.',409);
        if($current['revision']!==$expectedRevision)crm_fail('REVISION_CONFLICT','Состояние изменилось. Обновите раздел перед загрузкой.',409);
        if(file_exists(crm_storage_path('marketing/import.json')))crm_fail('BUNDLE_ALREADY_STAGED','Подготовленный пакет уже загружен. Обновите раздел и проверьте его состав перед инициализацией.',409);
        $previewDirectory=crm_storage_path('marketing/previews');
        if(file_exists($previewDirectory))crm_fail('BUNDLE_TARGET_EXISTS','В хранилище уже есть папка превью. Её содержимое не перезаписано; требуется проверка владельцем.',409);
        if(is_dir($stage.'/previews')){
            if(!rename($stage.'/previews',$previewDirectory))crm_fail('STORAGE_UNAVAILABLE','Не удалось перенести проверенные превью. База не изменена.',503);
            $previewPublished=true;
        }
        // The marker is published last: initialization never sees a partial bundle.
        if(!rename($stage.'/workspace.json',crm_storage_path('marketing/import.json')))crm_fail('STORAGE_UNAVAILABLE','Не удалось завершить подготовку пакета. Рабочая база не создана.',503);
        $committed=true;
        try{crm_audit('marketing.bundle.staged','marketing',null,['files'=>count($payload),'expanded_bytes'=>$expanded]);}catch(Throwable){error_log('Kosmoport marketing: bundle staged, database audit unavailable.');}
        return ['staged'=>true,'initialized'=>false,'revision'=>$current['revision'],'counts'=>mk_seed_counts($seed),'files'=>count($payload),'expanded_bytes'=>$expanded];
    } finally {
        if(!$committed&&$previewPublished){
            foreach($payload as $name=>$bytes)if(str_starts_with($name,'previews/')){$target=crm_storage_path('marketing/'.$name);if(is_file($target)&&!is_link($target)&&hash_file('sha256',$target)===hash('sha256',$bytes))@unlink($target);}
            @rmdir(crm_storage_path('marketing/previews'));
        }
        if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
        mk_bundle_cleanup($stage);
    }
}
