<?php
declare(strict_types=1);
require __DIR__.'/../server/import-upload.php';
function ok(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
ok(import_upload_sanitize_path('images/Лыжи/photo 1.jpg')==='images/Лыжи/photo 1.jpg','valid image path');
foreach(['../x.jpg','images/../x.jpg','images/.hidden/x.jpg','x.php','evil.php.jpg','x.xls',''] as $bad){$thrown=false;try{import_upload_sanitize_path($bad);}catch(Throwable $e){$thrown=true;}ok($thrown,'reject '.$bad);}
$root=sys_get_temp_dir().'/profisport-import-'.bin2hex(random_bytes(6));$batch=str_repeat('a',32);
try{
    $stage=import_upload_stage_dir($root,$batch);import_upload_mkdir($stage.'/images');
    file_put_contents($stage.'/Новая сводка.CSV.part',"id;name;price;stock\n1;Test;20;2\n");file_put_contents($stage.'/images/p.jpg.part','jpg');
    $r=import_upload_finalize_stage($root,$batch);ok($r['files']===2&&$r['images']===1,'one table and one image');
    ok(is_file($root.'/'.$r['folder'].'/Tovary.csv'),'arbitrary filename normalized internally');
    ok(!is_file($root.'/'.$r['folder'].'/Categories.csv'),'no second CSV required or synthesized');
    ok(is_file($root.'/'.$r['folder'].'/images/p.jpg'),'ordinary image published');ok(!is_dir($stage),'staging moved');
    import_upload_mkdir($stage);file_put_contents($stage.'/only.csv.part','x');
    $r=import_upload_finalize_stage($root,str_repeat('a',32));
}catch(RuntimeException $e){
    // Second publication using the identical batch is intentionally not allowed.
    if($e->getMessage()!=='Папка обновления уже существует.')throw $e;
}finally{import_upload_remove_tree($root);}
foreach([[],['first.csv','second.csv']] as $names){
    $root=sys_get_temp_dir().'/profisport-import-'.bin2hex(random_bytes(6));$stage=import_upload_stage_dir($root,$batch);import_upload_mkdir($stage);
    foreach($names as $name)file_put_contents($stage.'/'.$name.'.part','x');
    $thrown=false;try{import_upload_finalize_stage($root,$batch);}catch(RuntimeException $e){$thrown=true;}ok($thrown,'zero or two CSV rejected');import_upload_remove_tree($root);
}
$root=sys_get_temp_dir().'/profisport-import-'.bin2hex(random_bytes(6));$stage=import_upload_stage_dir($root,$batch);import_upload_mkdir($stage);file_put_contents($stage.'/without-images.csv.part','x');
$r=import_upload_finalize_stage($root,$batch);ok($r['files']===1&&$r['images']===0,'photos optional');import_upload_remove_tree($root);
$limits=import_upload_limits();ok($limits['max_files']>0&&$limits['max_batch_bytes']>0&&$limits['max_file_bytes']<=$limits['max_batch_bytes'],'hosting limits');
echo "PASS: arbitrary CSV name, no categories CSV, optional ordinary photos, traversal and double-extension checks\n";
