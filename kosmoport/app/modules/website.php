<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/website/content.php';

return [
    'GET website/content'=>static function(): void {
        header('Cache-Control: no-cache');
        crm_json(['ok'=>true,'data'=>kp_read_published()]);
    },
    'GET website/draft'=>static function(): void {
        crm_require_roles(['editor']);
        $row=kp_website_row();
        crm_json(['ok'=>true,'data'=>['content'=>$row?json_decode($row['draft'],true,64,JSON_THROW_ON_ERROR):kp_default_content(),'revision'=>(int)($row['revision']??0),'published_revision'=>(int)($row['published_revision']??0)]]);
    },
    'POST website/save'=>static function(): void {
        try { $result=kp_website_change(crm_input(),false); }
        catch (InvalidArgumentException $e) { crm_json(['ok'=>false,'error'=>['code'=>'website_validation','message'=>$e->getMessage()]],422); }
        crm_json(['ok'=>true,'data'=>$result]);
    },
    'POST website/publish'=>static function(): void {
        try { $result=kp_website_change(crm_input(),true); }
        catch (InvalidArgumentException $e) { crm_json(['ok'=>false,'error'=>['code'=>'website_validation','message'=>$e->getMessage()]],422); }
        crm_json(['ok'=>true,'data'=>$result]);
    },
];
