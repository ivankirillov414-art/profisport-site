<?php
declare(strict_types=1);
$fixtureFile=$argv[1] ?? '';
$fixture=json_decode(file_get_contents($fixtureFile),true,512,JSON_THROW_ON_ERROR);
if (($fixture['marker'] ?? '')!=='KCRM_QA_DISPOSABLE_V1' || ($fixture['environment'] ?? '')!=='test'
    || !preg_match('/^kcrmqa_[a-z0-9_]+$/D',$fixture['database']['name'] ?? '')
    || !in_array(parse_url($fixture['base_url'] ?? '',PHP_URL_HOST),['127.0.0.1','localhost','::1'],true)) throw new RuntimeException('Not a disposable local fixture');
$configFile=$fixture['config_file'];
$configuration=require $configFile;
if (($configuration['environment'] ?? '')!=='test' || ($configuration['database']['host'] ?? '')!=='127.0.0.1'
    || ($configuration['database']['name'] ?? '')!==$fixture['database']['name']) throw new RuntimeException('Refusing a non-local or mismatched configuration');
putenv('KCRM_CONFIG='.$configFile);
require_once dirname(__DIR__,2).'/app/bootstrap.php';
if(crm_config('environment')!=='test' || crm_db()->query('SELECT DATABASE()')->fetchColumn()!==$fixture['database']['name']) throw new RuntimeException('Fixture database identity mismatch');
$action=$argv[2] ?? '';
if($action==='users'){
 $users=[];
 foreach(['admin','manager','cashier','editor','document_manager','reader'] as $role){
  if(!in_array($role,KCRM_ROLES,true))throw new RuntimeException('Core role contract changed: '.$role);
  $id=crm_uuid();$email=$role.'.'.$fixture['id'].'@qa.invalid';$now=crm_now();
  crm_query('INSERT INTO kcrm_users(id,name,email,password_hash,role,active,session_version,revision,created_at,updated_at) VALUES(?,?,?,?,?,1,1,1,?,?)',[$id,'QA '.$role,$email,password_hash($fixture['owner']['password'],PASSWORD_BCRYPT,['cost'=>12]),$role,$now,$now]);
  $users[$role]=['id'=>$id,'email'=>$email,'role'=>$role];
 }
 echo json_encode(['users'=>$users],JSON_THROW_ON_ERROR);
}elseif($action==='migrate'){
 echo json_encode(crm_run_migrations(),JSON_THROW_ON_ERROR);
}elseif($action==='snapshot'){
 $tables=crm_db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);$result=[];
 foreach($tables as $table){if(!preg_match('/^kcrm_[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');$rows=crm_db()->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);$encoded=array_map(static function($row){ksort($row);return json_encode($row,JSON_THROW_ON_ERROR);},$rows);sort($encoded);$result[$table]=['rows'=>count($rows),'sha256'=>hash('sha256',implode("\n",$encoded))];}
 echo json_encode(['tables'=>$result],JSON_THROW_ON_ERROR);
}elseif($action==='query'){
 // Fixed read-only assertions; arbitrary SQL is deliberately not accepted.
 echo json_encode(['users'=>(int)crm_query('SELECT COUNT(*) FROM kcrm_users')->fetchColumn(),'partners'=>(int)crm_query('SELECT COUNT(*) FROM kcrm_partners')->fetchColumn(),'tasks'=>(int)crm_query('SELECT COUNT(*) FROM kcrm_tasks')->fetchColumn(),'audit'=>(int)crm_query('SELECT COUNT(*) FROM kcrm_audit')->fetchColumn()],JSON_THROW_ON_ERROR);
}else throw new RuntimeException('Unknown fixture action');
