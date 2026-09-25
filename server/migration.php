<?php
declare(strict_types=1);

function migration_ensure_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS site_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
    due_at DATETIME NOT NULL,
    phase VARCHAR(40) NOT NULL DEFAULT 'db_schema',
    cursor_json LONGTEXT NULL,
    config_cipher LONGTEXT NULL,
    progress_json LONGTEXT NULL,
    error_text TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_migration_due(status,due_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function migration_owner(PDO $pdo): array {
  $a=require_admin();
  if(($a['role']??'')!=='owner')json_response(['ok'=>false,'error'=>'owner_required'],403);
  return $a;
}
function migration_verify_password(PDO $pdo,int $adminId,string $password): void {
  auth_rate_check($pdo,'migration_confirm',(string)$adminId,5,900);
  $s=$pdo->prepare('SELECT password_hash FROM admin_users WHERE id=? AND is_active=1 LIMIT 1');$s->execute([$adminId]);$hash=(string)($s->fetchColumn()?:'');
  if($hash===''||!password_verify($password,$hash)){auth_rate_failure($pdo,'migration_confirm',(string)$adminId,5,900,900);json_response(['ok'=>false,'error'=>'current_password_invalid'],403);}
  auth_rate_clear($pdo,'migration_confirm',(string)$adminId);
}
function migration_secret_key(): string {
  global $config;
  $seed=(string)($config['migration_key']??'');
  if($seed==='')$seed=hash('sha256','profisport-migration-v1|'.(string)($config['db_host']??'').'|'.(string)($config['db_name']??'').'|'.(string)($config['db_user']??'').'|'.(string)($config['db_pass']??''));
  return hash('sha256',$seed,true);
}
function migration_encrypt(array $value): string {
  if(!function_exists('openssl_encrypt'))throw new RuntimeException('openssl_unavailable');
  $iv=random_bytes(12);$tag='';$plain=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  $cipher=openssl_encrypt($plain,'aes-256-gcm',migration_secret_key(),OPENSSL_RAW_DATA,$iv,$tag,'profisport-migration');
  if($cipher===false)throw new RuntimeException('migration_encrypt_failed');
  return base64_encode($iv.$tag.$cipher);
}
function migration_decrypt(string $blob): array {
  $raw=base64_decode($blob,true);if($raw===false||strlen($raw)<29)throw new RuntimeException('migration_config_invalid');
  $iv=substr($raw,0,12);$tag=substr($raw,12,16);$cipher=substr($raw,28);
  $plain=openssl_decrypt($cipher,'aes-256-gcm',migration_secret_key(),OPENSSL_RAW_DATA,$iv,$tag,'profisport-migration');
  if($plain===false)throw new RuntimeException('migration_config_decrypt_failed');
  $value=json_decode($plain,true,64,JSON_THROW_ON_ERROR);if(!is_array($value))throw new RuntimeException('migration_config_invalid');return $value;
}
function migration_host(string $value): string {
  $value=trim($value);if($value===''||strlen($value)>255||preg_match('/[\s\/@]/',$value))throw new InvalidArgumentException('invalid_host');return $value;
}
function migration_root(string $value): string {
  $value='/'.trim(str_replace('\\','/',$value),'/');if(str_contains($value,'..'))throw new InvalidArgumentException('invalid_target_path');return $value==='/'?'/':$value.'/';
}
function migration_config(array $in): array {
  $targetUrl=trim((string)($in['target_url']??''));
  if($targetUrl!==''&&(!filter_var($targetUrl,FILTER_VALIDATE_URL)||strtolower((string)parse_url($targetUrl,PHP_URL_SCHEME))!=='https'))throw new InvalidArgumentException('target_url_https_required');
  return [
    'target_url'=>rtrim($targetUrl,'/'),
    'ftp_host'=>migration_host((string)($in['ftp_host']??'')),
    'ftp_port'=>max(1,min(65535,(int)($in['ftp_port']??21))),
    'ftp_user'=>trim((string)($in['ftp_user']??'')),
    'ftp_pass'=>(string)($in['ftp_pass']??''),
    'ftp_root'=>migration_root((string)($in['ftp_root']??'/htdocs')),
    'db_host'=>migration_host((string)($in['db_host']??'')),
    'db_port'=>max(1,min(65535,(int)($in['db_port']??3306))),
    'db_name'=>trim((string)($in['db_name']??'')),
    'db_user'=>trim((string)($in['db_user']??'')),
    'db_pass'=>(string)($in['db_pass']??'')
  ];
}
function migration_target_db(array $cfg): PDO {
  if($cfg['db_name']===''||$cfg['db_user']===''||$cfg['db_pass']==='')throw new InvalidArgumentException('target_db_credentials_required');
  $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['db_host'],$cfg['db_port'],$cfg['db_name']);
  return new PDO($dsn,$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function migration_target_ftp(array $cfg) {
  if(!function_exists('ftp_ssl_connect'))throw new RuntimeException('ftps_unavailable_on_current_host');
  $ftp=@ftp_ssl_connect($cfg['ftp_host'],$cfg['ftp_port'],15);if(!$ftp)throw new RuntimeException('target_ftps_connect_failed');
  if(!@ftp_login($ftp,$cfg['ftp_user'],$cfg['ftp_pass'])){ftp_close($ftp);throw new RuntimeException('target_ftps_login_failed');}
  ftp_pasv($ftp,true);return $ftp;
}
function migration_ftp_mkdirs($ftp,string $path): void {
  $parts=array_values(array_filter(explode('/',trim($path,'/'))));$cwd='/';
  foreach($parts as $part){$cwd.=($cwd==='/'?'':'/').$part;if(@ftp_chdir($ftp,$cwd)){ftp_chdir($ftp,'/');continue;}if(!@ftp_mkdir($ftp,$cwd)&&!@ftp_chdir($ftp,$cwd))throw new RuntimeException('target_directory_create_failed');ftp_chdir($ftp,'/');}
}
function migration_preflight(array $cfg,bool $requireEmpty=true): array {
  $target=migration_target_db($cfg);$target->query('SELECT 1')->fetchColumn();
  $tables=$target->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
  if($requireEmpty&&$tables)throw new RuntimeException('target_database_not_empty');
  $ftp=migration_target_ftp($cfg);migration_ftp_mkdirs($ftp,$cfg['ftp_root']);
  $tmp=$cfg['ftp_root'].'.profisport-preflight-'.bin2hex(random_bytes(5)).'.txt';$mem=fopen('php://temp','r+');fwrite($mem,'ok');rewind($mem);
  $ok=@ftp_fput($ftp,$tmp,$mem,FTP_BINARY);fclose($mem);if(!$ok){ftp_close($ftp);throw new RuntimeException('target_ftps_write_failed');}@ftp_delete($ftp,$tmp);ftp_close($ftp);
  return ['database'=>true,'ftps'=>true,'empty_database'=>!$tables];
}
function migration_source_tables(PDO $pdo): array {
  $rows=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);$out=[];
  foreach($rows as $r){$name=(string)$r[0];if($name!=='site_migrations')$out[]=$name;}sort($out,SORT_STRING);return $out;
}
function migration_quote_ident(string $name): string {$q=chr(96);return $q.str_replace($q,$q.$q,$name).$q;}
function migration_runtime_files(): array {
  $root=realpath(__DIR__.'/..');if(!$root)throw new RuntimeException('app_root_missing');$out=[];$skip=['.git','.github','tests','docs'];
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
  foreach($it as $f){if(!$f->isFile())continue;$rel=str_replace(DIRECTORY_SEPARATOR,'/',substr($f->getPathname(),strlen($root)+1));$first=explode('/',$rel,2)[0];if(in_array($first,$skip,true)||$rel==='server/config.php')continue;$out[]=$rel;}
  sort($out,SORT_STRING);return [$root,$out];
}
function migration_upload_file($ftp,string $root,string $localRoot,string $rel): void {
  $remote=$root.$rel;$dir=str_replace('\\','/',dirname($remote));migration_ftp_mkdirs($ftp,$dir);
  $fh=fopen($localRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel),'rb');if(!$fh)throw new RuntimeException('source_file_read_failed:'.$rel);
  $ok=@ftp_fput($ftp,$remote,$fh,FTP_BINARY);fclose($fh);if(!$ok)throw new RuntimeException('target_file_upload_failed:'.$rel);
}
function migration_target_config(array $cfg): string {
  global $config;
  $importToken=(string)($config['import_token']??'');$migrationKey=hash('sha256','profisport-migration-v1|'.$cfg['db_host'].'|'.$cfg['db_name'].'|'.$cfg['db_user'].'|'.$cfg['db_pass']);$tickToken=hash('sha256','profisport-migration-tick-v1|'.$cfg['db_host'].'|'.$cfg['db_name'].'|'.$cfg['db_user'].'|'.$cfg['db_pass']);
  $vals=['timezone'=>'Asia/Yekaterinburg','db_timezone'=>'+05:00','db_host'=>$cfg['db_host'],'db_port'=>$cfg['db_port'],'db_name'=>$cfg['db_name'],'db_user'=>$cfg['db_user'],'db_pass'=>$cfg['db_pass'],'import_token'=>$importToken,'migration_key'=>$migrationKey,'migration_tick_token'=>$tickToken,'recovery_bootstrap_hash'=>''];
  return "<?php\nreturn ".var_export($vals,true).";\n";
}
function migration_cursor(array $job): array {$x=json_decode((string)($job['cursor_json']??''),true);return is_array($x)?$x:[];}
function migration_table_digest(PDO $db,string $table): array {
  $cols=$db->query('SHOW COLUMNS FROM '.migration_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);if(!$cols)return[0,hash('sha256','')];
  $order=migration_quote_ident((string)$cols[0]['Field']);$stmt=$db->query('SELECT * FROM '.migration_quote_ident($table).' ORDER BY '.$order);$ctx=hash_init('sha256');$count=0;
  while($row=$stmt->fetch(PDO::FETCH_ASSOC)){hash_update($ctx,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)."\n");$count++;}
  return[$count,hash_final($ctx)];
}
function migration_database_digest(PDO $db,array $tables): string {
  $ctx=hash_init('sha256');foreach($tables as $table){[$count,$digest]=migration_table_digest($db,$table);hash_update($ctx,$table.'|'.$count.'|'.$digest."\n");}return hash_final($ctx);
}
function migration_save(PDO $pdo,int $id,string $phase,array $cursor,array $progress=[],?string $status=null,?string $error=null): void {
  $sql='UPDATE site_migrations SET phase=?,cursor_json=?,progress_json=?,error_text=?'.($status!==null?',status=?':'').' WHERE id=?';
  $args=[$phase,json_encode($cursor,JSON_UNESCAPED_UNICODE),json_encode($progress,JSON_UNESCAPED_UNICODE),$error];if($status!==null)$args[]=$status;$args[]=$id;$pdo->prepare($sql)->execute($args);
}
function migration_tick_once(PDO $pdo): array {
  migration_ensure_schema($pdo);$lock=(int)$pdo->query("SELECT GET_LOCK('profisport_site_migration',0)")->fetchColumn();if($lock!==1)return ['ran'=>false,'reason'=>'busy'];
  try{
    $s=$pdo->query("SELECT * FROM site_migrations WHERE status IN ('scheduled','running') AND due_at<=NOW() ORDER BY id DESC LIMIT 1");$job=$s->fetch();if(!$job)return ['ran'=>false,'reason'=>'none'];
    $id=(int)$job['id'];$cfg=migration_decrypt((string)$job['config_cipher']);$phase=(string)$job['phase'];$cursor=migration_cursor($job);$progress=json_decode((string)($job['progress_json']??''),true);if(!is_array($progress))$progress=[];
    if($job['status']==='scheduled')$pdo->prepare("UPDATE site_migrations SET status='running' WHERE id=?")->execute([$id]);
    $sourceTables=migration_source_tables($pdo);$target=migration_target_db($cfg);$target->exec('SET FOREIGN_KEY_CHECKS=0');$deadline=microtime(true)+45;
    if($phase==='db_schema'){
      $i=(int)($cursor['table']??0);
      for(;$i<count($sourceTables)&&microtime(true)<$deadline;$i++){
        $table=$sourceTables[$i];$q=$pdo->query('SHOW CREATE TABLE '.migration_quote_ident($table))->fetch(PDO::FETCH_NUM);if(!$q||empty($q[1]))throw new RuntimeException('source_schema_failed:'.$table);
        $create=preg_replace('/^CREATE TABLE /i','CREATE TABLE IF NOT EXISTS ',(string)$q[1],1);$target->exec((string)$create);$progress['schema_tables']=$i+1;
      }
      if($i>=count($sourceTables)){$phase='db_data';$cursor=['table'=>0,'offset'=>0];}else$cursor=['table'=>$i];
      migration_save($pdo,$id,$phase,$cursor,$progress);return ['ran'=>true,'phase'=>$phase];
    }
    if($phase==='db_data'){
      $ti=(int)($cursor['table']??0);$offset=(int)($cursor['offset']??0);$limit=300;
      while($ti<count($sourceTables)&&microtime(true)<$deadline){
        $table=$sourceTables[$ti];$rows=$pdo->query('SELECT * FROM '.migration_quote_ident($table).' LIMIT '.$limit.' OFFSET '.$offset)->fetchAll(PDO::FETCH_ASSOC);
        if($rows){$cols=array_keys($rows[0]);$sql='REPLACE INTO '.migration_quote_ident($table).' ('.implode(',',array_map('migration_quote_ident',$cols)).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')';$ins=$target->prepare($sql);foreach($rows as $row)$ins->execute(array_values($row));$offset+=count($rows);$progress['rows_copied']=(int)($progress['rows_copied']??0)+count($rows);}
        if(count($rows)<$limit){$ti++;$offset=0;$progress['data_tables']=$ti;}else break;
      }
      if($ti>=count($sourceTables)){$phase='files';$cursor=['file'=>0];}else$cursor=['table'=>$ti,'offset'=>$offset];
      migration_save($pdo,$id,$phase,$cursor,$progress);return ['ran'=>true,'phase'=>$phase];
    }
    if($phase==='files'){
      [$localRoot,$files]=migration_runtime_files();$i=(int)($cursor['file']??0);$ftp=migration_target_ftp($cfg);
      try{for(;$i<count($files)&&microtime(true)<$deadline;$i++){migration_upload_file($ftp,$cfg['ftp_root'],$localRoot,$files[$i]);$progress['files_copied']=$i+1;}}finally{ftp_close($ftp);}
      if($i>=count($files)){$phase='config';$cursor=[];}else$cursor=['file'=>$i];
      migration_save($pdo,$id,$phase,$cursor,$progress);return ['ran'=>true,'phase'=>$phase];
    }
    if($phase==='config'){
      $ftp=migration_target_ftp($cfg);try{migration_ftp_mkdirs($ftp,$cfg['ftp_root'].'server');$tmp=fopen('php://temp','r+');fwrite($tmp,migration_target_config($cfg));rewind($tmp);if(!@ftp_fput($ftp,$cfg['ftp_root'].'server/config.php',$tmp,FTP_BINARY))throw new RuntimeException('target_config_upload_failed');fclose($tmp);}finally{ftp_close($ftp);}
      $phase='verify';migration_save($pdo,$id,$phase,[],$progress);return ['ran'=>true,'phase'=>$phase];
    }
    if($phase==='verify'){
      $sourceDigest=migration_database_digest($pdo,$sourceTables);$targetDigest=migration_database_digest($target,$sourceTables);
      if(!hash_equals($sourceDigest,$targetDigest))throw new RuntimeException('target_database_digest_mismatch');
      $sourceDigestAfter=migration_database_digest($pdo,$sourceTables);if(!hash_equals($sourceDigest,$sourceDigestAfter))throw new RuntimeException('source_changed_during_verify');
      $progress['database_verified']=true;$progress['database_digest']=$sourceDigest;$progress['verified_at']=date(DATE_ATOM);
      if($cfg['target_url']!==''){$ctx=stream_context_create(['http'=>['timeout'=>12,'ignore_errors'=>true,'header'=>"User-Agent: ProfiSport-Migration\r\n"]]);$raw=@file_get_contents($cfg['target_url'].'/api/health.php',false,$ctx);$j=is_string($raw)?json_decode($raw,true):null;if(!is_array($j)||empty($j['ok']))throw new RuntimeException('target_http_health_failed');$progress['http_verified']=true;}
      $pdo->prepare("UPDATE site_migrations SET status='completed',phase='completed',cursor_json='{}',progress_json=?,config_cipher=NULL,error_text=NULL,completed_at=NOW() WHERE id=?")->execute([json_encode($progress,JSON_UNESCAPED_UNICODE),$id]);
      audit($pdo,'site_migration_completed','site_migration',(string)$id,['target_url'=>$cfg['target_url']]);return ['ran'=>true,'phase'=>'completed'];
    }
    throw new RuntimeException('unknown_migration_phase');
  }catch(Throwable $e){
    if(isset($job['id'])){$pdo->prepare("UPDATE site_migrations SET status='failed',error_text=?,updated_at=NOW() WHERE id=?")->execute([mb_substr($e->getMessage(),0,1000),(int)$job['id']]);audit($pdo,'site_migration_failed','site_migration',(string)$job['id'],['error'=>mb_substr($e->getMessage(),0,200)]);}
    error_log($e->__toString());return ['ran'=>true,'phase'=>'failed'];
  }finally{$pdo->query("SELECT RELEASE_LOCK('profisport_site_migration')");}
}
