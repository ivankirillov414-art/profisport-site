<?php
declare(strict_types=1);
require __DIR__.'/../private/core.php';
if(getenv('CMS_TEST')!=='1'||!str_ends_with(cms_config()['db_name'],'_test'))throw new RuntimeException('Disposable *_test database required.');
$owner=cms_db()->query('SELECT id,username,active,auth_version FROM ps_cms_users WHERE id=1')->fetch();
if(!$owner||(int)$owner['active']!==1||$owner['username']!=='test-owner')throw new RuntimeException('Owner migration/protection failed.');
$logs=cms_db()->query('SELECT action,details FROM ps_cms_access_log')->fetchAll();
if(count($logs)<6)throw new RuntimeException('Access changes are not audited.');
foreach($logs as $log){
    $data=json_decode($log['details'],true,512,JSON_THROW_ON_ERROR);
    if(array_diff(array_keys($data),['active','memberships','password_changed']))throw new RuntimeException('Unexpected audit fields.');
    if(str_contains($log['details'],'test-password')||str_contains($log['details'],'$2y$'))throw new RuntimeException('Credentials leaked into audit.');
}
foreach(['','invented','viewer','editor','publisher','owner'] as $role){
    $p=cms_permissions($role);
    if($p['manage']!==($role==='owner'))throw new RuntimeException('Owner-only permission failed.');
    if($p['publish']!==in_array($role,['owner','publisher'],true))throw new RuntimeException('Publish permission failed.');
}
echo "CMS access migration, owner invariant and credential-free audit passed\n";
