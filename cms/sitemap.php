<?php
declare(strict_types=1);
require __DIR__.'/private/core.php';
try {
    cms_select_site((string)($_GET['site']??''));$row=cms_document();$data=json_decode($row['published']??'{}',true,512,JSON_THROW_ON_ERROR);
    $xml=cms_sitemap(cms_public($data??['pages'=>[]]));header('Content-Type: application/xml; charset=utf-8');header('Cache-Control: no-cache');echo $xml;
}catch(InvalidArgumentException $e){http_response_code(404);echo 'Сайт не найден.';}
catch(Throwable $e){error_log('CMS sitemap: '.$e->getMessage());http_response_code(503);echo 'Карта сайта временно недоступна.';}
