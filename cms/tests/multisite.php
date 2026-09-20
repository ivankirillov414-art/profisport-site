<?php
declare(strict_types=1);
require __DIR__.'/../private/core.php';
if(getenv('CMS_TEST')!=='1'||!str_ends_with(cms_config()['db_name'],'_test'))exit('Test database required');
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
cms_migrate();$original=cms_document();
$r=cms_create_site(['key'=>'second-site','name'=>'Second','url'=>'https://second.example/'],'test');cms_select_site('second-site');
$doc=cms_document();$draft=json_decode($doc['draft'],true);$draft['pages']['index.html']['layout']=[['id'=>'new-2','type'=>'text','props'=>['title'=>'Second only']]];
$saved=cms_change('save',(int)$doc['version'],$draft,'test');check(cms_document()['published']===null,'Draft leaked into publication');
cms_change('publish',$saved['version'],[],'test');check(str_contains(cms_document()['published'],'Second only'),'Second site did not publish');
$history=cms_db()->query("SELECT id FROM ps_cms_history WHERE site_key='".cms_config()['site_key']."' LIMIT 1")->fetchColumn();
try{cms_change('restore',$saved['version']+1,[],'test',(int)$history);throw new RuntimeException('Cross-site restore allowed');}catch(InvalidArgumentException $e){}
cms_select_site(cms_config()['site_key']);check(cms_document()===$original,'Original site changed');
check(cms_manifest()['site']==='profisport','Connector manifest changed');
echo "CMS multisite isolation passed\n";
