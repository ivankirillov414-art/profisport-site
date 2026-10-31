<?php
declare(strict_types=1);

// Hosting-only adapter. No password or persistent secret is present in source.
// During isolated deployment this file becomes config/config.php.
$cmsConfigPath=dirname(__DIR__,2).'/cms/private/config.php';
if (!is_file($cmsConfigPath)) throw new RuntimeException('Existing private CMS configuration was not found.');
$cms=require $cmsConfigPath;
foreach (['db_host','db_name','db_user','db_pass'] as $key) if (!is_string($cms[$key]??null) || $cms[$key]==='') throw new RuntimeException('Existing CMS database configuration is incomplete.');
return [
    'environment'=>'production','app_name'=>'Космопорт CRM','base_path'=>'/kosmoport',
    'timezone'=>'Asia/Yekaterinburg','storage_path'=>dirname(__DIR__).'/storage',
    'app_key'=>'GENERATED_ON_FIRST_DEPLOYMENT',
    'setup_key_hash'=>'SET_TO_INDEPENDENT_SETUP_TOKEN_SHA256',
    'database'=>['driver'=>'mysql','host'=>$cms['db_host'],'port'=>(int)($cms['db_port']??3306),'name'=>$cms['db_name'],'username'=>$cms['db_user'],'password'=>$cms['db_pass'],'charset'=>'utf8mb4'],
    'session'=>['name'=>'KCRMSESSID','secure'=>'auto','same_site'=>'Lax','idle_seconds'=>1800,'absolute_seconds'=>43200],
    'storage'=>['allow_webroot_storage'=>false,'http_denial_verified'=>false],
    // Set true only after a real browser request proves private HTTP paths denied.
    'documents'=>['allow_webroot_storage'=>false,'max_upload_bytes'=>10485760],
];
