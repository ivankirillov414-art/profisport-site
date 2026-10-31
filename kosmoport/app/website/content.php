<?php
declare(strict_types=1);

function kp_default_content(): array {
    return json_decode((string)file_get_contents(__DIR__.'/default-content.json'), true, 64, JSON_THROW_ON_ERROR);
}

function kp_content_error(string $message): never {
    throw new InvalidArgumentException($message);
}

function kp_text_value(mixed $value, int $max, bool $empty = true): string {
    if (!is_string($value) || strlen($value) > $max || (!$empty && trim($value) === '') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value)) {
        kp_content_error('Проверьте длину и формат текстовых полей сайта.');
    }
    if (preg_match('/<\s*\/?\s*[a-z!]|javascript\s*:|data\s*:\s*text\/html/i', $value)) {
        kp_content_error('В текстах сайта нельзя сохранять HTML и исполняемые ссылки.');
    }
    return trim($value);
}

function kp_validate_content(array $input): array {
    $defaults = kp_default_content();
    if (array_diff(array_keys($input), array_keys($defaults))) kp_content_error('В содержимом сайта есть неизвестные разделы.');
    foreach (array_keys($defaults) as $key) if (!array_key_exists($key, $input)) kp_content_error('Не хватает раздела сайта: '.$key);
    $out = $defaults;
    $out['brand'] = kp_text_value($input['brand'], 120, false);
    $out['tagline'] = kp_text_value($input['tagline'], 240, false);
    foreach (['event','tickets','contact','fields'] as $section) {
        if (!is_array($input[$section]) || array_diff(array_keys($input[$section]), array_keys($defaults[$section]))) kp_content_error('Неизвестные поля раздела '.$section.'.');
        foreach (array_keys($defaults[$section]) as $key) if (!array_key_exists($key, $input[$section])) kp_content_error('Не хватает поля '.$section.'.'.$key);
    }
    foreach (['city'=>160, 'venue'=>240, 'address'=>400, 'hours'=>400] as $key=>$max) $out['event'][$key] = kp_text_value($input['event'][$key], $max, $key==='hours');
    foreach (['opening_date','closing_date'] as $key) {
        $value = $input['event'][$key];
        if ($value !== null && (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || !checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4)))) kp_content_error('Дата выставки должна быть в формате ГГГГ-ММ-ДД.');
        $out['event'][$key] = $value;
    }
    if (!$out['event']['opening_date']) kp_content_error('Укажите дату открытия выставки.');
    if ($out['event']['closing_date'] && $out['event']['closing_date'] < $out['event']['opening_date']) kp_content_error('Дата окончания выставки раньше открытия.');
    $price = $input['tickets']['price'];
    if ($price !== null && ((!is_int($price) && !is_float($price)) || !is_finite((float)$price) || $price < 0 || $price > 1000000 || round((float)$price,2) !== (float)$price)) kp_content_error('Стоимость билета должна быть числом или null.');
    $out['tickets']['price'] = $price;
    if (!is_bool($input['tickets']['sales_open'])) kp_content_error('Флаг продажи должен быть true или false.');
    $out['tickets']['sales_open'] = $input['tickets']['sales_open'];
    $url = kp_text_value($input['tickets']['checkout_url'], 1500);
    if ($url !== '' && (!filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https' || parse_url($url,PHP_URL_USER) !== null || parse_url($url,PHP_URL_PASS)!==null)) kp_content_error('Укажите полный HTTPS-адрес покупки билетов без логина и пароля.');
    if ($out['tickets']['sales_open'] && ($url === '' || $price === null)) kp_content_error('Для открытия продажи нужны цена и адрес покупки.');
    $out['tickets']['checkout_url'] = $url;
    $out['tickets']['invitation_terms'] = kp_text_value($input['tickets']['invitation_terms'], 2500, false);
    foreach (['phone'=>100,'email'=>254,'vk_url'=>1500] as $key=>$max) $out['contact'][$key] = kp_text_value($input['contact'][$key], $max);
    if ($out['contact']['email']!=='' && !filter_var($out['contact']['email'],FILTER_VALIDATE_EMAIL)) kp_content_error('Проверьте электронную почту организатора.');
    if ($out['contact']['phone']!=='' && !preg_match('/^[+\d\s()\-]{5,100}$/D',$out['contact']['phone'])) kp_content_error('Проверьте номер телефона организатора.');
    if ($out['contact']['vk_url']!=='' && (!filter_var($out['contact']['vk_url'],FILTER_VALIDATE_URL) || strtolower((string)parse_url($out['contact']['vk_url'],PHP_URL_SCHEME))!=='https')) kp_content_error('Ссылка сообщества должна начинаться с https://.');
    $schema = json_decode((string)file_get_contents(__DIR__.'/field-schema.json'),true,32,JSON_THROW_ON_ERROR);
    foreach ($schema as $id=>$kind) {
        $value=kp_text_value($input['fields'][$id],$kind==='text'?5000:1500);
        if ($kind==='image' && !preg_match('~^site/assets/[a-zA-Z0-9][a-zA-Z0-9._-]*\.(?:webp|png|jpe?g)$~D',$value)) kp_content_error('Для изображений используйте файл из site/assets/.');
        if ($kind==='image' && !is_file(dirname(__DIR__,2).'/public/'.$value)) kp_content_error('Изображение отсутствует в пакете сайта.');
        $out['fields'][$id]=$value;
    }
    return $out;
}

function kp_website_row(bool $lock=false): ?array {
    $db=crm_db();
    $sql='SELECT * FROM kcrm_website WHERE id=1';
    if ($lock && $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $sql.=' FOR UPDATE';
    $row=$db->query($sql)->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}

function kp_read_published(): array {
    if (!function_exists('crm_db')) return ['content'=>kp_default_content(),'revision'=>0];
    try {
        $row=kp_website_row();
        if ($row && $row['published']!==null) return ['content'=>kp_validate_content(json_decode($row['published'],true,64,JSON_THROW_ON_ERROR)),'revision'=>(int)$row['published_revision']];
    } catch (Throwable $error) {
        error_log('Kosmoport website uses packaged content: '.$error->getMessage());
    }
    return ['content'=>kp_default_content(),'revision'=>0];
}

function kp_website_change(array $input, bool $publish): array {
    $user=crm_require_roles(['editor']);
    $revision=$input['expected_revision']??null;
    if ((!is_int($revision) && !(is_string($revision)&&ctype_digit($revision))) || (int)$revision<0) throw new InvalidArgumentException('Для сохранения нужна ожидаемая версия сайта.');
    if (!$publish && !is_array($input['content']??null)) throw new InvalidArgumentException('Содержимое сайта должно быть JSON-объектом.');
    $newContent=$publish?null:kp_validate_content($input['content']);
    $db=crm_db();
    $db->beginTransaction();
    try {
        $row=kp_website_row(true);
        $current=(int)($row['revision']??0);
        if ($current!==(int)$revision) {
            $db->rollBack();
            crm_json(['ok'=>false,'error'=>['code'=>'website_conflict','message'=>'Сайт уже изменён другим сотрудником. Обновите версию перед сохранением; ваш текст сохранён в редакторе.']],409);
        }
        if ($publish && !$row) throw new InvalidArgumentException('Сначала сохраните черновик сайта.');
        $content=$publish?kp_validate_content(json_decode($row['draft'],true,64,JSON_THROW_ON_ERROR)):$newContent;
        $json=json_encode($content,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $now=gmdate('Y-m-d H:i:s');
        $next=$current+1;
        if (!$row) {
            $stmt=$db->prepare('INSERT INTO kcrm_website (id,draft,published,revision,published_revision,updated_by,updated_at,published_at) VALUES(1,?,NULL,?,0,?,?,NULL)');
            $stmt->execute([$json,$next,(string)$user['id'],$now]);
        } elseif ($publish) {
            $stmt=$db->prepare('UPDATE kcrm_website SET published=?,published_revision=?,revision=?,updated_by=?,updated_at=?,published_at=? WHERE id=1 AND revision=?');
            $stmt->execute([$json,$next,$next,(string)$user['id'],$now,$now,$current]);
            if ($stmt->rowCount()!==1) throw new RuntimeException('Conflict while publishing.');
        } else {
            $stmt=$db->prepare('UPDATE kcrm_website SET draft=?,revision=?,updated_by=?,updated_at=? WHERE id=1 AND revision=?');
            $stmt->execute([$json,$next,(string)$user['id'],$now,$current]);
            if ($stmt->rowCount()!==1) throw new RuntimeException('Conflict while saving.');
        }
        crm_audit($publish?'website.publish':'website.save','website','main',['revision'=>$next]);
        $db->commit();
        return ['content'=>$content,'revision'=>$next,'published_revision'=>$publish?$next:(int)($row['published_revision']??0)];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($error instanceof PDOException && (int)($error->errorInfo[1]??0)===1062) crm_json(['ok'=>false,'error'=>['code'=>'website_conflict','message'=>'Сайт уже изменён другим сотрудником. Обновите версию перед сохранением.']],409);
        throw $error;
    }
}

function kp_escape(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function kp_money(mixed $value): string { return $value===null?'Скоро':number_format((float)$value,($value==floor((float)$value)?0:2),',',' ').' ₽'; }
function kp_date_label(string $value): string {
    $months=['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    return (int)substr($value,8,2).' '.$months[(int)substr($value,5,2)-1].' '.substr($value,0,4);
}
function kp_event_dates(array $event): string { return $event['closing_date']?kp_date_label($event['opening_date']).' — '.kp_date_label($event['closing_date']):'С '.kp_date_label($event['opening_date']); }
