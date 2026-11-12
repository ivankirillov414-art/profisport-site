<?php
declare(strict_types=1);

const MK_MAX_BYTES = 4 * 1024 * 1024;
const MK_COLLECTIONS = ['tasks', 'campaigns', 'documents', 'metrics'];

function mk_empty_workspace(): array {
    return ['schema'=>1,'revision'=>1,'title'=>'Сайт и маркетинг','updated_at'=>null,
        'members'=>[],'excluded_names'=>[],'tasks'=>[],'campaigns'=>[],'documents'=>[],
        'metrics'=>[],'sources'=>[],'datasets'=>[],'assets'=>[],
        'legacy_visuals'=>['status'=>'archived','note'=>'Прежние визуалы не входят в действующую библиотеку. Исходные файлы и история сохранены.']];
}

function mk_read_file(): array {
    $file = crm_storage_path('marketing/workspace.json');
    if (!is_file($file)) return mk_empty_workspace();
    if (filesize($file) > MK_MAX_BYTES) crm_fail('WORKSPACE_TOO_LARGE','База маркетинга превышает допустимый размер. Обратитесь к владельцу.',503);
    try { $data = json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR); }
    catch (JsonException) { crm_fail('WORKSPACE_UNAVAILABLE','Не удалось прочитать базу маркетинга. Предыдущие данные не изменены.',503); }
    if (!is_array($data) || ($data['schema']??null)!==1 || !is_int($data['revision']??null)) crm_fail('WORKSPACE_UNAVAILABLE','Проверьте формат базы маркетинга.',503);
    return array_replace(mk_empty_workspace(),$data);
}

function mk_actor(array $workspace, bool $edit=false, bool $ownerOnly=false): array {
    $actor = crm_require_roles(KCRM_ROLES);
    $name = trim((string)($actor['name']??''));
    foreach ($workspace['excluded_names'] as $excluded) {
        if (is_string($excluded) && $excluded!=='' && preg_match('~(?:^|\s)'.preg_quote($excluded,'~').'(?:\s|$)~iu',$name)) crm_fail('MARKETING_FORBIDDEN','Доступ к рабочему пространству маркетинга не подключён.',403);
    }
    if (($actor['role']??'')==='owner') return $actor;
    if ($ownerOnly) crm_fail('FORBIDDEN','Управлять доступом может только владелец.',403);
    foreach ($workspace['members'] as $member) {
        if (($member['user_id']??'')===$actor['id'] && (!$edit || ($member['permission']??'')==='edit')) return $actor;
    }
    crm_fail('MARKETING_FORBIDDEN','Доступ к рабочему пространству маркетинга не подключён. Обратитесь к владельцу.',403);
}

function mk_workspace_view(): array {
    crm_require_roles(KCRM_ROLES);
    $workspace = mk_read_file(); $actor = mk_actor($workspace);
    $permission = ($actor['role']??'')==='owner' ? 'owner' : 'view';
    foreach ($workspace['members'] as $member) if (($member['user_id']??'')===$actor['id'] && ($member['permission']??'')==='edit') $permission='edit';
    $workspace['permission']=$permission;
    $workspace['initialization_available']=$permission==='owner' && !is_file(crm_storage_path('marketing/workspace.json')) && is_file(crm_storage_path('marketing/import.json'));
    $workspace['bundle_upload_available']=$permission==='owner' && !is_file(crm_storage_path('marketing/workspace.json')) && !is_file(crm_storage_path('marketing/import.json'));
    if ($workspace['initialization_available']) {
        try { $pending=json_decode((string)file_get_contents(crm_storage_path('marketing/import.json')),true,32,JSON_THROW_ON_ERROR); $workspace['pending_import']=mk_seed_counts(mk_validate_seed($pending)); }
        catch (Throwable) { $workspace['pending_import']=['invalid'=>true]; }
    }
    unset($workspace['excluded_names']);
    foreach ($workspace['assets'] as &$asset) {
        foreach (['file','preview_file'] as $key) {
            $asset[$key.'_available']=!empty($asset[$key]) && is_file(crm_storage_path('marketing/'.$asset[$key]));
            unset($asset[$key]);
        }
    } unset($asset);
    if ($permission!=='owner') unset($workspace['members']);
    return $workspace;
}

