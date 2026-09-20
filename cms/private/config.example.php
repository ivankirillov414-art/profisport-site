<?php
// Copy to config.php outside version control. Give the DB user access only to CMS data.
return [
 'db_host'=>'localhost', 'db_name'=>'content_cms', 'db_user'=>'content_cms', 'db_pass'=>'CHANGE_ME',
 'site_key'=>'first-site', 'site_name'=>'Мой сайт', 'site_url'=>'https://example.com/',
 'media_url'=>'https://cms.example.com/media/',
 // Generate with: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
 // Required ONLY for first owner creation. Remove after installation.
 'install_token'=>'',
];
