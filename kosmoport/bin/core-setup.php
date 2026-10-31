<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/bootstrap.php';
try {
    // Password and key arrive over stdin, never in shell arguments/history.
    $input = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('Invalid input');
    echo json_encode(['ok' => true, 'data' => ['user' => crm_install_owner($input)]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, ($error instanceof CrmHttpException ? $error->getMessage() : 'Setup failed; inspect private configuration and database access.') . PHP_EOL); exit(1); }
