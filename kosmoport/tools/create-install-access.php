<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$options=getopt('',['output:','metadata:']);
if (!is_string($options['output']??null) || !is_string($options['metadata']??null)) throw new RuntimeException('Required: --output <private local instructions file> --metadata <public hash-only JSON>');
$path=$options['output']; $metadata=$options['metadata'];
if (file_exists($path)||file_exists($metadata)) throw new RuntimeException('Installation files already exist; refusing to replace the setup key.');
if (!is_dir(dirname($path)) || !is_dir(dirname($metadata))) throw new RuntimeException('Destination directories must exist.');
$token=bin2hex(random_bytes(32));
$hash=hash('sha256',$token);
$instructions="ПЕРВОНАЧАЛЬНАЯ НАСТРОЙКА CRM КОСМОПОРТА\n\nОткройте https://ferryffggg.infinityfreeapp.com/kosmoport/setup.php\nВведите этот разовый ключ в поле «Одноразовый ключ настройки»:\n\n".$token."\n\nУкажите имя, электронную почту и новый пароль владельца CRM.\nПосле создания владельца войдите: https://ferryffggg.infinityfreeapp.com/kosmoport/crm/\n\nЭто независимая учётная запись CRM. Ключ действует только до первого создания владельца. Не отправляйте этот файл другим людям.\n";
$file=fopen($path,'xb');
if ($file===false) throw new RuntimeException('Cannot create local setup instructions.');
try { if (fwrite($file,$instructions)!==strlen($instructions)) throw new RuntimeException('Incomplete local setup instructions.'); }
finally { fclose($file); }
@chmod($path,0600);
$json=['setup_key_hash'=>$hash];
if(file_put_contents($metadata,json_encode($json,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX)===false) throw new RuntimeException('Cannot write hash-only metadata.');
echo json_encode(['ok'=>true,'private_local_file'=>$path,'public_hash_file'=>$metadata,'setup_key_sha256'=>$hash,'credential_printed'=>false],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
