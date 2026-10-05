<?php
declare(strict_types=1);
const LEGAL_SETTINGS_KEY = 'legal_documents_v1';

function legal_fields(): array {
    return ['seller_type'=>'Форма: ИП или ООО','seller_name'=>'ФИО ИП / полное наименование ООО','inn'=>'ИНН','ogrn'=>'ОГРН / ОГРНИП','address'=>'Адрес продавца','registration'=>'Регистрация ИП: дата и регистрирующий орган','claims_address'=>'Адрес возвратов и письменных обращений','phone'=>'Телефон','email'=>'Email обращений и персональных данных','hours'=>'Режим работы','stores'=>'Магазины и пункты выдачи','bank_details'=>'Банковские реквизиты (если нужны)'];
}
function legal_defaults(): array {
    $state=['revision'=>0,'seller'=>array_fill_keys(array_keys(legal_fields()),''),'documents'=>[]];
    foreach((require __DIR__.'/legal-defaults.php') as $d){
        $state['documents'][$d['slug']]=['slug'=>$d['slug'],'title'=>$d['title'],'body'=>$d['body'],'note'=>$d['note'],'published'=>null,'history'=>[]];
    }
    return $state;
}
function legal_load(PDO $pdo, bool $lock=false): array {
    $s=$pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?'.($lock?' FOR UPDATE':''));
    $s->execute([LEGAL_SETTINGS_KEY]);$raw=$s->fetchColumn();$state=legal_defaults();
    if($raw!==false){
        $saved=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($saved)||!isset($saved['revision'],$saved['documents'],$saved['seller']))throw new RuntimeException('legal_storage_invalid');
        $state['revision']=(int)$saved['revision'];$state['seller']=array_replace($state['seller'],$saved['seller']);
        foreach($saved['documents'] as $slug=>$d)if(isset($state['documents'][$slug]))$state['documents'][$slug]=array_replace($state['documents'][$slug],$d);
    }
    return $state;
}
function legal_expand(string $text,array $seller): string {
    return preg_replace_callback('/\{\{([a-z_]+)\}\}/',fn($m)=>trim((string)($seller[$m[1]]??''))!==''?(string)$seller[$m[1]]:$m[0],$text);
}
function legal_publication_errors(array $doc,array $seller): array {
    $errors=[];
    foreach(['seller_type','seller_name','inn','ogrn','address','claims_address','phone','email','hours'] as $key){
        if(trim((string)($seller[$key]??''))==='')$errors[]='Заполните: '.legal_fields()[$key];
    }
    if(!in_array($seller['seller_type']??'',['ИП','ООО'],true))$errors[]='Выберите форму ИП или ООО.';
    $ip=($seller['seller_type']??'')==='ИП';
    if(!preg_match($ip?'/^\d{12}$/D':'/^\d{10}$/D',(string)($seller['inn']??'')))$errors[]='Проверьте длину ИНН: ИП — 12 цифр, ООО — 10.';
    if(!preg_match($ip?'/^\d{15}$/D':'/^\d{13}$/D',(string)($seller['ogrn']??'')))$errors[]='Проверьте длину ОГРН/ОГРНИП: ООО — 13 цифр, ИП — 15.';
    if(!filter_var($seller['email']??'',FILTER_VALIDATE_EMAIL))$errors[]='Укажите корректный email.';
    if($ip&&trim((string)($seller['registration']??''))==='')$errors[]='Заполните сведения о регистрации ИП.';
    $body=legal_expand($doc['body'],$seller);
    if(preg_match('/\{\{|\[ЗАПОЛНИТЬ/iu',$body))$errors[]='В тексте остались незаполненные поля. Замените все отметки [ЗАПОЛНИТЬ…] и заполните используемые реквизиты.';
    if(mb_strlen(trim($doc['body']))<50)$errors[]='Документ слишком короткий.';
    return $errors;
}
function legal_apply(array $state,array $in,int $adminId): array {
    if(!isset($in['revision'])||(int)$in['revision']!==$state['revision'])throw new RuntimeException('conflict');
    $action=$in['action']??'';
    if($action==='seller'){
        if(!is_array($in['seller']??null))throw new InvalidArgumentException('bad_seller');
        foreach(legal_fields() as $key=>$label){
            $value=$in['seller'][$key]??'';if(!is_string($value)||mb_strlen($value)>2000)throw new InvalidArgumentException('bad_seller');
            $state['seller'][$key]=trim($value);
        }
    }else{
        $slug=$in['slug']??'';if(!is_string($slug)||!isset($state['documents'][$slug]))throw new InvalidArgumentException('bad_document');
        $doc=&$state['documents'][$slug];
        if(in_array($action,['save','publish'],true)){
            if(!is_string($in['title']??null)||!is_string($in['body']??null)||mb_strlen(trim($in['title']))<3||mb_strlen($in['title'])>200||mb_strlen(trim($in['body']))<20||mb_strlen($in['body'])>60000)throw new InvalidArgumentException('bad_text');
            $doc['title']=trim($in['title']);$doc['body']=trim($in['body']);
            if($action==='publish'){
                $errors=legal_publication_errors($doc,$state['seller']);if($errors)throw new InvalidArgumentException(implode("\n",$errors));
                if($doc['published'])$doc['history'][]=$doc['published'];
                $publishedBody=legal_expand($doc['body'],$state['seller']);
                if($slug==='seller'&&$state['seller']['seller_type']==='ИП')$publishedBody.="\n\nСведения о государственной регистрации ИП и регистрирующем органе: ".$state['seller']['registration'].'.';
                if($slug==='seller'&&trim($state['seller']['bank_details'])!=='')$publishedBody.="\n\nБанковские реквизиты: ".$state['seller']['bank_details'];
                $doc['published']=['title'=>$doc['title'],'body'=>$publishedBody,'version'=>$state['revision']+1,'published_at'=>date('c'),'by'=>$adminId];
            }
        }elseif($action==='unpublish'){
            if($doc['published'])$doc['history'][]=$doc['published'];$doc['published']=null;
        }elseif($action==='restore'){
            $version=(int)($in['version']??0);$found=null;foreach($doc['history'] as $old)if((int)$old['version']===$version)$found=$old;
            if(!$found)throw new InvalidArgumentException('version_not_found');$doc['title']=$found['title'];$doc['body']=$found['body'];
        }else throw new InvalidArgumentException('bad_action');
        $doc['history']=array_slice($doc['history'],-20);$doc['updated_at']=date('c');
        unset($doc);
    }
    $state['revision']++;return $state;
}
function legal_public_documents(array $state): array {
    $out=[];foreach($state['documents'] as $slug=>$d)if(!empty($d['published'])){
        $p=$d['published'];$out[$slug]=['slug'=>$slug,'title'=>$p['title'],'body'=>$p['body'],'version'=>$p['version'],'published_at'=>$p['published_at']];
    }
    return $out;
}