function mk_mutate(array $input, Closure $mutation, bool $ownerOnly=false): array {
    crm_require_roles(KCRM_ROLES);
    $directory = crm_storage_path('marketing');
    if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) crm_fail('STORAGE_UNAVAILABLE','Не удалось открыть базу маркетинга.',503);
    $lock = fopen(crm_storage_path('marketing/workspace.lock'),'c+');
    if ($lock===false) crm_fail('STORAGE_UNAVAILABLE','Не удалось заблокировать базу маркетинга.',503);
    $temp=null;
    try {
        $deadline=microtime(true)+3;
        while (!flock($lock,LOCK_EX|LOCK_NB)) { if (microtime(true)>$deadline) crm_fail('WORKSPACE_BUSY','Базу сохраняет другой сотрудник. Повторите через несколько секунд.',409); usleep(20000); }
        $workspace=mk_read_file(); $actor=mk_actor($workspace,true,$ownerOnly);
        if (!is_int($input['expected_revision']??null) || $input['expected_revision']!==$workspace['revision']) crm_fail('REVISION_CONFLICT','Данные уже изменились. Ваш текст остался в форме; обновите раздел перед повторным сохранением.',409);
        $workspace=$mutation($workspace,$actor); $workspace['revision']++; $workspace['updated_at']=gmdate('c');
        $json=json_encode($workspace,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($json)>MK_MAX_BYTES) crm_fail('WORKSPACE_TOO_LARGE','Недостаточно места в базе маркетинга для этой записи.',413);
        $temp=crm_storage_path('marketing/save-'.bin2hex(random_bytes(12)).'.tmp');
        if (file_put_contents($temp,$json)!==strlen($json)) crm_fail('STORAGE_UNAVAILABLE','Не удалось сохранить изменения. Предыдущие данные не изменены.',503);
        if (!rename($temp,crm_storage_path('marketing/workspace.json'))) crm_fail('STORAGE_UNAVAILABLE','Не удалось заменить базу. Предыдущие данные не изменены.',503);
        $temp=null;
        $audit=['revision'=>$workspace['revision'],'actor_id'=>$actor['id'],'at'=>$workspace['updated_at'],'collection'=>$input['collection']??'members'];
        file_put_contents(crm_storage_path('marketing/history.ndjson'),json_encode($audit,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        try { crm_audit('marketing.workspace.update','marketing',null,['revision'=>$workspace['revision'],'collection'=>$input['collection']??'members']); }
        catch (Throwable) { error_log('Kosmoport marketing: database audit unavailable; private revision history retained.'); }
        return ['revision'=>$workspace['revision'],'updated_at'=>$workspace['updated_at']];
    } finally {
        if ($temp!==null && is_file($temp)) unlink($temp);
        flock($lock,LOCK_UN); fclose($lock);
    }
}

function mk_url(mixed $value): string {
    $url=crm_text($value,'Ссылка',1500);
    if ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['https','http'],true) || parse_url($url,PHP_URL_USER)!==null || parse_url($url,PHP_URL_PASS)!==null)) crm_fail('INVALID_URL','Ссылка должна начинаться с https:// или http:// и не содержать паролей.');
    return $url;
}

function mk_number(mixed $value,string $label,float $max=1000000000): ?float {
    if ($value===null || $value==='') return null;
    if ((!is_int($value)&&!is_float($value)) || !is_finite((float)$value) || $value<0 || $value>$max) crm_fail('INVALID_FIELD','Проверьте поле «'.$label.'».');
    return (float)$value;
}

