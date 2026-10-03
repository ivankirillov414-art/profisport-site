<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/product-photos.php';
if(($config['db_name']??'')!=='profisport_test')throw new RuntimeException('Disposable database only');
pph_schema($pdo);
$root=dirname(__DIR__);@mkdir($root.'/import/photo-test',0755,true);
foreach(['source.png'=>10,'source2.png'=>80,'upload.png'=>150,'replace.png'=>220] as $name=>$n){$im=imagecreatetruecolor(3,2);imagefill($im,0,0,imagecolorallocate($im,$n,80,50));imagepng($im,$root.'/import/photo-test/'.$name);imagedestroy($im);}
$q=$pdo->prepare("INSERT INTO products(source_id,source_hash,name,title,sku,price,price_rub,stock_qty,stock_status,availability,is_active,main_image,images,category_path) VALUES(?,?,?,?,?,100,100,5,'in_stock','in_stock',1,?,'[]','Тест')");
$q->execute(['PHOTO-A',hash('sha256','1c|PHOTO-A'),'Фото товар A','Фото товар A','PHOTO-A','/import/photo-test/source.png']);$a=(int)$pdo->lastInsertId();
$q->execute(['PHOTO-B',hash('sha256','1c|PHOTO-B'),'Фото товар B','Фото товар B','PHOTO-B','/import/photo-test/source.png']);$b=(int)$pdo->lastInsertId();
$u=$pdo->prepare("INSERT INTO admin_users(username,role,is_active,force_password_setup) VALUES(?,?,1,0)");$u->execute(['photo-test-owner','owner']);$owner=(int)$pdo->lastInsertId();$u->execute(['photo-test-viewer','viewer']);$viewer=(int)$pdo->lastInsertId();
$csrf=bin2hex(random_bytes(24));
function photo_session(int $id,string $csrf): string {session_name('PROFISPORT_ADMIN');session_id(bin2hex(random_bytes(16)));session_start();$_SESSION['admin']=['id'=>$id];$_SESSION['csrf']=$csrf;$cookie='PROFISPORT_ADMIN='.session_id();session_write_close();return $cookie;}
$oc=photo_session($owner,$csrf);$vc=photo_session($viewer,$csrf);
file_put_contents('/tmp/photo-test-fixtures.json',json_encode(['a'=>$a,'b'=>$b,'cookie'=>$oc,'viewer_cookie'=>$vc,'csrf'=>$csrf]));
echo "Disposable photo fixtures ready\n";
