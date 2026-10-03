<?php
declare(strict_types=1);

function import_upload_batch_valid(string $batch): bool {
    return preg_match('/^[a-f0-9]{32}$/D',$batch)===1;
}

function import_upload_sanitize_path(string $path): string {
    $path=str_replace('\\','/',trim($path));
    if($path===''||strlen($path)>900||str_contains($path,"\0"))throw new InvalidArgumentException('Некорректный путь файла.');
    $parts=explode('/',$path);$safe=[];
    foreach($parts as $part){
        $part=trim($part);
        if($part===''||$part==='.'||$part==='..'||str_starts_with($part,'.'))throw new InvalidArgumentException('Недопустимый путь файла.');
        if(strlen($part)>180||preg_match('/[<>:"|?*\x00-\x1F]/u',$part))throw new InvalidArgumentException('Недопустимое имя файла.');
        if(preg_match('/\.(php[0-9]*|phtml|phar|cgi|shtml)(\.|$)/i',$part))throw new InvalidArgumentException('Недопустимое имя файла.');
        $safe[]=$part;
    }
    $result=implode('/',$safe);
    $ext=strtolower(pathinfo($result,PATHINFO_EXTENSION));
    $allowed=['csv','jpg','jpeg','png','webp','gif','avif'];
    if(!in_array($ext,$allowed,true))throw new InvalidArgumentException('Неподдерживаемый тип файла: .'.$ext);
    return $result;
}

function import_upload_is_image(string $path): bool {
    return in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['jpg','jpeg','png','webp','gif','avif'],true);
}

function import_upload_stage_dir(string $importRoot,string $batch): string {
    if(!import_upload_batch_valid($batch))throw new InvalidArgumentException('Некорректный код загрузки.');
    return rtrim($importRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.staging'.DIRECTORY_SEPARATOR.$batch;
}

function import_upload_mkdir(string $dir): void {
    if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Не удалось создать папку загрузки.');
}

function import_upload_remove_tree(string $dir): void {
    if(!is_dir($dir))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $node){
        $path=$node->getPathname();
        if($node->isDir())@rmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

function import_upload_stage_summary(string $stage): array {
    if(!is_dir($stage))throw new RuntimeException('Загрузка не найдена или уже завершена.');
    $files=[];$csv=[];$images=0;$bytes=0;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if(!$f->isFile()||$f->isLink())continue;
        $name=$f->getFilename();
        if(!str_ends_with($name,'.part'))continue;
        $relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($f->getPathname(),strlen($stage)+1));
        $final=substr($relative,0,-5);
        $base=mb_strtolower(basename($final));
        $ext=strtolower(pathinfo($final,PATHINFO_EXTENSION));
        if($ext==='csv')$csv[]=$final;
        if(in_array($ext,['jpg','jpeg','png','webp','gif','avif'],true))$images++;
        $bytes+=$f->getSize();
        $files[]=['source'=>$f->getPathname(),'relative'=>$relative,'final'=>$final];
    }
    return ['files'=>$files,'csv'=>$csv,'images'=>$images,'bytes'=>$bytes];
}

function import_upload_finalize_stage(string $importRoot,string $batch): array {
    $stage=import_upload_stage_dir($importRoot,$batch);
    $summary=import_upload_stage_summary($stage);
    if(count($summary['csv'])!==1)throw new RuntimeException('Нужна ровно одна CSV-таблица с товарами. Обновите страницу, если видите два поля таблиц.');
    foreach($summary['files'] as $file){
        $relative=strtolower(pathinfo($file['final'],PATHINFO_EXTENSION))==='csv'?'Tovary.csv':$file['final'];
        $target=$stage.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        import_upload_mkdir(dirname($target));
        if(is_file($target))@unlink($target);
        if(!rename($file['source'],$target))throw new RuntimeException('Не удалось подготовить файл '.$file['final']);
    }
    touch($stage.DIRECTORY_SEPARATOR.'Tovary.csv');
    if(file_put_contents($stage.DIRECTORY_SEPARATOR.'.upload.json',json_encode(['completed_at'=>microtime(true)]),LOCK_EX)===false)throw new RuntimeException('Не удалось отметить завершение загрузки.');
    $manual=rtrim($importRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'manual';
    import_upload_mkdir($manual);
    $folder=date('Ymd-His').'-'.substr($batch,0,10);
    $target=$manual.DIRECTORY_SEPARATOR.$folder;
    if(is_dir($target))throw new RuntimeException('Папка обновления уже существует.');
    if(!rename($stage,$target))throw new RuntimeException('Не удалось опубликовать комплект обновления.');
    return ['folder'=>'manual/'.$folder,'images'=>$summary['images'],'bytes'=>$summary['bytes'],'files'=>count($summary['files'])];
}

/** Respect the hosting limits instead of assuming one large multipart request. */
function import_upload_limits(): array {
    $bytes=static function(string $value): int {
        $value=trim($value);$n=(float)$value;
        if($n<=0)return 64*1024*1024;
        $suffix=strtolower(substr($value,-1));$factor=['k'=>1024,'m'=>1048576,'g'=>1073741824][$suffix]??1;
        return (int)min(64*1024*1024,$n*$factor);
    };
    $post=max(1,min(24*1024*1024,$bytes((string)ini_get('post_max_size')))-65536);
    return ['max_file_bytes'=>min($post,$bytes((string)ini_get('upload_max_filesize'))),'max_batch_bytes'=>$post,'max_files'=>max(1,min(20,(int)ini_get('max_file_uploads')?:20))];
}
