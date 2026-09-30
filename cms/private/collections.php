<?php
declare(strict_types=1);

// Collections live in the same versioned document as pages. No separate writes
// can publish records behind the editor's revision check.
function cms_collection_key(mixed $value,int $max=32): bool {
    return is_string($value)&&strlen($value)<=$max&&preg_match('/^[a-z][a-z0-9-]*$/D',$value)===1;
}
function cms_collection_text(mixed $value,int $max,bool $required=false): string {
    if(!is_string($value)||strlen($value)>$max||($required&&trim($value)===''))throw new InvalidArgumentException('Некорректный текст коллекции.');
    return $value;
}
function cms_collection_value(mixed $value,array $field): mixed {
    if($value===null||$value==='')return null;
    switch($field['type']) {
        case 'number':
            if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||abs($value)>1e12)throw new InvalidArgumentException('Укажите конечное число от −10¹² до 10¹².');
            return $value;
        case 'boolean':
            if(!is_bool($value))throw new InvalidArgumentException('Ожидается переключатель да/нет.');
            return $value;
        case 'date':
            if(!is_string($value)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new InvalidArgumentException('Укажите действительную дату ГГГГ-ММ-ДД.');
            return $value;
        case 'reference':
            if(!cms_collection_key($value,40))throw new InvalidArgumentException('Неверная ссылка на запись.');
            return $value;
        default:
            $value=cms_collection_text($value,$field['type']==='text'?12000:2000);
            if(in_array($field['type'],['image','url'],true)&&!cms_url($value,$field['type']==='image'))throw new InvalidArgumentException('Небезопасная ссылка коллекции.');
            return $value;
    }
}
function cms_collections_validate(mixed $input): array {
    if(!is_array($input)||!array_is_list($input)||count($input)>20)throw new InvalidArgumentException('Допускается до 20 коллекций.');
    $out=[];$ids=[];$total=0;
    foreach($input as $collection) {
        if(!is_array($collection)||!cms_collection_key($collection['id']??null)||isset($ids[$collection['id']]))throw new InvalidArgumentException('Неверный или повторяющийся адрес коллекции.');
        $id=$collection['id'];$ids[$id]=true;
        $name=cms_collection_text($collection['name']??null,200,true);
        if(!is_array($collection['fields']??null)||!array_is_list($collection['fields'])||count($collection['fields'])<1||count($collection['fields'])>20)throw new InvalidArgumentException('В коллекции должно быть от 1 до 20 полей.');
        $fields=[];$keys=[];
        foreach($collection['fields'] as $field) {
            if(!is_array($field)||!cms_collection_key($field['key']??null)||isset($keys[$field['key']])||!in_array($field['type']??null,['string','text','number','boolean','date','image','url','reference'],true)||!is_bool($field['required']??null))throw new InvalidArgumentException('Некорректная схема коллекции.');
            $clean=['key'=>$field['key'],'label'=>cms_collection_text($field['label']??null,200,true),'type'=>$field['type'],'required'=>$field['required']];
            if($field['type']==='reference') {if(!cms_collection_key($field['target']??null))throw new InvalidArgumentException('Выберите коллекцию для ссылки.');$clean['target']=$field['target'];}
            $keys[$field['key']]=$clean;$fields[]=$clean;
        }
        $titleField=$collection['titleField']??'';
        if(!is_string($titleField)||!isset($keys[$titleField])||$keys[$titleField]['type']!=='string')throw new InvalidArgumentException('Название записи должно использовать строковое поле.');
        if(!is_array($collection['entries']??null)||!array_is_list($collection['entries'])||count($collection['entries'])>100)throw new InvalidArgumentException('Допускается до 100 записей в коллекции.');
        $entries=[];$entryIds=[];$slugs=[];
        foreach($collection['entries'] as $entry) {
            if(++$total>500)throw new InvalidArgumentException('Допускается до 500 записей на сайт.');
            if(!is_array($entry)||!cms_collection_key($entry['id']??null,40)||isset($entryIds[$entry['id']])||!cms_collection_key($entry['slug']??null,40)||isset($slugs[$entry['slug']])||!in_array($entry['status']??null,['draft','published'],true)||!is_array($entry['values']??null)||array_diff(array_keys($entry['values']),array_keys($keys)))throw new InvalidArgumentException('Некорректная запись или повторяющийся адрес.');
            $entryIds[$entry['id']]=true;$slugs[$entry['slug']]=true;$values=[];
            foreach($fields as $field) {
                $value=cms_collection_value($entry['values'][$field['key']]??null,$field);
                if($entry['status']==='published'&&($field['required']||$field['key']===$titleField)&&($value===null||(is_string($value)&&trim($value)==='')))throw new InvalidArgumentException('Заполните обязательные поля перед включением записи в публикацию.');
                $values[$field['key']]=$value;
            }
            $entries[]=['id'=>$entry['id'],'slug'=>$entry['slug'],'status'=>$entry['status'],'values'=>$values];
        }
        $clean=['id'=>$id,'name'=>$name,'titleField'=>$titleField,'fields'=>$fields,'entries'=>$entries];
        if(array_key_exists('template',$collection))$clean['template']=cms_collection_template($collection['template'],$keys);
        if(array_key_exists('seo',$collection))$clean['seo']=cms_collection_seo_validate($collection['seo'],$keys);
        $out[]=$clean;
    }
    // Resolve only against this document; deleting referenced content cannot
    // silently create a dangling link or disclose another site's records.
    $byId=array_column($out,null,'id');
    foreach($out as $collection)foreach($collection['fields'] as $field)if($field['type']==='reference') {
        $target=$byId[$field['target']]??null;if(!$target)throw new InvalidArgumentException('Коллекция по ссылке не найдена.');
        $entries=array_column($target['entries'],null,'id');
        foreach($collection['entries'] as $entry) {
            $value=$entry['values'][$field['key']];if($value===null)continue;
            if(!isset($entries[$value]))throw new InvalidArgumentException('Запись по ссылке не найдена.');
            if($entry['status']==='published'&&$entries[$value]['status']!=='published')throw new InvalidArgumentException('Публикуемая запись ссылается на черновик.');
        }
    }
    if(strlen(cms_encode($out))>700000)throw new InvalidArgumentException('Коллекции превышают 700 КБ. Сократите содержимое записей.');
    return $out;
}
function cms_collection_template(mixed $input,array $fields): array {
    if(!is_array($input)||!array_is_list($input)||count($input)>20)throw new InvalidArgumentException('В шаблоне записи допускается до 20 секций.');
    $out=[];$ids=[];
    foreach($input as $section){
        if(!is_array($section)||!is_array($section['block']??null)||!is_array($section['bindings']??null))throw new InvalidArgumentException('Некорректная секция шаблона.');
        $block=cms_layout([$section['block']],'__collection_template__')[0];
        if(isset($ids[$block['id']]))throw new InvalidArgumentException('Повторяющаяся секция шаблона.');$ids[$block['id']]=true;$bindings=[];
        foreach($section['bindings'] as $prop=>$field){
            if(!in_array($prop,['title','text','text2','image','alt','label','url'],true)||!is_string($field)||!isset($fields[$field]))throw new InvalidArgumentException('Поле привязки шаблона не найдено.');
            $type=$fields[$field]['type'];
            if(($prop==='image'&&$type!=='image')||($prop==='url'&&$type!=='url'))throw new InvalidArgumentException('Изображение и ссылка требуют поля соответствующего типа.');
            $bindings[$prop]=$field;
        }
        $out[]=['block'=>$block,'bindings'=>$bindings];
    }
    return $out;
}
function cms_collection_page_key(array $collection,array $entry): string {
    // The length prefix makes addresses unambiguous even when IDs contain dashes.
    return 'c-'.strlen($collection['id']).'-'.$collection['id'].'-'.$entry['slug'].'.html';
}
function cms_collection_display(array $collection,array $entry,string $key,array $collections): string {
    $value=$entry['values'][$key]??null;if($value===null)return '';
    $field=array_column($collection['fields'],null,'key')[$key];
    if($field['type']==='reference'){
        $target=array_column($collections,null,'id')[$field['target']]??null;if(!$target)return '';
        $record=array_column($target['entries'],null,'id')[$value]??null;
        return $record&&$record['status']==='published'?(string)($record['values'][$target['titleField']]??''):'';
    }
    return is_bool($value)?($value?'Да':'Нет'):(string)$value;
}
function cms_collection_pages(array $collections): array {
    $pages=[];$bytes=0;
    foreach($collections as $collection){if(empty($collection['template']))continue;
        foreach($collection['entries'] as $entry){if($entry['status']!=='published')continue;$layout=[];
            foreach($collection['template'] as $section){$block=$section['block'];foreach($section['bindings'] as $prop=>$key)$block['props'][$prop]=cms_collection_display($collection,$entry,$key,$collections);$layout[]=$block;}
            $page=['title'=>(string)$entry['values'][$collection['titleField']],'fields'=>[],'blocks'=>[],'layout'=>$layout,'sections'=>[],'collection'=>true];
            if(isset($collection['seo'])){$seo=$collection['seo'];$page['seo']=['title'=>mb_strcut((string)($entry['values'][$seo['titleField']]??''),0,600,'UTF-8'),'description'=>mb_strcut((string)($entry['values'][$seo['descriptionField']]??''),0,2000,'UTF-8'),'image'=>(string)($entry['values'][$seo['imageField']]??''),'canonical'=>'','noindex'=>$seo['noindex']];}
            $size=strlen(cms_encode($page));$bytes+=$size;if($size>1048576||$bytes>4194304)throw new InvalidArgumentException('Страницы коллекций превышают 1 МБ на запись или 4 МБ суммарно. Сократите шаблоны или записи.');
            $pages[cms_collection_page_key($collection,$entry)]=$page;
        }
    }return $pages;
}
