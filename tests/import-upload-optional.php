<?php
declare(strict_types=1);
require __DIR__.'/../server/import-upload.php';
function check_optional(bool $result,string $name): void {
    if(!$result)throw new RuntimeException('FAIL: '.$name);
    echo 'PASS: '.$name.PHP_EOL;
}
$root=sys_get_temp_dir().'/profisport-optional-'.bin2hex(random_bytes(8));
try {
    import_upload_mkdir($root.'/existing');
    $oldImage=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    file_put_contents($root.'/existing/old.png',$oldImage);
    $oldHash=hash_file('sha256',$root.'/existing/old.png');
    $batch=bin2hex(random_bytes(16));$stage=import_upload_stage_dir($root,$batch);
    import_upload_mkdir($stage);
    file_put_contents($stage.'/Tovary.csv.part',"id;name;price;stock;image\n1;Bicycle;100;2;not-uploaded.png\n");
    file_put_contents($stage.'/Categories.csv.part',"1;Bikes;\n");
    $zero=import_upload_finalize_stage($root,$batch);
    check_optional($zero['files']===2&&$zero['images']===0,'two tables can be published without any pictures');
    check_optional(is_file($root.'/'.$zero['folder'].'/Tovary.csv'),'table containing missing photo reference is accepted');
    check_optional(hash_file('sha256',$root.'/existing/old.png')===$oldHash,'previous image bytes unchanged after zero-photo upload');
    $batch=bin2hex(random_bytes(16));$stage=import_upload_stage_dir($root,$batch);
    import_upload_mkdir($stage.'/images/small-set');
    file_put_contents($stage.'/Tovary.csv.part',"id;name;price;stock;image\n1;Bicycle;110;3;not-uploaded.png\n2;Ski;20;1;new.png\n");
    file_put_contents($stage.'/Categories.csv.part',"1;Bikes;\n");
    file_put_contents($stage.'/images/small-set/new.png.part',$oldImage);
    $partial=import_upload_finalize_stage($root,$batch);
    check_optional($partial['files']===3&&$partial['images']===1,'partial image set does not require full photo coverage');
    check_optional(is_file($root.'/'.$partial['folder'].'/images/small-set/new.png'),'one selected picture published');
    check_optional(hash_file('sha256',$root.'/existing/old.png')===$oldHash,'previous image bytes unchanged after partial-photo upload');
    check_optional(is_file($root.'/'.$zero['folder'].'/Tovary.csv'),'previous completed upload remains intact');
} finally { import_upload_remove_tree($root); }
