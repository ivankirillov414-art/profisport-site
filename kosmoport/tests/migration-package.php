<?php
declare(strict_types=1);

define('KCRM_PACKAGE_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/tools/package.php';

$checks = 0;
$skips = [];
function package_assert(bool $value, string $message): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo 'PASS: ' . $message . "\n";
}
function package_reject(callable $call, string $message, string $fragment = ''): void
{
    $failed = false;
    try {
        $call();
    } catch (RuntimeException $error) {
        $failed = $fragment === '' || str_contains($error->getMessage(), $fragment);
    }
    package_assert($failed, $message);
}
function package_fixture_file(string $root, string $name, string $contents): void
{
    $path = $root . '/' . $name;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0770, true);
    }
    file_put_contents($path, $contents);
}
function package_remove_fixture(string $path, string $root): void
{
    if (!kcrm_package_below($path, $root) || $path === dirname($path)) {
        throw new RuntimeException('Unsafe fixture cleanup.');
    }
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                package_remove_fixture($path . '/' . $entry, $root);
            }
        }
        rmdir($path);
    }
}

$temporary = realpath(sys_get_temp_dir());
if ($temporary === false) {
    throw new RuntimeException('No fixture temporary directory.');
}
$fixture = $temporary . DIRECTORY_SEPARATOR . 'kcrm-package-fixture-' . bin2hex(random_bytes(8));
mkdir($fixture, 0770);
try {
    package_reject(static fn() => kcrm_package_collect($fixture), 'Missing application source fails clearly', 'required files');
    package_fixture_file($fixture, 'app/bootstrap.php', '<?php require_once __DIR__ . "/core/config.php"; // fixture, never executed');
    package_fixture_file($fixture, 'app/core/config.php', '<?php function fixture_config_loader(): array { return []; }');
    package_fixture_file($fixture, 'app/website/field-schema.json', '{"brand":"text"}');
    package_fixture_file($fixture, 'app/website/default-content.json', '{"brand":"PUBLIC SOURCE FIXTURE"}');
    package_fixture_file($fixture, 'public/api/index.php', '<?php // fixture API, no database');
    package_fixture_file($fixture, 'config/config.example.php', '<?php return ["database" => ["password" => "REPLACE_ON_HOST"]];');
    package_fixture_file($fixture, 'config/infinityfree.example.php', '<?php // immutable hosting adapter; credentials come from a separate private file');
    mkdir($fixture . '/migrations');
    package_reject(static fn() => kcrm_package_collect($fixture), 'Empty migrations directory fails clearly', 'no nonempty .sql');
    package_fixture_file($fixture, 'migrations/001_fixture.sql', 'CREATE TABLE kcrm_fixture (id INT PRIMARY KEY);');
    package_fixture_file($fixture, 'deploy/root.htaccess', (string)file_get_contents(dirname(__DIR__) . '/deploy/root.htaccess'));
    package_fixture_file($fixture, 'deploy/maintenance.example.php', (string)file_get_contents(dirname(__DIR__) . '/deploy/maintenance.example.php'));
    package_fixture_file($fixture, 'deploy/ftp-release.py', '# exact deployment source fixture only');
    package_fixture_file($fixture, 'deploy/github-workflow.yml', 'name: exact-workflow-source-fixture');
    $canonicalInstall = ['format' => 'kosmoport-install-public-v1', 'setup_key_sha256' => str_repeat('a', 64), 'base_path' => '/kosmoport', 'contains_secret' => false];
    package_fixture_file($fixture, 'deploy/install-public.json', json_encode($canonicalInstall, JSON_THROW_ON_ERROR));
    package_fixture_file($fixture, 'tools/backup.php', '<?php // CLI source fixture');
    package_fixture_file($fixture, 'bin/core-key.php', '<?php // private CLI source fixture');
    package_fixture_file($fixture, 'public/assets/app.js', 'console.log("fixture only");');
    package_fixture_file($fixture, 'public/.htaccess', 'Options -Indexes');
    package_fixture_file($fixture, 'public/assets/.htaccess', 'Options -Indexes');
    package_fixture_file($fixture, 'public/manifest.json', '{"name":"fixture"}');
    package_fixture_file($fixture, 'integrations/ticket-app/format.schema.json', '{"type":"object"}');
    package_fixture_file($fixture, 'docs/MIGRATION_BACKUP.md', '# Source backup instructions');
    $denied = [
        'config/config.php', 'config/production.php', '.env', 'app/.env.local', 'app/config.php',
        'app/credentials.php', 'app/bootstrap.backup.php', 'public/assets/app.js.bak',
        'public/node_modules/dependency/index.js', 'public/fixtures/customer.php',
        'tools/backups/previous.php', 'tests/fixtures/customer.php',
        'storage/documents/business.pdf', 'storage/backups/business.zip',
        'public/uploads/business.png', 'public/documents/contract.php',
        'docs/export.json', 'docs/database.sql.gz', 'deploy/previous-release.zip',
        'docs/database.sql', 'docs/passwords.txt', 'deploy/restore-target.php',
        'deploy/artifacts/previous-source.php',
        'app/website/customer-export.json', 'app/website/config.php',
        'deploy/unreviewed.py', 'deploy/unreviewed.yml', 'config/deployment.php',
        'deploy/CI_SOURCE_NOTES.md',
        'tools/verify-stop-db.php',
    ];
    foreach ($denied as $name) {
        package_fixture_file($fixture, $name, 'PRIVATE BUSINESS OR SECRET FIXTURE MUST NOT BE PACKAGED');
    }
    $source = kcrm_package_collect($fixture);
    $names = array_column($source['files'], 'path');
    foreach ($denied as $name) {
        package_assert(!in_array($name, $names, true), 'Exclude ' . $name);
    }
    foreach (['app/bootstrap.php', 'app/core/config.php', 'app/website/field-schema.json', 'app/website/default-content.json',
        'public/api/index.php', 'config/config.example.php', 'config/infinityfree.example.php', 'bin/core-key.php',
        'deploy/ftp-release.py', 'deploy/github-workflow.yml', 'deploy/install-public.json',
        'migrations/001_fixture.sql', 'tools/backup.php', 'public/assets/app.js', 'public/.htaccess', 'public/assets/.htaccess',
        'public/manifest.json', 'integrations/ticket-app/format.schema.json', 'docs/MIGRATION_BACKUP.md'] as $name) {
        package_assert(in_array($name, $names, true), 'Retain source ' . $name);
    }
    package_assert(!in_array('public/maintenance.example.php', $names, true), 'Maintenance example is never activated in public');
    $loader = (string)file_get_contents(dirname(__DIR__) . '/deploy/maintenance.example.php');
    package_assert(str_contains($loader, '$maintenanceEnabled = false;'), 'Maintenance loader defaults to disabled');
    package_assert(str_contains($loader, "define('KCRM_BACKUP_TARGET_CONFIG'"), 'Restore target is an optional separate private configuration');
    $sourceFiles = array_column($source['files'], 'bytes', 'path');
    package_assert(str_contains($sourceFiles['app/bootstrap.php'], '/core/config.php') && isset($sourceFiles['app/core/config.php']), 'Bootstrap configuration-loader dependency survives source packaging');
    package_assert(json_decode($sourceFiles['app/website/field-schema.json'], true)['brand'] === 'text'
        && json_decode($sourceFiles['app/website/default-content.json'], true)['brand'] === 'PUBLIC SOURCE FIXTURE', 'Exact public website schema/default JSON dependencies survive packaging');
    package_assert(!in_array('config/config.php', $names, true) && !in_array('app/config.php', $names, true), 'Loader exception never permits populated private config files');
    package_assert(str_contains($sourceFiles['.htaccess'], 'migrations|bin|tools'), 'Root routing protection explicitly denies private bin routes');
    package_fixture_file($fixture, 'deploy/install-public.json', json_encode(['setup_key_hash' => str_repeat('a', 64), 'setup_token' => 'NEVER-PACKAGE-RAW-TOKEN'], JSON_THROW_ON_ERROR));
    package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse extra public-manifest fields including raw setup tokens', 'installation manifest');
    package_fixture_file($fixture, 'deploy/install-public.json', '{"setup_key_hash":"invalid"}');
    package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse malformed setup hash', 'installation manifest');
    package_fixture_file($fixture, 'deploy/install-public.json', '{"setup_key_hash":"NEVER-PACKAGE-RAW-TOKEN","setup_key_hash":"' . str_repeat('a', 64) . '"}');
    package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse duplicate setup keys that could conceal raw token bytes', 'installation manifest');
    package_fixture_file($fixture, 'deploy/install-public.json', '{');
    package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse malformed public installation JSON', 'manifest JSON');
    package_assert(kcrm_package_install_manifest(json_encode($canonicalInstall, JSON_THROW_ON_ERROR)) === $canonicalInstall, 'Accept the exact canonical four-field public installation manifest');
    package_assert(kcrm_package_install_manifest(json_encode(['setup_key_hash' => str_repeat('b', 64)], JSON_THROW_ON_ERROR))['setup_key_hash'] === str_repeat('b', 64), 'Retain exact legacy one-hash manifest compatibility');
    package_fixture_file($fixture, 'deploy/install-public.json', json_encode($canonicalInstall + ['token' => 'NEVER-PACKAGE'], JSON_THROW_ON_ERROR));
    package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse canonical manifest with unknown token field', 'installation manifest');
    package_fixture_file($fixture, 'deploy/install-public.json', json_encode(array_replace($canonicalInstall, ['contains_secret' => true]), JSON_THROW_ON_ERROR));
    package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse canonical manifest claiming secret contents', 'installation manifest');
    foreach (['format' => 'other-install-format', 'base_path' => '/other-app', 'setup_key_sha256' => 'not-a-sha256'] as $field => $invalidValue) {
        package_fixture_file($fixture, 'deploy/install-public.json', json_encode(array_replace($canonicalInstall, [$field => $invalidValue]), JSON_THROW_ON_ERROR));
        package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse canonical installation field mismatch: ' . $field, 'installation manifest');
    }
    package_fixture_file($fixture, 'deploy/install-public.json', '{"format":"kosmoport-install-public-v1","setup_key_sha256":"NEVER-PACKAGE","setup_key_sha256":"' . str_repeat('a', 64) . '","base_path":"/kosmoport","contains_secret":false}');
    package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse duplicate canonical hash fields that conceal earlier raw bytes', 'installation manifest');
    foreach (['format', 'base_path', 'contains_secret'] as $field) {
        $duplicate = '{"' . $field . '":"NEVER-PACKAGE-RAW-BYTES",' . substr(json_encode($canonicalInstall, JSON_THROW_ON_ERROR), 1);
        package_fixture_file($fixture, 'deploy/install-public.json', $duplicate);
        package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse duplicate canonical public field: ' . $field, 'installation manifest');
    }
    package_fixture_file($fixture, 'deploy/install-public.json', json_encode($canonicalInstall, JSON_THROW_ON_ERROR));
    foreach (KCRM_PACKAGE_PRIVATE as $private) {
        package_assert(str_contains($sourceFiles[$private . '/.htaccess'], 'Require all denied')
            && str_contains($sourceFiles[$private . '/.htaccess'], 'Deny from all'), 'Private root denied independently of rewrite: ' . $private);
    }
    foreach (['../escape.php', 'app/../escape.php', '/absolute.php', 'app\\escape.php', 'app/C:escape.php', "app/control\n.php", 'app/NUL.txt'] as $name) {
        package_reject(static fn() => kcrm_package_name($name), 'Reject unsafe archive name ' . json_encode($name));
    }
    $collisions = [];
    kcrm_package_add($collisions, 'app/Case.php', '<?php');
    package_reject(static function () use (&$collisions): void { kcrm_package_add($collisions, 'app/case.php', '<?php'); }, 'Reject case-colliding archive members');
    package_fixture_file($fixture, 'app/key.php', '-----BEGIN ' . 'PRIVATE KEY-----' . "\nfixture only\n");
    package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse private key material in an otherwise allowed source file', 'Private key');
    unlink($fixture . '/app/key.php');
    $link = $fixture . '/app/linked.php';
    if (@symlink($fixture . '/app/bootstrap.php', $link)) {
        package_reject(static fn() => kcrm_package_collect($fixture), 'Refuse source symlink', 'Symbolic links');
        unlink($link);
    } else {
        $skips[] = 'Native symlink fixture unavailable to this PHP process.';
    }
    package_reject(static fn() => kcrm_package_destination($fixture . '/app/nested/release.zip', $fixture, false), 'Refuse output recursion before creating source directories');
    package_assert(!is_dir($fixture . '/app/nested'), 'Rejected recursive output leaves application source untouched');
    package_reject(static fn() => kcrm_package_destination($fixture . '/../escape.zip', $fixture, false), 'Reject traversal in output path');
    package_reject(static fn() => kcrm_package_destination($fixture . '/NUL.zip', $fixture, false), 'Reject reserved output filename');
    if (!class_exists('ZipArchive')) {
        package_reject(static fn() => kcrm_package_write($source, $fixture . '/build/release.zip'), 'Missing ZIP extension fails clearly', 'ZipArchive');
        $skips[] = 'Enable the matching PHP zip extension for actual archive/checksum tests.';
    } else {
        $source = kcrm_package_collect($fixture);
        $result = kcrm_package_write($source, $fixture . '/build/release.zip');
        package_assert($result['ok'] && is_file($result['archive']), 'Create a complete source ZIP');
        package_assert(hash_file('sha256', $result['archive']) === $result['sha256'], 'Report the actual ZIP SHA256');
        package_assert(str_starts_with((string)file_get_contents($result['archive'] . '.sha256'), $result['sha256'] . '  release.zip'), 'Write external ZIP checksum sidecar');
        $zip = new ZipArchive();
        package_assert($zip->open($result['archive']) === true, 'ZIP reopens after atomic promotion');
        $zipNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zipNames[] = $zip->getNameIndex($i);
        }
        $manifest = $zip->getFromName('CHECKSUMS.sha256');
        package_assert(is_string($manifest), 'ZIP contains checksum manifest');
        $covered = [];
        foreach (explode("\n", trim((string)$manifest)) as $line) {
            if (!preg_match('/^([a-f0-9]{64})  (.+)$/D', $line, $match)) {
                throw new RuntimeException('Invalid checksum fixture line');
            }
            $bytes = $zip->getFromName($match[2]);
            package_assert(is_string($bytes) && hash('sha256', $bytes) === $match[1], 'Verify ZIP member checksum ' . $match[2]);
            $covered[] = $match[2];
        }
        $regular = array_values(array_filter($zipNames, static fn(string $name): bool => !str_ends_with($name, '/') && $name !== 'CHECKSUMS.sha256'));
        sort($regular);
        sort($covered);
        package_assert($regular === $covered, 'Every regular ZIP member except manifest is covered exactly once');
        foreach (['storage/', 'storage/documents/', 'storage/backups/', 'storage/tmp/'] as $directory) {
            package_assert(in_array($directory, $zipNames, true), 'Ship empty storage scaffolding ' . $directory);
            $operatingSystem = 0;
            $attributes = 0;
            $zip->getExternalAttributesName($directory, $operatingSystem, $attributes);
            package_assert($operatingSystem === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0002) === 0, 'Storage directory is not world-writable: ' . $directory);
        }
        package_assert(count(array_filter($zipNames, static fn(string $name): bool => str_starts_with($name, 'storage/') && !str_ends_with($name, '/') && $name !== 'storage/.htaccess')) === 0, 'Source ZIP contains no business storage files');
        $metadata = json_decode((string)$zip->getFromName('SOURCE-PACKAGE.json'), true, 512, JSON_THROW_ON_ERROR);
        package_assert($metadata['contains_business_data'] === false && $metadata['contains_live_configuration'] === false, 'Source package metadata explicitly excludes live data/configuration');
        $zip->close();
        $before = hash_file('sha256', $result['archive']);
        package_reject(static fn() => kcrm_package_write($source, $result['archive']), 'Existing archive cannot be overwritten implicitly');
        package_assert(hash_file('sha256', $result['archive']) === $before, 'Refused overwrite preserves existing archive');
        package_assert(kcrm_package_write($source, $result['archive'], true)['ok'], 'Explicit --force can replace an ordinary archive');
        package_assert(kcrm_package_write($source, $fixture . '/deploy/artifacts/release.zip')['ok'], 'Allow the dedicated excluded deployment artifact output tree');
        $rebuilt = kcrm_package_collect($fixture);
        package_assert(count(array_filter(array_column($rebuilt['files'], 'path'), static fn(string $name): bool => str_starts_with($name, 'deploy/artifacts/'))) === 0, 'Repeated packaging never collects its deployment artifact outputs');
    }
    echo 'ALL ' . $checks . ' SOURCE PACKAGE CHECKS PASSED' . "\n";
    foreach ($skips as $skip) {
        echo 'SKIP: ' . $skip . "\n";
    }
} finally {
    if (dirname($fixture) !== $temporary || !str_starts_with(basename($fixture), 'kcrm-package-fixture-')) {
        throw new RuntimeException('Unsafe fixture root cleanup.');
    }
    package_remove_fixture($fixture, $fixture);
}
