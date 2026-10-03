<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/product-photo-editor.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function ppe_fail(string $message,int $status=400): never {json_response(['ok'=>false,'error'=>$message],$status);}
function ppe_detail(array $p): array {
    $photos=[];
    foreach(ppe_candidates($p) as $url){$safe=ppe_safe_url($url);if($safe==='')continue;$photos[]=['url'=>$url,'preview'=>$safe,'working'=>ppe_working($url),'manual'=>ppe_manual_url($url),'primary'=>$url===($p['main_image']??'')];}
    return ['id'=>(int)$p['id'],'name'=>(string)($p['name']??''),'sku'=>(string)($p['sku']??''),'source_id'=>(string)($p['source_id']??''),'category'=>(string)($p['category_path']??''),'active'=>(bool)$p['is_active'],'stock_qty'=>$p['stock_qty'],'photos'=>$photos,'revision'=>ppe_revision($p,$p)];
}
const PPE_SELECT='SELECT p.id,p.name,p.sku,p.source_id,p.model,p.is_active,p.stock_qty,p.category_path,p.main_image,p.images,o.manual_urls,o.primary_url FROM products p LEFT JOIN product_photo_overrides o ON o.product_id=p.id';
$locked=false;
try{
    $admin=require_admin();$method=$_SERVER['REQUEST_METHOD'];
    if(!in_array($method,['GET','POST'],true))ppe_fail('Разрешены GET и POST.',405);
    if($method==='POST'){
        csrf_check();
        if(!in_array($admin['role'],['owner','admin'],true))ppe_fail('Изменять фото может владелец или администратор.',403);
        if(($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site')ppe_fail('Запрос с другого сайта отклонён.',403);
    }
    ppe_schema($pdo);
    if($method==='GET'){
        $id=(int)($_GET['id']??0);
        if($id>0){$st=$pdo->prepare(PPE_SELECT.' WHERE p.id=?');$st->execute([$id]);$p=$st->fetch();if(!$p)ppe_fail('Товар не найден.',404);json_response(['ok'=>true,'product'=>ppe_detail($p),'max_file_bytes'=>ppe_limit_bytes(),'can_edit'=>in_array($admin['role'],['owner','admin'],true)]);}
        $q=mb_substr(trim((string)($_GET['q']??'')),0,160,'UTF-8');$missing=($_GET['missing']??'0')==='1';$page=max(1,min(10000,(int)($_GET['page']??1)));$size=20;$where=[];$args=[];
        if(($_GET['archived']??'0')!=='1')$where[]='p.is_active=1';
        $terms=preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY)?:[];
        foreach(array_slice($terms,0,8) as $term){
            $like='%'.strtr($term,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $where[]="(p.name LIKE ? ESCAPE '!' OR p.sku LIKE ? ESCAPE '!' OR p.source_id LIKE ? ESCAPE '!' OR p.model LIKE ? ESCAPE '!' OR p.id=?)";
            array_push($args,$like,$like,$like,$like,ctype_digit($term)?(int)$term:0);
        }
        $st=$pdo->prepare(PPE_SELECT.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY p.id');$st->execute($args);
        $items=[];$total=0;
        while($p=$st->fetch()){
            $preview='';foreach(ppe_candidates($p) as $u){if(ppe_working($u)){$preview=ppe_safe_url($u);break;}}
            if($missing&&$preview!=='')continue;
            if($total>=($page-1)*$size&&count($items)<$size)$items[]=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'sku'=>(string)($p['sku']??''),'source_id'=>(string)($p['source_id']??''),'category'=>(string)($p['category_path']??''),'active'=>(bool)$p['is_active'],'preview'=>$preview];
            $total++;
        }
        json_response(['ok'=>true,'items'=>$items,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$size)]);
    }
    $action=(string)($_GET['action']??'');
    if(!in_array($action,['upload','primary'],true))ppe_fail('Неизвестная операция.',404);
    $data=$action==='upload'?$_POST:input_json();
    $id=filter_var($data['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id)ppe_fail('Выберите товар из каталога.');
    $revision=(string)($data['revision']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$revision))ppe_fail('Обновите карточку товара перед изменением.');
    $locked=(int)$pdo->query("SELECT GET_LOCK('profisport_1c_import',0)")->fetchColumn()===1;
    if(!$locked)ppe_fail('Сейчас обновляется каталог. Повторите после завершения импорта.',409);
    $pdo->beginTransaction();
    $st=$pdo->prepare(PPE_SELECT.' WHERE p.id=? FOR UPDATE');$st->execute([$id]);$p=$st->fetch();
    if(!$p){$pdo->rollBack();ppe_fail('Товар не найден.',404);}
    if(!hash_equals(ppe_revision($p,$p),$revision)){$pdo->rollBack();ppe_fail('Фото товара уже изменились. Обновите карточку, затем повторите действие.',409);}
    $manual=ppe_urls($p['manual_urls']??[]);$primary=(string)($p['primary_url']??'');
    if($action==='upload'){
        $file=$_FILES['photo']??null;
        if(!$file||is_array($file['error']??null)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Файл не получен или превышает лимит хостинга.');
        if(!is_uploaded_file((string)$file['tmp_name']))throw new InvalidArgumentException('Недопустимый файл загрузки.');
        $image=ppe_image_bytes((string)$file['tmp_name'],ppe_limit_bytes());
        if(count($manual)>=48)throw new InvalidArgumentException('Для товара уже добавлено 48 ручных фотографий.');
        $url=ppe_store_image((int)$id,$image);$manual=ppe_urls(array_merge($manual,[$url]));
        if(($data['primary']??'0')==='1'||!ppe_working((string)($p['main_image']??'')))$primary=$url;
    }else{
        $url=(string)($data['url']??'');
        if(!in_array($url,ppe_candidates($p),true)||!ppe_working($url))throw new InvalidArgumentException('Выберите доступную фотографию этого товара.');
        $primary=$url;
    }
    $set=ppe_overlay(['images'=>ppe_candidates($p),'main'=>$p['main_image']],['manual_urls'=>$manual,'primary_url'=>$primary]);
    $manualJson=json_encode($manual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $galleryJson=json_encode($set['images'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $st=$pdo->prepare('INSERT INTO product_photo_overrides(product_id,manual_urls,primary_url,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE manual_urls=VALUES(manual_urls),primary_url=VALUES(primary_url),updated_by=VALUES(updated_by)');$st->execute([$id,$manualJson,$primary?:null,(int)$admin['id']]);
    $pdo->prepare('UPDATE products SET main_image=?,images=?,updated_at=NOW() WHERE id=?')->execute([$set['main'],$galleryJson,$id]);
    audit($pdo,'product_photo_'.$action,'product',(string)$id,['url'=>$url,'primary'=>$primary?:null]);
    $pdo->commit();
    $p['main_image']=$set['main'];$p['images']=$galleryJson;$p['manual_urls']=$manualJson;$p['primary_url']=$primary;
    json_response(['ok'=>true,'product'=>ppe_detail($p),'can_edit'=>true,'max_file_bytes'=>ppe_limit_bytes()]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('product_photos: '.$e->getMessage());
    json_response(['ok'=>false,'error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Не удалось сохранить фото. Обновите карточку для проверки результата; прежние данные не удалены.'],$e instanceof InvalidArgumentException?400:500);
}finally{if($locked)$pdo->query("SELECT RELEASE_LOCK('profisport_1c_import')");}
