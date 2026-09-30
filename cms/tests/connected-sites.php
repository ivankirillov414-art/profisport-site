<?php
declare(strict_types=1);
$path=__DIR__.'/../private/config.php';$config=require $path;
if(getenv('CMS_TEST')!=='1'||!str_ends_with($config['db_name'],'_test'))throw new RuntimeException('Disposable database required');
$config['connected_sites']=['kosmosfera'];file_put_contents($path,'<?php return '.var_export($config,true).';');
require __DIR__.'/../private/core.php';
function expectConnected(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$before=cms_db()->query("SELECT draft,published,version FROM ps_cms_documents WHERE site_key='profisport'")->fetch();
$owner=cms_db()->query('SELECT password_hash FROM ps_cms_users WHERE id=1')->fetchColumn();
foreach(['ps_cms_history','ps_cms_documents','ps_cms_sites'] as $table)cms_db()->exec("DELETE FROM $table WHERE site_key='kosmosfera'");
cms_migrate();cms_select_site('kosmosfera');
$original=cms_document();expectConnected($original['published']===null,'Registration must not publish');
expectConnected(count(cms_manifest()['pages']['index.html']['fields'])===79,'Fields missing');
expectConnected(count(cms_templates()['index.html']['sections'])===8,'Visual templates missing');
$draft=json_decode($original['draft'],true);expectConnected(cms_validate($draft)===$draft,'Initial draft invalid');
$textField=array_values(array_filter(cms_manifest()['pages']['index.html']['fields'],fn($f)=>$f['kind']==='text'))[0]['id'];
$draft['pages']['index.html']['fields'][$textField]='Тестовая правка Космопорта';
$draft['pages']['index.html']['layout']=array_map(fn($s)=>['id'=>$s['id'],'type'=>'existing','visible'=>true],cms_templates()['index.html']['sections']);
$saved=cms_change('save',(int)$original['version'],$draft,'test-owner');
cms_register_connector('kosmosfera');expectConnected(json_decode(cms_document()['draft'],true)===$saved['draft'],'Registration overwrote edits');
expectConnected(cms_document()['published']===null,'Autosave published content');
$pub=cms_change('publish',$saved['version'],[],'test-owner');
$out=cms_public(json_decode(cms_document()['published'],true));expectConnected($out['index.html']['fields'][0]['value']==='Тестовая правка Космопорта','Published edit missing');
expectConnected(count($out['index.html']['sections'])===8,'Missing section selectors');
expectConnected($before===cms_db()->query("SELECT draft,published,version FROM ps_cms_documents WHERE site_key='profisport'")->fetch(),'Changed ProfiSport');
expectConnected($owner===cms_db()->query('SELECT password_hash FROM ps_cms_users WHERE id=1')->fetchColumn(),'Changed owner password');
expectConnected(cms_connector_bundle('../kosmosfera')===null&&cms_connector_bundle('unregistered')===null,'Invalid bundle allowed');
echo "Connected site registration, isolation, save/publish and template checks passed\n";
