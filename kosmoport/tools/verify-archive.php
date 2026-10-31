<?php
declare(strict_types=1);
// Read-only freshness check of the existing release; never builds/extracts a ZIP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$archive=$root.'/deploy/artifacts/kosmoport-crm-source-20261031.zip';
$files=[$root.'/app/bootstrap.php',...glob($root.'/app/core/*.php'),$root.'/public/api/index.php',$root.'/public/setup.php',$root.'/config/config.example.php',...glob($root.'/migrations/001*.sql'),...glob($root.'/migrations/010*.sql'),...glob($root.'/bin/core-*.php')];
$zip=new ZipArchive();if ($zip->open($archive)!==true) throw new RuntimeException('Source archive unavailable');
$names=[];for($i=0;$i<$zip->numFiles;$i++)$names[]=$zip->getNameIndex($i);
$missing=[];$changed=[];$hashes=[];
foreach($files as $file){$name=str_replace('\\','/',substr($file,strlen($root)+1));$hashes[$name]=hash_file('sha256',$file);$matches=array_values(array_filter($names,static fn($n)=>$n===$name||str_ends_with($n,'/'.$name)));if(count($matches)!==1)$missing[]=$name;elseif(hash('sha256',$zip->getFromName($matches[0]))!==$hashes[$name])$changed[]=$name;}
$zip->close();$result=['checked_at'=>gmdate('c'),'ok'=>!$missing&&!$changed,'archive'=>'deploy/artifacts/'.basename($archive),'archive_sha256'=>hash_file('sha256',$archive),'owned_sources'=>count($files),'missing'=>$missing,'changed'=>$changed,'source_sha256'=>$hashes];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($result['ok']?0:1);
