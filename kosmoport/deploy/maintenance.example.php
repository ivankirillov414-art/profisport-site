<?php
declare(strict_types=1);

/**
 * Optional maintenance loader. This example is disabled and is never copied to
 * public/ by the source packager. Configure a temporary unpredictable filename
 * in public/ only after private-path access checks, then remove it after use.
 * Authentication, owner authorization and CSRF are enforced by backup-admin.php.
 * Do not accept bootstrap/config paths from a URL, form or request header.
 */
$maintenanceEnabled = false; // Deliberately opt in on the target host.
if (!$maintenanceEnabled) {
    http_response_code(404);
    exit;
}

// Replace with trusted absolute paths on the target server, outside public/.
$privateProjectRoot = '/replace-with-private-project-root';
$privateRestoreTarget = '/replace-with-private-config/restore-target.php';

$bootstrap = realpath($privateProjectRoot . '/app/bootstrap.php');
$adminTool = realpath($privateProjectRoot . '/tools/backup-admin.php');
if ($bootstrap === false || $adminTool === false || !is_file($bootstrap) || !is_file($adminTool)) {
    http_response_code(503);
    exit('Maintenance is not configured.');
}

header('Cache-Control: no-store');
require $bootstrap;

// This optional separate private PHP file describes the *restore target*.
// It is not bundled in source ZIPs. An absent target leaves restoration disabled.
if (is_file($privateRestoreTarget)) {
    define('KCRM_BACKUP_TARGET_CONFIG', $privateRestoreTarget);
}
require $adminTool;
