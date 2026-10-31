<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('KCRM_PACKAGE_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/tools/package.php';

function package_review_target(string $base, string $suffix): string
{
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $base . '/' . $suffix)) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            if ($parts === []) throw new RuntimeException('Dependency escapes source root.');
            array_pop($parts);
        } else $parts[] = $part;
    }
    return kcrm_package_name(implode('/', $parts));
}

try {
    $archive = $argv[1] ?? '';
    if (!class_exists('ZipArchive') || !is_file($archive) || kcrm_package_is_link($archive)) {
        throw new RuntimeException('Use a regular source ZIP file with the matching PHP ZIP extension enabled.');
    }
    $outer = hash_file('sha256', $archive);
    $sidecar = trim((string)file_get_contents($archive . '.sha256'));
    if ($sidecar !== $outer . '  ' . basename($archive)) throw new RuntimeException('Outer SHA256 sidecar mismatch.');
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot open source ZIP.');
    $members = []; $names = []; $expanded = 0;
    try {
        if ($zip->numFiles > KCRM_PACKAGE_MAX_FILES + 100) throw new RuntimeException('Too many ZIP members.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            kcrm_package_name(rtrim($name, '/'));
            $folded = strtolower($name);
            if (isset($names[$folded])) throw new RuntimeException('Duplicate ZIP path.');
            $names[$folded] = true;
            if (str_ends_with($name, '/')) continue;
            if ($stat['size'] > KCRM_PACKAGE_MAX_FILE || ($expanded += $stat['size']) > KCRM_PACKAGE_MAX_TOTAL + KCRM_PACKAGE_MAX_FILE) throw new RuntimeException('ZIP size limit.');
            $bytes = $zip->getFromIndex($i);
            if (!is_string($bytes) || strlen($bytes) !== $stat['size']) throw new RuntimeException('Unreadable ZIP member.');
            $members[$name] = $bytes;
        }
    } finally { $zip->close(); }
    $metadata = json_decode($members['SOURCE-PACKAGE.json'] ?? '', true, 64, JSON_THROW_ON_ERROR);
    if (($metadata['format'] ?? '') !== 'kosmoport-source-v1' || $metadata['contains_business_data'] !== false || $metadata['contains_live_configuration'] !== false) throw new RuntimeException('Not a source-only package.');
    $covered = [];
    foreach (explode("\n", trim($members['CHECKSUMS.sha256'] ?? '')) as $line) {
        if (!preg_match('/^([a-f0-9]{64})  (.+)$/D', $line, $match) || isset($covered[$match[2]])
            || !isset($members[$match[2]]) || hash('sha256', $members[$match[2]]) !== $match[1]) throw new RuntimeException('Member checksum mismatch.');
        $covered[$match[2]] = true;
    }
    $regular = array_keys($members); $expected = array_keys($covered);
    $regular = array_values(array_filter($regular, static fn(string $name): bool => $name !== 'CHECKSUMS.sha256'));
    sort($regular); sort($expected);
    if ($regular !== $expected) throw new RuntimeException('Checksum manifest coverage mismatch.');
    foreach (array_keys($members) as $name) {
        if (strcasecmp($name, 'deploy/CI_SOURCE_NOTES.md') === 0) throw new RuntimeException('Temporary coordinator note in release ZIP.');
        $generated = in_array($name, ['.htaccess', 'SOURCE-PACKAGE.json', 'CHECKSUMS.sha256', 'config/config.example.php', 'config/infinityfree.example.php', 'deploy/root.htaccess'], true)
            || in_array($name, array_map(static fn(string $dir): string => $dir . '/.htaccess', KCRM_PACKAGE_PRIVATE), true)
            || (str_starts_with($name, 'public/') && basename($name) === '.htaccess');
        if (!$generated && kcrm_package_excluded($name, false)) throw new RuntimeException('Excluded source entry in ZIP: ' . $name);
        if (str_starts_with($name, 'config/') && !in_array($name, ['config/.htaccess', 'config/config.example.php', 'config/infinityfree.example.php'], true)) throw new RuntimeException('Private configuration in ZIP.');
        if (str_starts_with($name, 'storage/') && $name !== 'storage/.htaccess') throw new RuntimeException('Business storage in source ZIP.');
    }
    foreach (KCRM_PACKAGE_PRIVATE as $private) {
        $rules = $members[$private . '/.htaccess'] ?? '';
        if (!str_contains($rules, 'Require all denied') || !str_contains($rules, 'Deny from all')) throw new RuntimeException('Missing independent private HTTP rules.');
    }
    if (isset($members['deploy/install-public.json'])) {
        kcrm_package_install_manifest($members['deploy/install-public.json']);
    }
    foreach (['app/bootstrap.php', 'app/core/config.php', 'public/api/index.php', 'config/config.example.php',
        'app/website/field-schema.json', 'app/website/default-content.json', 'tools/backup-lib.php',
        'tools/backup.php', 'tools/restore.php', 'tools/restore-schema.php', 'bin/core-key.php', 'deploy/README.md'] as $required) {
        if (!isset($members[$required])) throw new RuntimeException('Missing required source: ' . $required);
    }
    $dependencies = []; $dynamic = [];
    foreach ($members as $name => $bytes) {
        if (!str_ends_with($name, '.php')) continue;
        $tokens = token_get_all($bytes, TOKEN_PARSE); // Syntax only: no application/config execution.
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) continue;
            $expression = '';
            for ($j = $index + 1; $j < count($tokens); $j++) {
                $part = $tokens[$j];
                if ($part === ';') break;
                if (is_array($part) && in_array($part[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $expression .= is_array($part) ? $part[1] : $part;
                if (strlen($expression) > 2000) break;
            }
            $expression = trim(trim($expression), '() ' . "\t\r\n");
            $target = null;
            if (preg_match('~^__DIR__\s*\.\s*([\'"])([^\'"]+)\1$~D', $expression, $match)) {
                $target = package_review_target(dirname($name), $match[2]);
            } elseif (preg_match('~^dirname\s*\(\s*__DIR__\s*(?:,\s*(\d+))?\s*\)\s*\.\s*([\'"])([^\'"]+)\2$~D', $expression, $match)) {
                $base = dirname($name);
                for ($level = 0; $level < (int)($match[1] !== '' ? $match[1] : 1); $level++) $base = dirname($base);
                $target = package_review_target($base, $match[3]);
            } elseif ($name === 'public/index.php' && preg_match('~^\$applicationRoot\s*\.\s*([\'"])([^\'"]+)\1~', $expression, $match)) {
                $target = package_review_target('.', $match[2]);
            } elseif ($name === 'tools/backup-lib.php' && $expression === '$parser') {
                $target = 'app/core/migrations.php'; // Reviewed assignment: $trustedRoot . '/app/core/migrations.php'.
            }
            if ($target !== null) {
                if (!isset($members[$target])) throw new RuntimeException('Missing static PHP dependency: ' . $name . ' -> ' . $target);
                $dependencies[] = ['from' => $name, 'to' => $target];
            } else $dynamic[] = ['file' => $name, 'line' => $token[2], 'expression' => $expression];
        }
    }
    // These immutable JSON dependencies are loaded by file_get_contents, not require.
    foreach (['field-schema.json', 'default-content.json'] as $json) json_decode($members['app/website/' . $json], true, 64, JSON_THROW_ON_ERROR);
    echo json_encode(['ok' => true, 'archive' => realpath($archive), 'sha256' => $outer, 'member_hashes_verified' => count($covered),
        'php_static_dependencies_verified' => count($dependencies), 'missing_static_dependencies' => 0,
        'dynamic_require_sites_for_review' => $dynamic, 'private_live_config_included' => false, 'business_storage_included' => false,
        'deployment_layout' => 'app/public/config/migrations/bin/tools/storage siblings; no flattening',
        'runtime_or_hosting_verification' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Source review refused: ' . $error->getMessage() . "\n");
    exit(1);
}