function mk_record_fields(string $collection,array $input): array {
    $row=['title'=>crm_text($input['title']??'','Название',240,true),
        'body'=>crm_text($input['body']??'','Описание',50000),
        'source_ids'=>[]];
    $sources=$input['source_ids']??[];
    if (!is_array($sources) || !array_is_list($sources) || count($sources)>30) crm_fail('INVALID_FIELD','Выберите не более 30 источников списком.');
    foreach ($sources as $id) $row['source_ids'][]=crm_identifier($id);
    $row['source_ids']=array_values(array_unique($row['source_ids']));
    if (in_array($collection,['tasks','campaigns'],true)) {
        $row['status']=crm_choice($input['status']??'todo',['todo','in_progress','waiting','done','archived'],'Статус');
        $row['due_date']=crm_date($input['due_date']??null);
        $row['assignee']=crm_text($input['assignee']??'','Ответственный',120);
    }
    if ($collection==='campaigns') {
        $row['channel']=crm_text($input['channel']??'','Канал',120);
        $row['budget']=mk_number($input['budget']??null,'Бюджет');
        $row['start_date']=crm_date($input['start_date']??null); $row['end_date']=crm_date($input['end_date']??null);
        if ($row['start_date'] && $row['end_date'] && $row['start_date']>$row['end_date']) crm_fail('INVALID_DATE','Дата окончания кампании раньше начала.');
    }
    if ($collection==='documents') $row['category']=crm_choice($input['category']??'knowledge',['knowledge','brief','meeting','partner','research'],'Раздел');
    if ($collection==='metrics') {
        $row['kind']=crm_choice($input['kind']??'forecast',['forecast','actual'],'Вид показателя');
        $row['channel']=crm_text($input['channel']??'','Канал',120);
        $row['reach']=mk_number($input['reach']??null,'Охват'); $row['spend']=mk_number($input['spend']??null,'Расход');
        $row['conversion_rate']=mk_number($input['conversion_rate']??null,'Доля отклика',1);
        $row['guests_per_response']=mk_number($input['guests_per_response']??1,'Гостей на отклик',100);
        $row['actual_guests']=mk_number($input['actual_guests']??null,'Фактические гости');
        $row['as_of']=crm_date($input['as_of']??null);
        $row['assumptions']=crm_text($input['assumptions']??'','Допущения',10000);
        if ($row['kind']==='actual' && $row['source_ids']===[]) crm_fail('SOURCE_REQUIRED','Для фактического показателя укажите источник.');
    }
    return $row;
}

function mk_record_save(array $input): array {
    $collection=crm_choice($input['collection']??'',MK_COLLECTIONS,'Раздел');
    $id=crm_identifier($input['id']??null,true); $fields=mk_record_fields($collection,$input);
    return mk_mutate($input,static function(array $workspace,array $actor) use($collection,$id,$fields,$input): array {
        $sourceIds=array_column($workspace['sources'],'id');
        if (array_diff($fields['source_ids'],$sourceIds)) crm_fail('SOURCE_NOT_FOUND','Один из выбранных источников не найден. Обновите раздел.');
        $rows=$workspace[$collection]; $index=null;
        foreach ($rows as $key=>$row) if ($row['id']===$id) $index=$key;
        if ($id!==null && $index===null) crm_fail('NOT_FOUND','Запись не найдена.',404);
        if (count($rows)>=1000 && $index===null) crm_fail('RECORD_LIMIT','В разделе уже 1000 записей.');
        $record=['id'=>$id??crm_uuid(),...$fields,'archived'=>false,'updated_at'=>gmdate('c'),'updated_by'=>$actor['id']];
        if ($index!==null) $record=array_replace($rows[$index],$record);
        if (array_key_exists('archived',$input)) $record['archived']=crm_boolean($input['archived']);
        if (($record['status']??'')==='archived') $record['archived']=true;
        if ($index===null) $rows[]=$record; else $rows[$index]=$record;
        $workspace[$collection]=$rows;
        return $workspace;
    });
}

