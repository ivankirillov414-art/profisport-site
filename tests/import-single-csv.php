<?php
declare(strict_types=1);
require __DIR__.'/../server/import-single-csv.php';
function check(bool $pass,string $message): void {if(!$pass)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/single-csv-'.bin2hex(random_bytes(8));mkdir($dir);
try{
    foreach([';',',',"\t"] as $delimiter){
        $file=$dir.'/Любое название.CSV';$f=fopen($file,'w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['id','name','price','stock','category'],$delimiter,'"','');fputcsv($f,['1',"Товар, с\nпереносом",'12,50','2','Велосипеды'],$delimiter,'"','');fclose($f);
        $r=single_csv_rows($file);check(count($r)===2&&$r[1][1]==="Товар, с\nпереносом",'quoted CSV cells and arbitrary filenames');
    }
    $hash=single_csv_snapshot($file,null);check($hash===single_csv_snapshot($file,null),'stable single CSV fingerprint');
    mkdir($dir.'/manual');mkdir($dir.'/manual/one');mkdir($dir.'/manual/two');copy($file,$dir.'/manual/one/Tovary.csv');copy($file,$dir.'/manual/two/Tovary.csv');
    check(single_csv_snapshot($dir.'/manual/one/Tovary.csv',null)!==single_csv_snapshot($dir.'/manual/two/Tovary.csv',null),'a new photo batch with identical CSV is not silently skipped');
    check(single_csv_category('Велосипеды / Горные',fn($id)=>'')==='Велосипеды / Горные','CSV text category');
    check(single_csv_category('12',fn($id)=>$id==='12'?'Каталог / Подшипники':'')==='Каталог / Подшипники','existing optional category mapping');
    check(single_csv_category('42',fn($id)=>'')==='','do not invent labels for unknown IDs');
    $p=single_csv_merge_photos(['/import/manual/new/a.jpg'],['/import/old/a.jpg','/import/b.jpg'],'/import/old/a.jpg');
    check($p['main']==='/import/manual/new/a.jpg'&&$p['images']===['/import/manual/new/a.jpg','/import/b.jpg'],'replace image by reference without duplicated old main');
    $p=single_csv_merge_photos([],['/import/old/a.jpg'],'/import/old/a.jpg');check($p['main']==='/import/old/a.jpg','no new photo preserves previous image');
    echo "PASS: single CSV names, separators, multiline cells, optional categories and photo updates\n";
}finally{
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
}
