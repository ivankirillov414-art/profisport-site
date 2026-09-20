<?php
declare(strict_types=1);
require __DIR__.'/../private/core.php';
if(getenv('CMS_TEST')!=='1'||!str_ends_with(cms_config()['db_name'],'_test'))throw new RuntimeException('A disposable *_test database and CMS_TEST=1 are required.');
function expect(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
cms_install('test-owner','test-only-password-1234');
expect(cms_document()['published']===null,'Must start unpublished');
try{cms_install('second-owner','test-only-password-4321');throw new RuntimeException('Second owner installation was allowed');}catch(PDOException $e){}
expect((int)cms_db()->query('SELECT COUNT(*) FROM ps_cms_users')->fetchColumn()===1,'Only one owner');
$draft=cms_initial();$draft['pages']['index.html']['fields']['f1']='CMS test content';
$saved=cms_change('save',1,$draft,'test-owner');expect($saved['version']===2,'Version increment');expect(cms_document()['published']===null,'Draft must stay private');
$published=cms_change('publish',2,[],'test-owner');expect($published['published_version']===1,'Publish version');
expect(json_decode(cms_document()['published'],true)['pages']['index.html']['fields']['f1']==='CMS test content','Published content');
$initialId=(int)cms_db()->query("SELECT id FROM ps_cms_history WHERE action='install' LIMIT 1")->fetchColumn();
$restored=cms_change('restore',3,[],'test-owner',$initialId);expect($restored['draft']===cms_initial(),'Restore initial snapshot');expect(json_decode(cms_document()['published'],true)['pages']['index.html']['fields']['f1']==='CMS test content','Restore must not publish');
cms_change('publish',4,[],'test-owner');expect(json_decode(cms_document()['published'],true)===cms_initial(),'Restored publication');
expect((int)cms_db()->query('SELECT COUNT(*) FROM ps_cms_history')->fetchColumn()===5,'Audit snapshots');
// Leave a valid owner and clean published state for HTTP contract testing.
echo "CMS database integration passed\n";
