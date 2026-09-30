<?php
declare(strict_types=1);
function cms_seo_validate(mixed $input): array {
    if(!is_array($input)||array_diff(array_keys($input),['title','description','canonical','image','noindex']))throw new InvalidArgumentException('Некорректные SEO-настройки.');
    $out=[];foreach(['title'=>600,'description'=>2000,'canonical'=>2048,'image'=>2048] as $key=>$max){$value=$input[$key]??'';if(!is_string($value)||strlen($value)>$max)throw new InvalidArgumentException('SEO-поле слишком длинное или имеет неверный тип.');
        if($key==='canonical'&&$value!==''&&(!str_starts_with($value,'https://')||!cms_url($value)||parse_url($value,PHP_URL_FRAGMENT)!==null||parse_url($value,PHP_URL_USER)!==null))throw new InvalidArgumentException('Canonical должен быть полным HTTPS-адресом без фрагмента и логина.');
        if($key==='image'&&!cms_url($value,true))throw new InvalidArgumentException('Небезопасный адрес SEO-изображения.');$out[$key]=$value;
    }
    if(!is_bool($input['noindex']??false))throw new InvalidArgumentException('Неверное значение индексации.');$out['noindex']=$input['noindex']??false;return $out;
}
function cms_collection_seo_validate(mixed $input,array $fields): array {
    if(!is_array($input)||array_diff(array_keys($input),['titleField','descriptionField','imageField','noindex']))throw new InvalidArgumentException('Некорректные SEO-поля коллекции.');$out=[];
    foreach(['titleField','descriptionField','imageField'] as $key){$value=$input[$key]??'';if(!is_string($value)||($value!==''&&(!isset($fields[$value])||($key==='imageField'?$fields[$value]['type']!=='image':!in_array($fields[$value]['type'],['string','text'],true)))))throw new InvalidArgumentException('Поле для SEO отсутствует или имеет неподходящий тип.');$out[$key]=$value;}
    if(!is_bool($input['noindex']??false))throw new InvalidArgumentException('Неверное значение индексации.');$out['noindex']=$input['noindex']??false;return $out;
}
function cms_public_base(): string {
    $configured=cms_config()['public_url']??'';
    if($configured!==''){if(!str_starts_with($configured,'https://')||!cms_url($configured)||parse_url($configured,PHP_URL_QUERY)!==null||parse_url($configured,PHP_URL_FRAGMENT)!==null||parse_url($configured,PHP_URL_USER)!==null)throw new RuntimeException('Настройте public_url как HTTPS-адрес каталога CMS.');return rtrim($configured,'/').'/';}
    $host=$_SERVER['HTTP_HOST']??'';if(!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/iD',$host))throw new RuntimeException('Не удалось определить адрес CMS. Настройте public_url.');
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';$path=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/cms/site.php'));
    return $scheme.'://'.$host.rtrim($path,'/').'/';
}
function cms_absolute_public_url(string $value,string $base): string {
    if($value===''||preg_match('~^https?://~i',$value))return $value;
    if(str_starts_with($value,'/')){ $parts=parse_url($base);return $parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'').$value;}
    return rtrim($base,'/').'/'.$value;
}
function cms_seo_metadata(string $key,array $page): array {
    $seo=$page['seo']??[];$canonical=$seo['canonical']??'';
    if($canonical==='')$canonical=isset(cms_manifest()['pages'][$key])?rtrim(cms_site()['url'],'/').'/'.$key:cms_public_base().'site.php?'.http_build_query(['site'=>cms_site_key(),'page'=>$key],'','&',PHP_QUERY_RFC3986);
    return ['title'=>($seo['title']??'')?:($page['title']??''),'description'=>$seo['description']??'','canonical'=>$canonical,'image'=>cms_absolute_public_url($seo['image']??'',cms_public_base()),'noindex'=>$seo['noindex']??false];
}
function cms_sitemap(array $pages): string {
    $base=cms_public_base();$urls=[];foreach($pages as $key=>$page){$seo=cms_seo_metadata($key,$page);if($seo['noindex'])continue;$url=$seo['canonical'];if(!str_starts_with($url,$base))continue;$urls[$url]=true;}
    $xml='<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach(array_keys($urls) as $url)$xml.='<url><loc>'.htmlspecialchars($url,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></url>';return $xml.'</urlset>';
}