function mk_members_save(array $input): array {
    $members=$input['members']??null;
    if (!is_array($members) || count($members)>100) crm_fail('INVALID_FIELD','Проверьте список участников.');
    return mk_mutate($input,static function(array $workspace,array $actor) use($members): array {
        $out=[];
        foreach ($members as $member) {
            if (!is_array($member)) crm_fail('INVALID_FIELD','Проверьте участника.');
            $id=crm_identifier($member['user_id']??null); $permission=crm_choice($member['permission']??'view',['view','edit'],'Доступ');
            $user=crm_find('kcrm_users',$id);
            if (!(bool)$user['active']) crm_fail('MEMBER_INACTIVE','Нельзя подключить отключённую учётную запись.');
            foreach ($workspace['excluded_names'] as $excluded) if (preg_match('~(?:^|\s)'.preg_quote($excluded,'~').'(?:\s|$)~iu',(string)$user['name'])) crm_fail('MEMBER_EXCLUDED','Этот сотрудник не подключается к маркетинговому пространству.');
            $out[$id]=['user_id'=>$id,'name'=>$user['name'],'permission'=>$permission];
        }
        $workspace['members']=array_values($out); return $workspace;
    },true);
}

function mk_member_candidates(): array {
    crm_require_roles(KCRM_ROLES);
    $workspace=mk_read_file(); mk_actor($workspace,false,true); $items=[];
    foreach (crm_users_list()['items'] as $user) {
        $excluded=false;
        foreach ($workspace['excluded_names'] as $name) if (preg_match('~(?:^|\s)'.preg_quote($name,'~').'(?:\s|$)~iu',(string)$user['name'])) $excluded=true;
        if (!$excluded && ($user['active']??true)) $items[]=array_intersect_key($user,array_flip(['id','name','role']));
    }
    return ['items'=>$items];
}

function mk_dataset_row_save(array $input): array {
    $datasetId=crm_identifier($input['dataset_id']??null); $rowId=crm_identifier($input['row_id']??null);
    $work=['status'=>crm_choice($input['status']??'new',['new','negotiating','working','declined','paused'],'Статус работы'),
        'visit_status'=>crm_choice($input['visit_status']??'unknown',['unknown','not_visited','visited'],'Посещение'),
        'last_contact_date'=>crm_date($input['last_contact_date']??null),'next_date'=>crm_date($input['next_date']??null),
        'assignee'=>crm_text($input['assignee']??'','Ответственный',120),'next_action'=>crm_text($input['next_action']??'','Следующий шаг',3000),
        'notes'=>crm_text($input['notes']??'','Рабочие заметки',10000)];
    if ($work['visit_status']==='visited' && $work['last_contact_date']===null) crm_fail('CONTACT_DATE_REQUIRED','Для отметки «Посетили» укажите дату контакта.');
    return mk_mutate($input,static function(array $workspace,array $actor) use($datasetId,$rowId,$work): array {
        foreach ($workspace['datasets'] as &$dataset) if ($dataset['id']===$datasetId) {
            foreach ($dataset['rows'] as &$row) if ($row['id']===$rowId) { $row['work']=[...$work,'updated_at'=>gmdate('c'),'updated_by'=>$actor['id']]; return $workspace; }
        }
        crm_fail('NOT_FOUND','Точка не найдена в адресной базе.',404);
    });
}

function mk_validate_seed(mixed $seed): array {
    try { return mk_validate_seed_fields($seed); }
    catch(CrmHttpException $error){if($error->status<500&&$error->errorCode!=='INVALID_IMPORT')crm_fail('INVALID_IMPORT',$error->getMessage(),422);throw $error;}
}

