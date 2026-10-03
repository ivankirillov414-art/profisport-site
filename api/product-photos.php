<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require_once __DIR__.'/../server/product-photos.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try{
    $admin=require_admin();$method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true))json_response(['ok'=>false,'error'=>'Разрешены GET и POST.'],405);
    $canEdit=in_array($admin['role'],['owner','admin'],true);
    if($method==='POST'){
        if(!$canEdit)json_response(['ok'=>false,'error'=>'Изменять фотографии может владелец или администратор.'],403);
        if(($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site')json_response(['ok'=>false,'error'=>'Запрос с другого сайта отклонён.'],403);
        csrf_check();
    }
    pph_schema($pdo);
    if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
    $csrf=(string)$_SESSION['csrf'];session_write_close();
    $id=filter_var($_GET['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if($id===false)json_response(['ok'=>false,'error'=>'Неверный товар.'],400);
    if($method==='GET'){
        if($id>0)json_response(['ok'=>true,'product'=>pph_get($pdo,$id),'csrf'=>$csrf,'can_edit'=>$canEdit,'limits'=>pph_limits()]);
        $search=mb_substr(trim((string)($_GET['q']??'')),0,150);$page=max(1,min(10000,(int)($_GET['page']??1)));$offset=($page-1)*20;
        $where='1=1';$args=[];
        if($search!==''){
            $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
            $where="(name LIKE ? ESCAPE '!' OR sku LIKE ? ESCAPE '!' OR source_id=? OR id=?)";
            $args=[$like,$like,$search,ctype_digit($search)?(int)$search:0];
        }
        $q=$pdo->prepare('SELECT COUNT(*) FROM products WHERE '.$where);$q->execute($args);$total=(int)$q->fetchColumn();
        $q=$pdo->prepare('SELECT id,source_id,name,sku,category_path,is_active,main_image,images FROM products WHERE '.$where.' ORDER BY is_active DESC,id DESC LIMIT 20 OFFSET '.$offset);$q->execute($args);
        $items=[];foreach(pph_apply_rows($pdo,$q->fetchAll(PDO::FETCH_ASSOC)) as $row){$items[]=['id'=>(int)$row['id'],'name'=>(string)$row['name'],'sku'=>(string)($row['sku']??''),'category'=>(string)($row['category_path']??''),'active'=>(int)$row['is_active']===1,'image'=>pph_urls($row)[0]??null,'manual'=>!empty($row['_photo_manual'])];}
        json_response(['ok'=>true,'items'=>$items,'total'=>$total,'page'=>$page,'page_size'=>20,'can_edit'=>$canEdit,'csrf'=>$csrf,'limits'=>pph_limits()]);
    }
    if($id<1)json_response(['ok'=>false,'error'=>'Выберите товар.'],400);
    $action=(string)($_GET['action']??'');$upload=null;
    if(in_array($action,['upload','replace'],true)){
        $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));
        if($contentType!=='multipart/form-data')json_response(['ok'=>false,'error'=>'Ожидается фотография.'],415);
        if((int)($_SERVER['CONTENT_LENGTH']??0)>pph_limits()['max_file_bytes']+65536)json_response(['ok'=>false,'error'=>'Фотография превышает лимит хостинга.'],413);
        $f=$_FILES['photo']??null;
        if(!$f||is_array($f['error']??null)||(int)($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)json_response(['ok'=>false,'error'=>'Фотография не получена. Проверьте размер и повторите выбор.'],400);
        if(!is_uploaded_file((string)$f['tmp_name']))json_response(['ok'=>false,'error'=>'Недопустимый файл.'],400);
        if(!preg_match('/\.(jpe?g|png|webp)$/i',(string)$f['name']))json_response(['ok'=>false,'error'=>'Выберите JPG, PNG или WebP.'],400);
        $upload=(string)$f['tmp_name'];$input=$_POST;
    }else{$input=input_json();}
    $product=pph_mutate($pdo,$id,(int)$admin['id'],$action,$input,$upload);
    json_response(['ok'=>true,'product'=>$product]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    $code=(int)$e->getCode();$safe=$e instanceof RuntimeException&&in_array($code,[400,403,404,409,413,503],true);
    if(!$safe)error_log('product_photos: '.$e->__toString());
    json_response(['ok'=>false,'error'=>$safe?$e->getMessage():'Не удалось сохранить фотографии. Обновите карточку для проверки результата.'],$safe?$code:500);
}
