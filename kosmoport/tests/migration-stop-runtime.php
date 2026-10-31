<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$path = __DIR__ . '/migration-runtime/local-runtime.private.json';
$runtime = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
if (($runtime['host'] ?? null) !== '127.0.0.1') {
    throw new RuntimeException('Only this loopback fixture server can be stopped.');
}
$db = new PDO('mysql:host=127.0.0.1;port=' . (int)$runtime['port'], $runtime['user'], $runtime['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$actual = $db->query('SELECT @@datadir')->fetchColumn();
$normalize = static fn(string $p): string => strtolower(rtrim(str_replace('\\', '/', $p), '/'));
$allowed = realpath(__DIR__ . '/migration-runtime');
if ($allowed === false || !is_string($actual) || !str_starts_with($normalize($actual), $normalize($allowed) . '/db-')) {
    throw new RuntimeException('Server datadir is not owned by this migration test; refusing to stop.');
}
$db->exec('SHUTDOWN');
echo "{\"ok\":true,\"isolated_mariadb_stopped\":true}\n";
