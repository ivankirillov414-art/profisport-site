<?php
declare(strict_types=1);
$source=realpath($argv[1] ?? '');$target=$argv[2] ?? '';$allowed=realpath(__DIR__.'/.runtime/runs');$parent=realpath(dirname($target));
if(!$source||!$parent||!$allowed||!str_starts_with($source,$allowed.DIRECTORY_SEPARATOR)||!str_starts_with($parent,$allowed.DIRECTORY_SEPARATOR)||$source===$target)throw new RuntimeException('Not a private disposable QA archive');
if(!copy($source,$target))throw new RuntimeException('Fixture copy failed');
$zip=new ZipArchive();if($zip->open($target)!==true)throw new RuntimeException('Fixture ZIP open failed');
for($i=0;$i<$zip->numFiles;$i++){$name=$zip->getNameIndex($i);if(str_starts_with($name,'database/')&&str_ends_with($name,'.jsonl')){$zip->addFromString($name,$zip->getFromName($name)."tampered-fixture\n");$zip->close();echo '{"tampered":true}';exit;}}
$zip->close();throw new RuntimeException('No database payload in backup');
