<?php
declare(strict_types=1);
require __DIR__.'/../server/bootstrap.php';
require __DIR__.'/../server/legal-documents.php';
$admin=require_admin();
try{
    if($_SERVER['REQUEST_METHOD']==='GET')json_response(['ok'=>true,'state'=>legal_load($pdo),'fields'=>legal_fields(),'csrf'=>$_SESSION['csrf']??'']);
    if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
    csrf_check();$in=input_json();
    $pdo->beginTransaction();
    $s=$pdo->prepare('INSERT IGNORE INTO site_settings(setting_key,setting_value) VALUES(?,?)');
    $s->execute([LEGAL_SETTINGS_KEY,json_encode(legal_defaults(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $state=legal_apply(legal_load($pdo,true),$in,(int)$admin['id']);
    $s=$pdo->prepare('UPDATE site_settings SET setting_value=? WHERE setting_key=?');
    $s->execute([json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LEGAL_SETTINGS_KEY]);
    audit($pdo,'legal_'.($in['action']??''),'legal_document',$in['slug']??null,['revision'=>$state['revision']]);
    $pdo->commit();json_response(['ok'=>true,'state'=>$state]);
}catch(InvalidArgumentException $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'error'=>'validation','message'=>$e->getMessage()],422);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e->getMessage()==='conflict')json_response(['ok'=>false,'error'=>'conflict','message'=>'Документы изменены в другой вкладке. Скопируйте свои правки и обновите страницу.'],409);error_log('legal_admin: '.$e->getMessage());json_response(['ok'=>false,'error'=>'server_error','message'=>'Не удалось сохранить документ. Попробуйте снова.'],500);}
