<?php
declare(strict_types=1);

/** Optional site services. Closed until the owner configures identity and privacy. */
function kp_site_settings(): array {
    $settings=[];
    try { if (function_exists('crm_config')) $settings=crm_config('website')??[]; } catch (Throwable) {}
    if (!is_array($settings)) $settings=[];
    $text=static fn(string $key,int $max):string=>is_string($settings[$key]??null)?trim(substr($settings[$key],0,$max)):'';
    $https=static fn(string $value):bool=>filter_var($value,FILTER_VALIDATE_URL)!==false && strtolower((string)parse_url($value,PHP_URL_SCHEME))==='https' && parse_url($value,PHP_URL_USER)===null && parse_url($value,PHP_URL_PASS)===null;
    $public=rtrim($text('public_url',1500),'/');
    $privacy=$text('privacy_url',1500);
    $organizer=$text('organizer_name',300);
    return ['public_url'=>$https($public)?$public:'','privacy_url'=>$https($privacy)?$privacy:'','organizer_name'=>$organizer,'requests_enabled'=>($settings['requests_enabled']??false)===true && $https($privacy) && $organizer!==''];
}

function kp_request_table_ready(): bool {
    if (!function_exists('crm_db')) return false;
    try { crm_db()->query('SELECT id FROM kcrm_visit_requests WHERE 1=0'); return true; } catch (Throwable) { return false; }
}

function kp_requests_ready(): bool { return kp_site_settings()['requests_enabled'] && kp_request_table_ready(); }

function kp_request_text(mixed $value,string $label,int $max,bool $required=false): string {
    if (!is_string($value) || strlen($value)>$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value)) crm_fail('REQUEST_INVALID','Проверьте поле «'.$label.'».',422);
    $value=trim($value);
    if ($required && $value==='') crm_fail('REQUEST_INVALID','Заполните поле «'.$label.'».',422);
    return $value;
}

function kp_visit_request(array $input): array {
    if (!kp_requests_ready()) crm_fail('REQUESTS_CLOSED','Приём заявок пока не открыт.',503);
    if (($input['consent']??null)!==true) crm_fail('CONSENT_REQUIRED','Для отправки нужно согласие на обработку данных заявки.',422);
    if (($input['website']??'')!=='') crm_fail('REQUEST_INVALID','Не удалось отправить заявку.',422);
    $requestId=$input['request_id']??'';
    if (!is_string($requestId) || !preg_match('/^[a-f0-9]{32}$/D',$requestId)) crm_fail('REQUEST_INVALID','Обновите форму и повторите отправку.',422);
    $kind=$input['kind']??'';
    if (!in_array($kind,['visit','group','notify'],true)) crm_fail('REQUEST_INVALID','Выберите тип обращения.',422);
    $name=kp_request_text($input['name']??null,'Имя',200,true);
    $contact=kp_request_text($input['contact']??null,'Телефон или почта',254,true);
    $phone=preg_replace('/[\s()\-]/','',$contact);
    if (!filter_var($contact,FILTER_VALIDATE_EMAIL) && !preg_match('/^\+?\d{10,15}$/D',$phone)) crm_fail('REQUEST_INVALID','Укажите телефон с кодом страны или электронную почту.',422);
    $org=kp_request_text($input['org']??'','Организация',300,$kind==='group');
    $message=kp_request_text($input['message']??'','Пожелания',3000);
    $source=kp_request_text($input['source']??'','Источник',300);
    $quantity=$input['quantity']??null;
    if (!is_int($quantity) || $quantity<1 || $quantity>200 || ($kind==='group' && $quantity<2)) crm_fail('REQUEST_INVALID','Проверьте количество гостей.',422);
    $date=kp_request_text($input['date']??'','Дата',10,true);
    $event=kp_read_published()['content']['event'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)) || $date<$event['opening_date'] || ($event['closing_date'] && $date>$event['closing_date'])) crm_fail('REQUEST_INVALID','Выберите дату в период выставки.',422);
    crm_session();
    $key=(string)crm_config('app_key');
    $dedupe=hash_hmac('sha256',session_id().'|'.$requestId,$key);
    $ip=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'),$key);
    $db=crm_db();
    $existing=$db->prepare('SELECT id FROM kcrm_visit_requests WHERE dedupe_key=?');$existing->execute([$dedupe]);
    if ($old=$existing->fetchColumn()) return ['id'=>$old,'saved'=>true,'duplicate'=>true,'paid'=>false,'reserved'=>false];
    try { crm_rate_consume(crm_rate_bucket('public-visit',(string)($_SERVER['REMOTE_ADDR']??'unknown')),5); }
    catch (CrmHttpException $error) { if ($error->status===429) crm_fail('REQUEST_RATE_LIMIT','Слишком много заявок. Повторите через 15 минут.',429);throw $error; }
    $db->beginTransaction();
    try {
        $query=$db->prepare('SELECT id FROM kcrm_visit_requests WHERE dedupe_key=?');$query->execute([$dedupe]);
        if ($old=$query->fetchColumn()) {$db->commit();return ['id'=>$old,'saved'=>true,'duplicate'=>true,'paid'=>false,'reserved'=>false];}
        $id=bin2hex(random_bytes(16));$now=gmdate('Y-m-d H:i:s');$policy=kp_site_settings()['privacy_url'];
        $sql='INSERT INTO kcrm_visit_requests(id,dedupe_key,kind,name,contact,organization,guest_count,visit_date,message,source,status,revision,consent_at,privacy_url,ip_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?,?)';
        $db->prepare($sql)->execute([$id,$dedupe,$kind,$name,$contact,$org,$quantity,$date,$message,$source,'new',$now,$policy,$ip,$now,$now]);
        $db->commit();
        return ['id'=>$id,'saved'=>true,'duplicate'=>false,'paid'=>false,'reserved'=>false];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($error instanceof PDOException && (int)($error->errorInfo[1]??0)===1062) {
            $query=$db->prepare('SELECT id FROM kcrm_visit_requests WHERE dedupe_key=?');$query->execute([$dedupe]);
            if ($old=$query->fetchColumn()) return ['id'=>$old,'saved'=>true,'duplicate'=>true,'paid'=>false,'reserved'=>false];
        }
        throw $error;
    }
}

