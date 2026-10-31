<?php
declare(strict_types=1);
/* Require from a temporary same-origin loader AFTER the trusted app bootstrap.
 * The default source ZIP does not expose this file as a public route. */
require_once __DIR__ . '/backup-lib.php';
if (PHP_SAPI === 'cli' || !function_exists('crm_require_roles') || !function_exists('crm_config')) {
    http_response_code(404);
    exit;
}
crm_require_roles(['owner']);
$transferUser = crm_current_user();
// Core admin override is useful elsewhere; complete business exports are owner-only.
if (($transferUser['role'] ?? null) !== 'owner') {
    http_response_code(403);
    exit('Доступно владельцу CRM.');
}
ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if (session_status() !== PHP_SESSION_ACTIVE) {
    http_response_code(503);
    exit('Сессия CRM недоступна.');
}
$_SESSION['kcrm_transfer_csrf'] ??= bin2hex(random_bytes(32));
$transferCsrf = $_SESSION['kcrm_transfer_csrf'];
$transferMessage = '';
$transferTargetPath = defined('KCRM_BACKUP_TARGET_CONFIG') ? constant('KCRM_BACKUP_TARGET_CONFIG') : null;
$transferEscape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($transferCsrf, $_POST['csrf'])) {
            http_response_code(403);
            kcrm_transfer_fail('Сессия формы устарела. Откройте страницу заново.');
        }
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['backup', 'verify', 'restore', 'prepare'], true)) {
            http_response_code(422);
            kcrm_transfer_fail('Неизвестное действие.');
        }
        // A per-owner session lock serializes the operation; keep the session open.
        // Never bootstrap shell tools or accept database credentials from the browser.
        if ($action === 'backup') {
            if (($_POST['maintenance_confirmed'] ?? '') !== 'yes') {
                kcrm_transfer_fail('Подтвердите остановку изменений документов на время копирования.');
            }
            $job = kcrm_transfer_temp_dir();
            try {
                $archive = $job . DIRECTORY_SEPARATOR . 'business-backup.zip';
                $manifest = kcrm_backup_create(crm_db(), crm_storage_path(), $archive, [
                    'application_version' => defined('KCRM_VERSION') ? KCRM_VERSION : (string)(crm_config('version') ?? 'unknown'),
                ]);
                crm_audit('backup.created', 'backup', null, ['tables' => count($manifest['tables']), 'schema_version' => $manifest['schema_version']]);
                if (headers_sent()) {
                    kcrm_transfer_fail('Нельзя начать скачивание: заголовки уже отправлены.');
                }
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="kosmoport-business-' . gmdate('Ymd-His') . '.zip"');
                header('Content-Length: ' . filesize($archive));
                header('X-Backup-SHA256: ' . hash_file('sha256', $archive));
                $stream = fopen($archive, 'rb');
                if ($stream === false) {
                    kcrm_transfer_fail('Не удалось прочитать подготовленный архив.');
                }
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            } finally {
                kcrm_transfer_remove_temp($job);
            }
            exit;
        }
        if ($action === 'prepare') {
            if (!is_string($transferTargetPath) || $transferTargetPath === '' || !is_string($_POST['target_database'] ?? null)
                || ($_POST['separate_target_confirmed'] ?? '') !== 'yes') {
                kcrm_transfer_fail('Укажите отдельно настроенную новую базу и подтвердите её имя.');
            }
            $targetConfig = kcrm_transfer_load_config($transferTargetPath);
            $target = kcrm_transfer_connect($targetConfig);
            if (hash_equals(kcrm_transfer_database_name(crm_db()), kcrm_transfer_database_name($target))) {
                kcrm_transfer_fail('Нельзя готовить текущую базу CRM.');
            }
            crm_audit('backup.prepare_separate_database_started', 'backup', null, ['target_database' => $_POST['target_database']]);
            $result = kcrm_restore_prepare_schema($target, $_POST['target_database']);
            $transferMessage = 'Структура создана в новой базе ' . $result['database'] . '. Владелец не создавался; теперь можно загрузить архив для восстановления.';
            try {
                crm_audit('backup.separate_database_prepared', 'backup', null, ['target_database' => $result['database']]);
            } catch (Throwable) {
                $transferMessage .= ' Итоговую запись в журнал исходной CRM сохранить не удалось; подготовка завершена.';
            }
        } else {
        $upload = $_FILES['archive'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($upload['tmp_name'] ?? '')) {
            kcrm_transfer_fail('Архив не загружен. Проверьте upload_max_filesize и post_max_size на хостинге.');
        }
        if ($action === 'verify') {
            $manifest = kcrm_backup_verify($upload['tmp_name']);
            $transferMessage = 'Проверка пройдена: таблиц ' . count($manifest['tables']) . ', файлов/таблиц в архиве ' . count($manifest['entries']) . '. База данных не изменялась.';
        } else {
            if (!is_string($transferTargetPath) || $transferTargetPath === '' || !is_string($_POST['target_database'] ?? null)
                || ($_POST['separate_target_confirmed'] ?? '') !== 'yes') {
                kcrm_transfer_fail('Владелец хостинга должен настроить отдельный приватный конфиг восстановления и подтвердить пустую цель.');
            }
            $targetConfig = kcrm_transfer_load_config($transferTargetPath);
            $target = kcrm_transfer_connect($targetConfig);
            if (hash_equals(kcrm_transfer_database_name(crm_db()), kcrm_transfer_database_name($target))) {
                kcrm_transfer_fail('Нельзя восстанавливать в текущую базу CRM.');
            }
            $sourceStorage = realpath(crm_storage_path());
            $targetStorage = realpath(kcrm_transfer_config_storage($targetConfig));
            if ($targetStorage === false || $sourceStorage === $targetStorage
                || ($sourceStorage !== false && str_starts_with($targetStorage . DIRECTORY_SEPARATOR, $sourceStorage . DIRECTORY_SEPARATOR))) {
                kcrm_transfer_fail('Нужен отдельный приватный каталог, вне текущего хранилища.');
            }
            crm_audit('backup.restore_to_separate_database_started', 'backup', null, ['target_database' => $_POST['target_database']]);
            $result = kcrm_backup_restore($target, $upload['tmp_name'], $targetStorage, $_POST['target_database']);
            $transferMessage = 'Восстановлено в отдельную базу ' . $result['database'] . ': таблиц ' . $result['tables'] . ', файлов ' . $result['files'] . '. Текущая CRM не переключалась.';
            try {
                crm_audit('backup.restored_to_separate_database', 'backup', null, ['target_database' => $result['database'], 'schema_version' => $result['schema_version']]);
            } catch (Throwable) {
                $transferMessage .= ' Итоговую запись в журнал исходной CRM сохранить не удалось; восстановление уже завершено.';
            }
        }
        }
        $_SESSION['kcrm_transfer_csrf'] = $transferCsrf = bin2hex(random_bytes(32));
    }
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    $transferMessage = $e instanceof PDOException ? 'Ошибка базы данных. Проверьте отдельную целевую схему и приватный конфиг.' : $e->getMessage();
}
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Космопорт — резервная копия</title>
<style>body{font:16px system-ui;max-width:820px;margin:40px auto;padding:0 20px;background:#f6f5f1;color:#242721}section{background:white;border:1px solid #ddd;border-radius:12px;padding:24px;margin:24px 0}input,button{font:inherit;padding:10px;max-width:100%;box-sizing:border-box}button{cursor:pointer}label{display:block;margin:16px 0}p{line-height:1.5}.notice{padding:20px;background:#fff3ce}</style>
<h1>Резервная копия и перенос</h1>
<p>Для владельца CRM. Архив содержит персональные данные и документы. Сохраните его локально в защищённом месте. После работы удалите временную страницу обслуживания с хостинга.</p>
<?php if ($transferMessage !== ''): ?><p class="notice" role="status"><?= $transferEscape($transferMessage) ?></p><?php endif; ?>
<section><h2>Скачать рабочую копию</h2><p>Остановите изменения и загрузку документов на время копирования. Здесь не включается режим обслуживания всего приложения.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $transferEscape($transferCsrf) ?>"><input type="hidden" name="action" value="backup">
<label><input type="checkbox" name="maintenance_confirmed" value="yes" required> Сотрудники прекратили изменения документов</label><button>Скачать БД и документы</button></form></section>
<section><h2>Проверить архив</h2><p>Проверка структуры, строк таблиц и SHA-256. Записи в БД не производятся.</p>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $transferEscape($transferCsrf) ?>"><input type="hidden" name="action" value="verify">
<label>Архив ZIP <input type="file" name="archive" accept=".zip" required></label><button>Проверить</button></form></section>
<section><h2>Восстановить в отдельную базу</h2><p>Сначала установите ту же версию схемы в новую базу и создайте пустой приватный каталог. Перезапись существующих записей и таблиц запрещена.</p>
<?php if (!is_string($transferTargetPath) || $transferTargetPath === ''): ?><p>Восстановление отключено: отдельный приватный конфиг цели не задан владельцем хостинга.</p>
<?php else: ?>
<h3>1. Подготовить совсем новую базу</h3><p>Создаёт структуру из установленной версии программы. База должна быть совсем без таблиц. Учётная запись владельца здесь не создаётся.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $transferEscape($transferCsrf) ?>"><input type="hidden" name="action" value="prepare">
<label>Точное имя новой целевой базы <input name="target_database" autocomplete="off" required></label>
<label><input type="checkbox" name="separate_target_confirmed" value="yes" required> Это новая отдельная база совсем без таблиц</label><button>Подготовить новую базу</button></form>
<h3>2. Восстановить данные</h3>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $transferEscape($transferCsrf) ?>"><input type="hidden" name="action" value="restore">
<label>Архив ZIP <input type="file" name="archive" accept=".zip" required></label>
<label>Точное имя новой целевой базы <input name="target_database" autocomplete="off" required></label>
<label><input type="checkbox" name="separate_target_confirmed" value="yes" required> Это отдельная пустая база; доступ сотрудников к ней отключён</label><button>Восстановить в указанную базу</button></form><?php endif; ?></section>
</html>