function mk_validate_seed_fields(mixed $seed): array {
    if (!is_array($seed)||($seed['schema']??null)!==1||($seed['revision']??null)!==1||($seed['members']??null)!==[]) crm_fail('INVALID_IMPORT','Нужна начальная база без подключённых сотрудников.');
    foreach ([...MK_COLLECTIONS,'sources','datasets','assets','excluded_names'] as $collection) if (!is_array($seed[$collection]??null) || !array_is_list($seed[$collection]) || count($seed[$collection])>1000) crm_fail('INVALID_IMPORT','Проверьте разделы подготовленной базы.');
    crm_text($seed['title']??'','Название пространства',240,true);
    if (count($seed['assets'])>4 || count($seed['sources'])>200 || count($seed['datasets'])>50 || count($seed['excluded_names'])>20) crm_fail('INVALID_IMPORT','Превышен размер начальной базы.');
    foreach ($seed['excluded_names'] as $name) crm_text($name,'Имя исключённого участника',120,true);
    $sourceIds=[]; $sourceSeen=[];
    foreach ($seed['sources'] as $key=>$source) {
        if (!is_array($source)) crm_fail('INVALID_IMPORT','Проверьте источник.');
        $id=crm_identifier($source['id']??null);if(isset($sourceSeen[$id]))crm_fail('INVALID_IMPORT','Повторяется источник.');$sourceSeen[$id]=true;$sourceIds[]=$id;
        $seed['sources'][$key]=['id'=>$id,'title'=>crm_text($source['title']??'','Название источника',500,true),'url'=>mk_url($source['url']??''),
            'updated_at'=>crm_text($source['updated_at']??'','Дата источника',80),'access_type'=>crm_text($source['access_type']??'','Тип источника',120),'notes'=>crm_text($source['notes']??'','Описание источника',10000)];
    }
    foreach (MK_COLLECTIONS as $collection) {
        $seen=[];
        foreach ($seed[$collection] as $key=>$row) {
            if (!is_array($row)) crm_fail('INVALID_IMPORT','Проверьте запись базы.');
            $id=crm_identifier($row['id']??null);if(isset($seen[$id]))crm_fail('INVALID_IMPORT','Повторяется запись базы.');$seen[$id]=true;
            $validated=mk_record_fields($collection,$row);
            if (array_diff($validated['source_ids'],$sourceIds)) crm_fail('INVALID_IMPORT','Запись ссылается на отсутствующий источник.');
            $normalized=['id'=>$id,...$validated,'archived'=>array_key_exists('archived',$row)?crm_boolean($row['archived']):false,'updated_at'=>crm_text($row['updated_at']??'','Дата записи',80)];
            if(array_key_exists('proposal',$row))$normalized['proposal']=crm_boolean($row['proposal']);
            $seed[$collection][$key]=$normalized;
        }
    }
    $datasetSeen=[]; $totalRows=0;
    foreach ($seed['datasets'] as $datasetIndex=>$dataset) {
        if (!is_array($dataset)||!is_array($dataset['rows']??null)||!array_is_list($dataset['rows'])||count($dataset['rows'])>10000||($totalRows+=count($dataset['rows']))>20000) crm_fail('INVALID_IMPORT','Проверьте размер адресной базы.');
        $id=crm_identifier($dataset['id']??null);if(isset($datasetSeen[$id]))crm_fail('INVALID_IMPORT','Повторяется адресная база.');$datasetSeen[$id]=true;
        crm_text($dataset['title']??'','Название базы',240,true);
        $refs=$dataset['source_ids']??[];if(!is_array($refs)||!array_is_list($refs)||count($refs)>30)crm_fail('INVALID_IMPORT','Источники адресной базы должны быть списком.');foreach($refs as $ref)crm_identifier($ref);if(array_diff($refs,$sourceIds))crm_fail('INVALID_IMPORT','Адресная база ссылается на отсутствующий источник.');
        if(!is_array($dataset['columns']??null)||!array_is_list($dataset['columns'])||count($dataset['columns'])>16)crm_fail('INVALID_IMPORT','Колонки базы должны быть списком.');
        $columns=[];$columnSeen=[];
        foreach($dataset['columns'] as $column){if(!is_array($column)||!is_string($column['key']??null)||!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$column['key'])||isset($columnSeen[$column['key']]))crm_fail('INVALID_IMPORT','Проверьте ключ колонки.');$columnSeen[$column['key']]=true;$columns[]=['key'=>$column['key'],'label'=>crm_text($column['label']??'','Колонка',120,true)];}
        $seen=[];
        foreach($dataset['rows'] as $row){if(!is_array($row))crm_fail('INVALID_IMPORT','Проверьте строку базы.');$rowId=crm_identifier($row['id']??null);if(isset($seen[$rowId]))crm_fail('INVALID_IMPORT','Повторяется строка адресной базы.');$seen[$rowId]=true;foreach($row as $cell){if(is_array($cell)||is_object($cell))crm_fail('INVALID_IMPORT','Ячейки начальной адресной базы должны содержать текст или числа, без вложенных объектов.');if(is_float($cell)&&!is_finite($cell))crm_fail('INVALID_IMPORT','В адресной базе есть число вне допустимого диапазона.');if(is_string($cell))crm_text($cell,'Ячейка базы',10000);}}
        $seed['datasets'][$datasetIndex]=['id'=>$id,'title'=>crm_text($dataset['title'],'Название базы',240,true),'notes'=>crm_text($dataset['notes']??'','Описание базы',10000),'source_ids'=>array_values(array_unique($refs)),'columns'=>$columns,'rows'=>$dataset['rows']];
    }
    $assetSeen=[];
    foreach ($seed['assets'] as $key=>$asset) {
        if (!is_array($asset)) crm_fail('INVALID_IMPORT','Проверьте карточку макета.');
        $id=crm_identifier($asset['id']??null);if(isset($assetSeen[$id]))crm_fail('INVALID_IMPORT','Повторяется макет.');$assetSeen[$id]=true;
        $filename=crm_text($asset['filename']??'','Имя макета',240,true);if(!str_ends_with(strtolower($filename),'.eps'))crm_fail('INVALID_IMPORT','Векторные мастера должны иметь формат EPS.');
        foreach (['file','preview_file'] as $field) if (!empty($asset[$field]) && (!is_string($asset[$field])||!preg_match($field==='file'?'~^assets/[A-Za-z0-9][A-Za-z0-9._-]{0,145}\.eps$~D':'~^previews/[A-Za-z0-9][A-Za-z0-9._-]{0,145}\.jpg$~D',$asset[$field]))) crm_fail('INVALID_IMPORT','Проверьте путь к макету.');
        $sha=crm_text($asset['sha256']??'','Хеш макета',64);if($sha!==''&&!preg_match('/^[a-fA-F0-9]{64}$/D',$sha))crm_fail('INVALID_IMPORT','Проверьте хеш макета.');
        $seed['assets'][$key]=['id'=>$id,'title'=>crm_text($asset['title']??'','Название макета',240,true),'filename'=>$filename,'file'=>$asset['file']??'','preview_file'=>$asset['preview_file']??'',
            'status'=>crm_choice($asset['status']??'approved',['approved','archived'],'Статус макета'),'dimensions'=>crm_text($asset['dimensions']??'','Размеры',200),'notes'=>crm_text($asset['notes']??'','Описание макета',10000),'sent_at'=>crm_text($asset['sent_at']??'','Дата отправки',80),'sha256'=>$sha];
    }
    if(isset($seed['assistant'])){if(!is_array($seed['assistant']))crm_fail('INVALID_IMPORT','Проверьте карточку помощника.');$seed['assistant']=['name'=>crm_text($seed['assistant']['name']??'','Имя помощника',120),'status'=>crm_text($seed['assistant']['status']??'','Статус помощника',240)];}
    if(isset($seed['legacy_visuals'])){if(!is_array($seed['legacy_visuals']))crm_fail('INVALID_IMPORT','Проверьте архив визуалов.');$seed['legacy_visuals']=['status'=>'archived','note'=>crm_text($seed['legacy_visuals']['note']??'','Архив визуалов',10000)];}
    try{$encoded=json_encode($seed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    catch(JsonException){crm_fail('INVALID_IMPORT','Подготовленная база содержит данные, которые нельзя сохранить в JSON.');}
    if(strlen($encoded)>MK_MAX_BYTES)crm_fail('INVALID_IMPORT','Подготовленная база превышает допустимый размер.');
    return $seed;
}

function mk_seed_counts(array $seed): array {
    return ['tasks'=>count($seed['tasks']),'campaigns'=>count($seed['campaigns']),'documents'=>count($seed['documents']),
        'metrics'=>count($seed['metrics']),'sources'=>count($seed['sources']),'datasets'=>count($seed['datasets']),
        'dataset_rows'=>array_sum(array_map(static fn(array $dataset): int=>count($dataset['rows']),$seed['datasets'])),
        'contact_records'=>array_sum(array_map(static fn(array $dataset): int=>in_array($dataset['id'],['shops','schools','kindergartens','offline_providers','online_channels'],true)?count($dataset['rows']):0,$seed['datasets'])),
        'visuals'=>count($seed['assets']),'members'=>count($seed['members'])];
}

function mk_initialize(array $input): array {
    $actor=crm_require_roles(KCRM_ROLES);
    if (($actor['role']??'')!=='owner') crm_fail('FORBIDDEN','Загрузить начальную базу может только владелец.',403);
    if (($input['confirm']??false)!==true) crm_fail('CONFIRM_REQUIRED','Подтвердите загрузку подготовленной базы.');
    $path=crm_storage_path('marketing/import.json');
    if (!is_file($path) || filesize($path)>MK_MAX_BYTES) crm_fail('IMPORT_NOT_READY','Подготовленная база ещё не перенесена в приватное хранилище.',404);
    try { $seed=mk_validate_seed(json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR)); }
    catch (JsonException) { crm_fail('INVALID_IMPORT','Файл подготовленной базы повреждён.'); }
    mk_actor($seed,true,true);
    return mk_mutate($input,static function(array $workspace,array $actor) use($seed): array {
        if (is_file(crm_storage_path('marketing/workspace.json'))) crm_fail('WORKSPACE_EXISTS','База уже создана. Повторный импорт не перезаписывает рабочие данные.',409);
        $seed['revision']=$workspace['revision'];return array_replace(mk_empty_workspace(),$seed);
    },true);
}

function mk_download(): never {
    crm_require_roles(KCRM_ROLES);
    $workspace=mk_read_file(); mk_actor($workspace);
    $id=crm_identifier($_GET['id']??null); $preview=($_GET['preview']??'')==='1';
    foreach ($workspace['assets'] as $asset) if (($asset['id']??null)===$id && ($asset['status']??'')==='approved') {
        $relative=$asset[$preview?'preview_file':'file']??'';
        if (!is_string($relative) || $relative==='') break;
        $path=crm_storage_path('marketing/'.$relative);
        if (!is_file($path)) crm_fail('ASSET_PENDING','Файл ещё не перенесён в защищённое хранилище. Карточка сохранена.',404);
        $type=$preview?'image/jpeg':'application/postscript';
        $name=crm_text($asset['filename']??'maket.eps','Имя файла',240,true);
        if ($preview) $name=pathinfo($name,PATHINFO_FILENAME).'.jpg';
        header('Content-Type: '.$type); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
        header("Content-Disposition: ".($preview?'inline':'attachment')."; filename*=UTF-8''".rawurlencode($name)); header('Content-Length: '.filesize($path));
        readfile($path); exit;
    }
    crm_fail('NOT_FOUND','Утверждённый макет не найден.',404);
}
