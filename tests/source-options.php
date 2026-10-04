<?php
declare(strict_types=1);
require __DIR__.'/../server/import-safety.php';
function expect(bool $ok):void{if(!$ok)throw new RuntimeException('variant recovery regression');}
$map=['source_id'=>4,'sku'=>5,'name'=>7,'image'=>9,'price'=>10,'category'=>3];
$row=array_fill(0,41,'');$row[3]='15083';$row[4]='18978';$row[5]='18978';$row[7]='Охотничьи лыжи';$row[9]='ski.jpg';$row[10]='4600.00&1&89=189|4980.00&1&89=199';$row[11]='2';$row[29]='150|170';
$out=import_normalize_rows([$row],$map,11);
expect(count($out)===2&&$out[0][10]==='4600'&&$out[1][10]==='4980');
expect(str_ends_with($out[0][7],' — 150')&&str_ends_with($out[1][7],' — 170'));
expect($out[0][4]!==$out[1][4]&&$out[0][11]==='1');
$row[11]='5';expect(import_normalize_rows([$row],$map,11)===[$row]);
expect(import_price_options('4600&1&89=189|oops')===null);
expect(import_price_options('4600&1&89=189|4980&1&89=189')===null);
$row[10]='3150.00';$row[11]='2';$later=$row;$later[10]='3500.00';$later[11]='12';
expect(import_normalize_rows([$row,$later],$map,11)===[$later]);
$different=$later;$different[7]='Other product';
expect(import_normalize_rows([$row,$different],$map,11)===[$row,$different]);
$otherSize=$later;$otherSize[29]='190';
$variants=import_normalize_rows([$row,$otherSize],$map,11);
expect(count($variants)===2&&$variants[0][4]!==$variants[1][4]);
echo "Source variant price, stock, size and duplicate identity tests passed\n";
