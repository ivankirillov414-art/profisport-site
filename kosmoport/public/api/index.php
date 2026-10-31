<?php
declare(strict_types=1);

ini_set('display_errors', '0'); ini_set('log_errors', '1');
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
try { crm_dispatch(); } catch (Throwable $error) { crm_error_response($error); }
