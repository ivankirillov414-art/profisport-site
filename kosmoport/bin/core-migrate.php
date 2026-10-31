<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/bootstrap.php';
try { echo json_encode(['ok' => true, 'data' => crm_run_migrations()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL; }
catch (Throwable $error) { fwrite(STDERR, ($error instanceof CrmHttpException ? $error->getMessage() : 'Migration failed; inspect private configuration and database access.') . PHP_EOL); exit(1); }
