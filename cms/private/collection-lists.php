<?php
declare(strict_types=1);
function cms_collection_list_validate(array $block,array $collections): array {
    $id=$block['collection']??null;
    if(!is_string($id))throw new InvalidArgumentException('Выберите коллекцию списка.');
    $collection=array_column($collections,null,'id')[$id]??null;
    if(!$collection)throw new InvalidArgumentException('Коллекция списка не найдена.');
    $fields=array_column($collection['fields'],null,'key');$mapping=$block['mapping']??null;
    if(!is_array($mapping)||array_diff(array_keys($mapping),['title','text','image','alt']))throw new InvalidArgumentException('Некорректные поля карточки коллекции.');
    $cleanMap=[];
    foreach($mapping as $prop=>$key){if(!is_string($key)||!isset($fields[$key])||($prop==='image'&&$fields[$key]['type']!=='image'))throw new InvalidArgumentException('Поле карточки отсутствует или имеет неподходящий тип.');$cleanMap[$prop]=$key;}
    if(!isset($cleanMap['title']))$cleanMap['title']=$collection['titleField'];
    $query=$block['query']??[];
    if(!is_array($query)||!is_int($query['pageSize']??null)||$query['pageSize']<1||$query['pageSize']>24||!is_string($query['sortField']??null)||($query['sortField']!==''&&!isset($fields[$query['sortField']]))||!in_array($query['direction']??null,['asc','desc'],true)||!is_string($query['filterField']??null)||($query['filterField']!==''&&!isset($fields[$query['filterField']])))throw new InvalidArgumentException('Проверьте сортировку, фильтр и размер страницы (1–24).');
    if($query['filterField']!==''&&!in_array($fields[$query['filterField']]['type'],['string','number','boolean','date','reference'],true))throw new InvalidArgumentException('Для фильтра используйте короткий текст, число, переключатель, дату или связь.');
    $props=cms_layout([['id'=>$block['id'],'type'=>'cards','props'=>$block['props']??[]]],'__collection_list__')[0]['props'];unset($props['items']);
    return ['id'=>$block['id'],'type'=>'collection','collection'=>$id,'mapping'=>$cleanMap,'query'=>array_intersect_key($query,array_flip(['pageSize','sortField','direction','filterField'])),'props'=>$props];
}
function cms_collection_list_render(array $block,array $collections): array {
    if(($block['type']??'')!=='collection')return $block;
    $c=array_column($collections,null,'id')[$block['collection']];$q=$block['query'];$fields=array_column($c['fields'],null,'key');
    $entries=array_values(array_filter($c['entries'],fn($e)=>$e['status']==='published'));
    if($q['sortField']!==''){
        $key=$q['sortField'];$type=$fields[$key]['type'];
        usort($entries,function($a,$b)use($c,$collections,$q,$key,$type){
            $av=$a['values'][$key]??null;$bv=$b['values'][$key]??null;
            // Empty values always follow populated values, in either direction.
            if($av===null||$bv===null)return $av===$bv?strcmp($a['id'],$b['id']):($av===null?1:-1);
            $result=in_array($type,['number','boolean'],true)?($av<=>$bv):strcmp(cms_collection_display($c,$a,$key,$collections),cms_collection_display($c,$b,$key,$collections));
            return ($q['direction']==='desc'?-$result:$result)?:strcmp($a['id'],$b['id']);
        });
    }
    $items=[];
    foreach($entries as $entry){$item=['title'=>'','text'=>'','image'=>'','alt'=>''];foreach($block['mapping'] as $prop=>$key)$item[$prop]=cms_collection_display($c,$entry,$key,$collections);
        $item['recordPage']=empty($c['template'])?'':cms_collection_page_key($c,$entry);
        $item['filter']=$q['filterField']===''?'':cms_collection_display($c,$entry,$q['filterField'],$collections);$items[]=$item;
    }
    return ['id'=>$block['id'],'type'=>'collection-list','props'=>$block['props'],'items'=>$items,'pageSize'=>$q['pageSize'],'filterLabel'=>$q['filterField']===''?'':$fields[$q['filterField']]['label']];
}
