<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $path = realpath(__DIR__ . '/documents-security-runtime/runtime.private.json');
    if ($path === false) throw new RuntimeException('Runtime metadata absent.');
    $runtime = json_decode(ltrim((string)file_get_contents($path), "\xEF\xBB\xBF"), true, flags: JSON_THROW_ON_ERROR);
    $expectedRoot = realpath(__DIR__ . '/documents-security-runtime');
    $run = realpath($runtime['runtime_path'] ?? '');
    $normalize = static fn(string $value): string => strtolower(rtrim(str_replace('\\', '/', $value), '/'));
    if (($runtime['host'] ?? '') !== '127.0.0.1' || !$expectedRoot || !$run || !str_starts_with($normalize($run), $normalize($expectedRoot) . '/run-')) throw new RuntimeException('Not own isolated runtime.');
    $db = new PDO('mysql:host=127.0.0.1;port=' . (int)$runtime['port'] . ';charset=utf8mb4', $runtime['user'], $runtime['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $actualData = realpath((string)$db->query('SELECT @@datadir')->fetchColumn());
    $expectedData = realpath($run . '/data');
    if (!$actualData || !$expectedData || $normalize($actualData) !== $normalize($expectedData)) throw new RuntimeException('Data directory is not the owned disposable runtime.');
    $db->exec('SHUTDOWN');
    echo "Own disposable document database shutdown requested.\n";
} catch (Throwable) {
    fwrite(STDERR, "Document runtime shutdown not confirmed; no other service was stopped.\n");
    exit(1);
}
