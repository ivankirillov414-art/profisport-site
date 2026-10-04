<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/catalog-source-options.php';
if(PHP_SAPI!=='cli'||($config['db_name']??'')!=='profisport_test')throw new RuntimeException('disposable database only');
$pdo=db();$dir=__DIR__.'/../import/manual/source-options-test';@mkdir($dir,0755,true);
$pf=$dir.'/Tovary.csv';$cf=$dir.'/Categories.csv';
$get=$pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key='current_1c_snapshot'");$get->execute();$previous=$get->fetchColumn();
$save=$pdo->prepare("INSERT INTO site_settings(setting_key,setting_value) VALUES('current_1c_snapshot',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
try{
    $f=fopen($pf,'w');
    for($i=0;$i<105;$i++){
        $r=array_fill(0,41,'');$r[2]='Kod_'.(800000+$i);$r[3]='ski';$r[4]=(string)(800000+$i);$r[5]=$r[4];$r[7]='Recovery fixture ski '.$i;
        $r[10]='4600.00&1&89=189|4980.00&2&89=199';$r[11]='3';$r[29]='150|170';fputcsv($f,$r,';','"','');
    }
    fclose($f);file_put_contents($cf,"ski;Hunting skis;\n");clearstatcache();$snapshot=single_csv_snapshot($pf,$cf);$save->execute([$snapshot]);
    $result=catalog_repair_source_options($pdo);
    if((int)$pdo->query("SELECT IS_FREE_LOCK('profisport_1c_import')")->fetchColumn()!==1)throw new RuntimeException('repair must release the shared import lock between batches');
    if($result['state']!=='pending'||$result['created']!==100)throw new RuntimeException('first batch must be bounded');
    do{$result=catalog_repair_source_options($pdo);}while($result['state']==='pending');
    if($result['state']!=='done'||$result['created']!==210)throw new RuntimeException('all options must recover');
    $a=$pdo->query("SELECT * FROM products WHERE name='Recovery fixture ski 0 — 150'")->fetch();
    $b=$pdo->query("SELECT * FROM products WHERE name='Recovery fixture ski 0 — 170'")->fetch();
    if(!$a||!$b||(int)$a['price_rub']!==4600||(int)$b['price_rub']!==4980||(int)$b['stock_qty']!==2||$a['category_path']!=='Hunting skis')throw new RuntimeException('price/stock/size/category mismatch');
    if(catalog_repair_source_options($pdo)!==$result)throw new RuntimeException('repair must be idempotent');
    echo "Bounded source recovery database integration passed\n";
}finally{
    $pdo->exec("DELETE FROM products WHERE name LIKE 'Recovery fixture ski %'");
    $pdo->exec("DELETE FROM site_settings WHERE setting_key='catalog_source_options_v1'");
    if($previous===false)$pdo->exec("DELETE FROM site_settings WHERE setting_key='current_1c_snapshot'");else $save->execute([$previous]);
    @unlink($pf);@unlink($cf);@rmdir($dir);
}
