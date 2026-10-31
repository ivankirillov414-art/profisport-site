<?php
declare(strict_types=1);

defined('KCRM_ROOT') || define('KCRM_ROOT', dirname(__DIR__));
defined('KCRM_VERSION') || define('KCRM_VERSION', '0.1.0');
require_once __DIR__ . '/core/http.php';
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/session.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/records.php';
require_once __DIR__ . '/core/migrations.php';
require_once __DIR__ . '/core/routes.php';
