<?php
declare(strict_types=1);
require __DIR__.'/../server/source-photo-reconcile.php';

function reconcile_check(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}

reconcile_check(spr_photo_path('/import/images/ONE.JPG')==='images/one.jpg','Import path was not normalized');
reconcile_check(spr_photo_path('https://ferryffggg.infinityfreeapp.com/import/new_images/two.jpg?x=1')==='new_images/two.jpg','Live URL was not normalized');
reconcile_check(spr_photo_path('images\\three.webp')==='images/three.webp','Windows path was not normalized');
reconcile_check(spr_photo_path('https://other.example/import/images/one.jpg')!=='images/one.jpg','Foreign host must not count as a native file');
reconcile_check(spr_photo_path('//other.example/import/images/one.jpg')!=='images/one.jpg','Protocol-relative foreign URL must not count as native');

class ReconcileFixtureStatement extends PDOStatement {
    public function __construct(private array $rows){}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->rows;}
}
class ReconcileFixturePdo extends PDO {
    public int $queries=0;
    public function __construct(private array $rows){}
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false {
        reconcile_check($query==='SELECT id,source_id,source_hash,sku,name,main_image,images,is_active FROM products ORDER BY id','Unexpected SQL statement in read-only audit');
        $this->queries++;
        return new ReconcileFixtureStatement($this->rows);
    }
}

$root=sys_get_temp_dir().'/photo-reconcile-'.bin2hex(random_bytes(8));
mkdir($root);mkdir($root.'/images');
try{
    file_put_contents($root.'/images/native.jpg','fixture');
    $csv=fopen($root.'/1c_to_diafan_tovary.csv','w');
    foreach([100,101,102,103,104,104] as $id){
        $row=array_fill(0,41,'');$row[2]='Kod_'.$id;$row[4]=(string)$id;$row[5]='sku-'.$id;$row[7]='Товар '.$id;$row[9]='native.jpg';$row[10]='150';$row[11]='2';
        fputcsv($csv,$row,';','"','');
    }
    fclose($csv);
    $dbRows=[
        ['id'=>1,'source_id'=>'100','source_hash'=>'','sku'=>'sku-100','name'=>'Товар 100','main_image'=>'/import/images/native.jpg','images'=>'["/import/images/pads.jpg","/import/images/native.jpg"]','is_active'=>1],
        ['id'=>2,'source_id'=>'old-101','source_hash'=>hash('sha256','1c|101'),'sku'=>'sku-101','name'=>'Товар 101','main_image'=>'','images'=>'[]','is_active'=>1],
        ['id'=>3,'source_id'=>'103','source_hash'=>'','sku'=>'','name'=>'Товар 103','main_image'=>'https://other.example/import/images/native.jpg','images'=>'["/import/images/native.jpg"]','is_active'=>0],
    ];
    $pdo=new ReconcileFixturePdo($dbRows);$before=hash_file('sha256',$root.'/1c_to_diafan_tovary.csv');
    $result=spr_reconcile($pdo,$root);$stats=$result['stats'];
    reconcile_check($pdo->queries===1,'Audit must only read the product snapshot once');
    reconcile_check($stats['source_valid_rows']===6&&$stats['source_exact_unique_rows']===4,'Duplicate source IDs must be excluded');
    reconcile_check($stats['matched_products']===2&&$stats['correct_main_photo']===1,'Remote lookalike URL must not pass main photo check');
    reconcile_check($stats['galleries_with_unverified_photos']===1,'Correct main must not conceal unverified gallery');
    reconcile_check($stats['gallery_first_differs_from_main']===2,'Gallery order mismatch must be reported');
    reconcile_check($stats['missing_products']===2&&$stats['missing_code_with_identity_candidates']===1&&$stats['missing_code_without_identity_candidates']===1,'Missing code must be separated from possible existing product');
    reconcile_check($result['examples']['missing_product'][0]['identity_candidates'][0]['reasons']===['name','sku','source_hash'],'Keep all duplicate identity evidence');
    reconcile_check($stats['db_products_outside_exact_audit']===1,'Report the excluded MySQL products');
    reconcile_check($stats['native_photo_only_secondary']===1,'Native secondary photo must be retained as evidence');
    reconcile_check($before===hash_file('sha256',$root.'/1c_to_diafan_tovary.csv'),'Source must remain unchanged');
}finally{
    unlink($root.'/1c_to_diafan_tovary.csv');unlink($root.'/images/native.jpg');rmdir($root.'/images');rmdir($root);
}
echo "Source-to-MySQL reconciliation: main, gallery, missing identities, audit scope and read-only checks passed.\n";
