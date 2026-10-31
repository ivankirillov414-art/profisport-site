<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','1');
header('Cache-Control: no-store, private');
try {
    require_once dirname(__DIR__).'/app/bootstrap.php';
    if (crm_config('environment')==='production' && !crm_is_https()) crm_fail('HTTPS_REQUIRED','Откройте эту страницу через HTTPS.',403);
    crm_require_roles(['owner']);
    if ((crm_current_user()['role']??null)!=='owner') crm_fail('OWNER_REQUIRED','Резервные копии доступны владельцу CRM.',403);
    $target=dirname(__DIR__).'/config/restore-target.php';
    if (is_file($target)) define('KCRM_BACKUP_TARGET_CONFIG',$target);
    require dirname(__DIR__).'/tools/backup-admin.php';
} catch (Throwable $error) {
    if (headers_sent()) { error_log('Kosmoport maintenance interrupted ['.get_class($error).']'); exit; }
    $known=$error instanceof CrmHttpException;
    http_response_code($known?$error->status:500);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $message=$known?$error->getMessage():'Инструмент временно недоступен. Проверьте настройки и журнал сервера.';
    if (!$known) error_log('Kosmoport maintenance ['.get_class($error).']');
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Резервные копии CRM</title><style>body{font:17px/1.6 system-ui;max-width:640px;margin:60px auto;padding:20px;color:#172435}a{color:#174e82}</style><h1>Резервные копии CRM</h1><p>'.htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p><p><a href="crm/">Открыть CRM</a></p></html>';
}
