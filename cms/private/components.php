<?php
declare(strict_types=1);
function cms_components_validate(mixed $input): array {
    if(!is_array($input)||!array_is_list($input)||count($input)>50)throw new InvalidArgumentException('Допускается до 50 общих блоков.');
    $out=[];$seen=[];
    foreach($input as $item){
        if(!is_array($item)||!is_string($item['id']??null)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$item['id'])||isset($seen[$item['id']])||!is_string($item['name']??null)||trim($item['name'])===''||strlen($item['name'])>200||!is_array($item['block']??null))throw new InvalidArgumentException('Некорректный общий блок.');
        $seen[$item['id']]=true;
        // Definitions are ordinary safe blocks. Nested references/cycles are forbidden.
        $block=cms_layout([$item['block']],'__component__')[0];
        $out[]=['id'=>$item['id'],'name'=>$item['name'],'block'=>$block];
    }return $out;
}
function cms_component_find(string $id,array $components): array {
    foreach($components as $component)if($component['id']===$id)return $component;
    throw new InvalidArgumentException('Общий блок не найден в этом сайте.');
}
function cms_component_reference(array $block,array $components): array {
    if(!is_string($block['component']??null)||!is_array($block['overrides']??[]))throw new InvalidArgumentException('Некорректная ссылка на общий блок.');
    $base=cms_component_find($block['component'],$components)['block'];$overrides=$block['overrides']??[];
    $resolved=$base;$resolved['props']=array_replace($base['props'],$overrides);
    $props=cms_layout([$resolved],'__component_override__')[0]['props'];
    if(array_diff(array_keys($overrides),array_keys($props)))throw new InvalidArgumentException('Неизвестные настройки экземпляра.');
    return ['id'=>$block['id'],'type'=>'global','component'=>$block['component'],'overrides'=>array_intersect_key($props,$overrides)];
}
function cms_component_render(array $block,array $components): array {
    if(($block['type']??'')!=='global')return $block;
    $base=cms_component_find($block['component'],$components)['block'];
    return ['id'=>$block['id'],'type'=>$base['type'],'props'=>array_replace($base['props'],$block['overrides']??[])];
}
function cms_components_budget(array $draft): void {
    $total=0;
    foreach($draft['pages'] as $page){$size=0;foreach($page['layout']??[] as $block){
        $bytes=strlen(cms_encode(cms_component_render($block,$draft['components']??[])));$size+=$bytes;$total+=$bytes;
        if($size>1048576||$total>4194304)throw new InvalidArgumentException('Общие блоки делают страницу слишком большой. Уменьшите содержимое или число экземпляров (1 МБ на страницу, 4 МБ на сайт).');
    }}
}
