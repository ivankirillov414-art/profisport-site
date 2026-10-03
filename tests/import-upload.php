<?php
declare(strict_types=1);
require __DIR__.'/../server/import-upload.php';
function ok(bool $value,string $message): void { if(!$value){fwrite(STDERR,"FAIL: $message\n");exit(1);} }
ok(import_upload_sanitize_path('images/Лыжи/photo 1.jpg')==='images/Лыжи/photo 1.jpg','valid nested path');
foreach(['../x.jpg','images/../x.jpg','images/.hidden/x.jpg','x.php',''] as $bad){$thrown=false;try{import_upload_sanitize_path($bad);}catch(Throwable $e){$thrown=true;}ok($thrown,'reject '.$bad);}
$root=sys_get_temp_dir().'/profisport-import-'.bin2hex(random_bytes(6));$batch=str_repeat('a',32);$stage=import_upload_stage_dir($root,$batch);import_upload_mkdir($stage.'/images/set');
file_put_contents($stage.'/Tovary.csv.part',"1;2;3\n");file_put_contents($stage.'/Categories.csv.part',"1;Cat\n");file_put_contents($stage.'/images/set/p.jpg.part','jpg');
$r=import_upload_finalize_stage($root,$batch);ok($r['files']===3,'file count');ok($r['images']===1,'image count');ok(is_file($root.'/'.$r['folder'].'/Tovary.csv'),'products published');ok(is_file($root.'/'.$r['folder'].'/Categories.csv'),'categories published');ok(is_file($root.'/'.$r['folder'].'/images/set/p.jpg'),'image published');ok(!is_dir($stage),'staging moved');import_upload_remove_tree($root);
$root=sys_get_temp_dir().'/profisport-import-'.bin2hex(random_bytes(6));$stage=import_upload_stage_dir($root,$batch);import_upload_mkdir($stage);file_put_contents($stage.'/Tovary.csv.part','x');$thrown=false;try{import_upload_finalize_stage($root,$batch);}catch(RuntimeException $e){$thrown=true;}ok($thrown,'missing categories rejected');import_upload_remove_tree($root);
echo "import upload tests passed\n";
