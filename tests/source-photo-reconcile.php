<?php
declare(strict_types=1);
require __DIR__.'/../server/source-photo-reconcile.php';

function reconcile_check(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}

reconcile_check(spr_photo_path('/import/images/ONE.JPG')==='images/one.jpg','Import path was not normalized');
reconcile_check(spr_photo_path('https://ferryffggg.infinityfreeapp.com/import/new_images/two.jpg?x=1')==='new_images/two.jpg','Live URL was not normalized');
reconcile_check(spr_photo_path('images\\three.webp')==='images/three.webp','Windows path was not normalized');
echo "Source-to-MySQL photo path normalization passed.\n";