function kp_visit_requests(): array {
    crm_require_roles(['manager','editor']);[$limit,$offset]=crm_page();
    if (!kp_request_table_ready()) crm_fail('REQUESTS_NOT_INSTALLED','Раздел заявок ещё не установлен. Администратору нужно применить миграцию сайта.',503);
    $where='';$values=[];
    if (isset($_GET['status']) && $_GET['status']!=='') {
        if (!in_array($_GET['status'],['new','contacted','confirmed','cancelled'],true)) crm_fail('REQUEST_INVALID','Неизвестный статус.',422);
        $where=' WHERE status=?';$values[]=$_GET['status'];
    }
    $db=crm_db();$count=$db->prepare('SELECT COUNT(*) FROM kcrm_visit_requests'.$where);$count->execute($values);
    $query=$db->prepare('SELECT id,kind,name,contact,organization,guest_count,visit_date,message,source,status,revision,created_at,updated_at FROM kcrm_visit_requests'.$where.' ORDER BY created_at DESC,id LIMIT '.$limit.' OFFSET '.$offset);$query->execute($values);
    return ['items'=>$query->fetchAll(PDO::FETCH_ASSOC),'total'=>(int)$count->fetchColumn(),'limit'=>$limit,'offset'=>$offset];
}

function kp_visit_request_status(array $input): array {
    $actor=crm_require_roles(['manager']);
    if (!kp_request_table_ready()) crm_fail('REQUESTS_NOT_INSTALLED','Раздел заявок ещё не установлен. Администратору нужно применить миграцию сайта.',503);
    $id=$input['id']??'';$status=$input['status']??'';$revision=$input['expected_revision']??null;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id) || !in_array($status,['new','contacted','confirmed','cancelled'],true) || !is_int($revision) || $revision<1) crm_fail('REQUEST_INVALID','Проверьте статус и версию заявки.',422);
    $db=crm_db();$db->beginTransaction();
    try {
        $query=$db->prepare('UPDATE kcrm_visit_requests SET status=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?');$query->execute([$status,gmdate('Y-m-d H:i:s'),$id,$revision]);
        if ($query->rowCount()!==1) crm_fail('REQUEST_CONFLICT','Заявка уже изменилась. Обновите список.',409);
        crm_audit('website.request.status','website_request',$id,['status'=>$status]);$db->commit();
        return ['id'=>$id,'status'=>$status,'revision'=>$revision+1];
    } catch(Throwable $error) {if($db->inTransaction())$db->rollBack();throw $error;}
}

function kp_checkout_target(array $content): ?string {
    require_once __DIR__.'/pricing.php';
    if (!kp_catalog_live_sales_ready()) return null;
    if ($content['tickets']['sales_open']!==true || !is_numeric($content['tickets']['price'])) return null;
    $url=$content['tickets']['checkout_url'];
    if (!is_string($url) || !filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https' || parse_url($url,PHP_URL_USER)!==null || parse_url($url,PHP_URL_PASS)!==null) return null;
    return $url;
}
