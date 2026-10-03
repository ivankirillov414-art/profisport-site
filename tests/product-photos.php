<?php
declare(strict_types=1);
require __DIR__.'/../server/product-photo-editor.php';
require __DIR__.'/../server/import-single-csv.php';
function pp_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$manual='/import/curated-photos/42/'.str_repeat('a',64).'.png';
$source='/import/source.jpg';$incoming='/import/new/source.jpg';
$set=ppe_overlay(single_csv_merge_photos([$incoming],[$source,$manual],$manual),['manual_urls'=>json_encode([$manual]),'primary_url'=>$manual]);
pp_assert($set['main']===$manual&&$set['images'][0]===$manual,'Manual primary survives source import');
pp_assert(in_array($incoming,$set['images'],true),'New source images remain available');
$set=ppe_overlay(['main'=>$source,'images'=>[$source]],['manual_urls'=>[$manual],'primary_url'=>'']);
pp_assert($set['main']===$source&&in_array($manual,$set['images'],true),'Gallery-only upload does not replace existing main');
pp_assert(ppe_overlay(['main'=>$incoming,'images'=>[$incoming]],['manual_urls'=>[],'primary_url'=>$source])['main']===$source,'Explicit source-photo choice is retained');
pp_assert(ppe_safe_url('/import/photo one.jpg')==='/import/photo%20one.jpg','Spaces in local filenames are supported');
foreach(['javascript:alert(1)','data:text/html,test','//other.test/image.jpg','/import/../server/config.php','/import/%2e%2e/server/config.php','/import/a\\b.png'] as $u)pp_assert(ppe_safe_url($u)==='','Unsafe URL rejected: '.$u);
pp_assert(ppe_manual_url($manual)&&!ppe_manual_url('/import/manual/42.jpg'),'Separate manual origin');
$p=['main_image'=>$source,'images'=>json_encode([$source])];$revision=ppe_revision($p);
pp_assert($revision!==ppe_revision($p,['primary_url'=>$manual]),'Revision changes with override');
$root=sys_get_temp_dir().'/ppe-'.bin2hex(random_bytes(8));mkdir($root);
try{
    file_put_contents($root.'/photo one.jpg','test');pp_assert(ppe_working('/import/photo%20one.jpg',$root),'Existing file recognised');pp_assert(!ppe_working('/import/missing.jpg',$root),'Missing file recognised');
    file_put_contents($root.'/bad','<svg onload="alert(1)"></svg>');
    $rejected=false;try{ppe_image_bytes($root.'/bad',10000);}catch(InvalidArgumentException $e){$rejected=true;}pp_assert($rejected,'SVG rejected');
    $im=imagecreatetruecolor(12,8);imagealphablending($im,false);imagesavealpha($im,true);imagefill($im,0,0,imagecolorallocatealpha($im,20,50,60,100));imagepng($im,$root.'/valid.png');imagedestroy($im);
    file_put_contents($root.'/valid.png',"<?php echo 'bad'; ?>",FILE_APPEND);
    $img=ppe_image_bytes($root.'/valid.png',10000);pp_assert(!str_contains($img['bytes'],'<?php'),'Re-encoding strips appended content');
    $u=ppe_store_image(42,$img,$root);pp_assert(ppe_working($u,$root),'Stored image can be resolved');pp_assert(ppe_store_image(42,$img,$root)===$u,'Identical photos reuse immutable path');
    $decoded=imagecreatefromstring($img['bytes']);$rgba=imagecolorsforindex($decoded,imagecolorat($decoded,0,0));pp_assert($rgba['alpha']>90,'PNG alpha preserved');imagedestroy($decoded);
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);
}
echo "PASS product photos: import priority, gallery, revisions, URL validation, re-encoding and transparency\n";
