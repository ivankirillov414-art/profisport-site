<?php
declare(strict_types=1);
require __DIR__.'/../server/source-photo-diagnostic.php';
function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=sys_get_temp_dir().'/source-photo-test-'.bin2hex(random_bytes(8));
mkdir($root);mkdir($root.'/images');mkdir($root.'/new_images');
try{
    foreach(['images/one.jpg','images/two.jpg','new_images/two.jpg'] as $file)file_put_contents($root.'/'.$file,'fixture');
    $rows=[];
    foreach(['one.jpg','two.jpg','default-no-image.png','absent.jpg','images/two.jpg','../one.jpg'] as $index=>$photo){
        $row=array_fill(0,41,'');$row[0]='1';$row[2]='Kod_'.(100+$index);$row[4]=(string)(100+$index);$row[7]='Товар';$row[8]="Описание; с кавычкой \" и\nпереносом";$row[9]=$photo;$rows[]=$row;
    }
    $rows[]=$rows[0];$rows[]=['1','','Kod_40125','10364','40125','sku','Разорванная запись'];
    $stream=fopen('php://temp','w+');foreach($rows as $row)fputcsv($stream,$row,';','"','');rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
    foreach(['UTF-8','UTF-16LE','Windows-1251'] as $encoding){
        $raw=$encoding==='UTF-16LE'?"\xFF\xFE".mb_convert_encoding($csv,$encoding,'UTF-8'):mb_convert_encoding($csv,$encoding,'UTF-8');
        file_put_contents($root.'/1c_to_diafan_tovary.csv',$raw);
        $before=hash_file('sha256',$root.'/1c_to_diafan_tovary.csv');$report=spd_report($root);
        check($report!==null,'Legacy format not detected: '.$encoding);
        check($report['rows']===8&&$report['stats']['valid_rows']===7,'First row or multiline description lost');
        check($report['stats']['malformed_rows']===1,'Malformed record not reported');
        check($report['references']===['exact'=>3,'ambiguous'=>1,'missing'=>1,'placeholder'=>1,'invalid'=>1],'Wrong exact, ambiguous, placeholder or invalid classification');
        check($report['image_files']===3&&$report['unique_image_names']===2,'Duplicate files counted incorrectly');
        check($report['duplicate_source_ids'][100]===2,'Repeated source code lost');
        check(hash_file('sha256',$root.'/1c_to_diafan_tovary.csv')===$before,'Source was modified');
        check($report['mysql_links_checked']===false,'Source report misrepresents MySQL audit');
    }
    $damaged=$csv."1;;Kod_999;10364;999;sku;;\"Незакрытая кавычка;broken.jpg\n";
    $valid=array_fill(0,41,'');$valid[0]='1';$valid[2]='Kod_1000';$valid[4]='1000';$valid[7]='После ошибки';$valid[9]='one.jpg';
    $line=fopen('php://temp','w+');fputcsv($line,$valid,';','"','');rewind($line);$damaged.=stream_get_contents($line);fclose($line);
    file_put_contents($root.'/1c_to_diafan_tovary.csv',$damaged);
    $recovered=spd_report($root);
    check($recovered!==null&&$recovered['stats']['valid_rows']===8,'Damaged quote swallowed later DIAFAN rows');
    check($recovered['stats']['malformed_rows']>=2,'Damaged record was not reported');
    echo "Source photo diagnostics passed: exact paths, duplicate names, malformed records, encodings, multiline CSV and read-only behavior.\n";
}finally{
    foreach(glob($root.'/images/*')?:[] as $file)unlink($file);
    foreach(glob($root.'/new_images/*')?:[] as $file)unlink($file);
    unlink($root.'/1c_to_diafan_tovary.csv');rmdir($root.'/images');rmdir($root.'/new_images');rmdir($root);
}
