<?php
declare(strict_types=1);

/** CLI source packaging only. Database/document backups belong to backup.php. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const KCRM_PACKAGE_ROOTS = ['app', 'public', 'migrations', 'bin', 'tools', 'docs', 'deploy', 'integrations'];
const KCRM_PACKAGE_PRIVATE = ['app', 'config', 'migrations', 'bin', 'tools', 'docs', 'deploy', 'integrations', 'storage'];
const KCRM_PACKAGE_MAX_FILE = 32 * 1024 * 1024;
const KCRM_PACKAGE_MAX_TOTAL = 64 * 1024 * 1024;
const KCRM_PACKAGE_MAX_FILES = 12000;

function kcrm_package_fail(string $message): never
{
    throw new RuntimeException($message);
}

function kcrm_package_name(string $relative): string
{
    if ($relative === '' || strlen($relative) > 1024 || !preg_match('//u', $relative)
        || preg_match('/[\\\\:\x00-\x1f\x7f]/', $relative) || str_starts_with($relative, '/')) {
        kcrm_package_fail('Unsafe package path.');
    }
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/', $part)
            || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)) {
            kcrm_package_fail('Unsafe package path: ' . $relative);
        }
    }
    return $relative;
}

function kcrm_package_below(string $path, string $root): bool
{
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || str_starts_with($path, $root . '/');
}

function kcrm_package_is_link(string $path): bool
{
    // PHP on Windows can report is_link=false and mode=0 for a directory
    // junction, while readlink still returns its reparse target.
    if (is_link($path)) {
        return true;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        $stat = @lstat($path);
        // Windows readlink() also canonicalizes ordinary files/directories, so
        // it must only classify entries that lstat reports as a reparse type.
        return $stat !== false && ($stat['mode'] & 0170000) === 0 && @readlink($path) !== false;
    }
    return false;
}

/** Reject linked ancestors too, so a linked source/output root cannot hide links. */
function kcrm_package_no_links(string $path): void
{
    $cursor = $path;
    for ($i = 0; $i < 256; $i++) {
        if (kcrm_package_is_link($cursor)) {
            kcrm_package_fail('Symbolic links are not permitted: ' . $cursor);
        }
        $parent = dirname($cursor);
        if ($parent === $cursor || $cursor === '.' || $cursor === '') {
            return;
        }
        $cursor = $parent;
    }
    kcrm_package_fail('Excessively deep filesystem path.');
}

function kcrm_package_root(string $path): string
{
    kcrm_package_no_links($path);
    $root = realpath($path);
    if ($root === false || !is_dir($root)) {
        kcrm_package_fail('Source root does not exist.');
    }
    kcrm_package_no_links($root);
    return rtrim($root, '/\\');
}

function kcrm_package_excluded(string $relative, bool $directory): bool
{
    if (strcasecmp($relative, 'deploy/CI_SOURCE_NOTES.md') === 0) return true; // Temporary coordinator handoff, never a release source.
    if (strcasecmp($relative, 'tools/verify-stop-db.php') === 0) return true; // Local QA runtime shutdown; requires intentionally excluded test fixtures.
    $parts = explode('/', $relative);
    foreach ($parts as $part) {
        if (($part[0] === '.' && $part !== '.htaccess')
            || preg_match('/^(?:node_modules|vendor|tests?|fixtures?|backups?|storage|uploads?|attachments?|exports?|snapshots?|artifacts|build|dist|outputs?|releases?|cache|tmp|temp|logs?)$/i', $part)
            || preg_match('/(?:^|[._-])(?:secrets?|credentials?|passwords?|passwd)(?:[._-]|$)/i', $part)) {
            return true;
        }
    }
    if (strcasecmp($parts[0], 'public') === 0 && count($parts) > 1 && preg_match('/^documents?$/i', $parts[1])) {
        return true;
    }
    $name = basename($relative);
    if ((strcasecmp($name, 'config.php') === 0 && $relative !== 'app/core/config.php') || preg_match('/^(?:(?:restore|backup)[._-]target|target[._-]config|config[._-](?:local|production|private))\.php$/i', $name)
        || preg_match('/^\.env(?:\.|$)/i', $name)
        || preg_match('/\.(?:bak|backup|old|orig|save|swp|tmp)(?:\.[^.]+)?$/i', $name)
        || preg_match('/(?:[._-](?:backup|copy|old|orig)|[._-]\d{8}(?:[._-]\d{6})?)\.(?:php|js|css|html|sql|json)$/i', $name)
        || str_ends_with($name, '~')) {
        return true;
    }
    if ($directory) {
        return false;
    }
    if ($name === '.htaccess' || str_ends_with(strtolower($name), '.htaccess')) {
        return true; // Only controlled private/root rules are generated below; public rules are handled separately.
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($relative, ['deploy/ftp-release.py', 'deploy/github-workflow.yml', 'deploy/install-public.json'], true)) {
        return false; // Exact reviewed deployment sources; no broad Python/YAML/JSON admission.
    }
    if ($extension === 'sql' && $parts[0] !== 'migrations') {
        return true; // SQL outside the migration root can be a live database dump.
    }
    $allowed = ['php', 'js', 'mjs', 'cjs', 'css', 'html', 'htm', 'sql', 'md', 'txt',
        'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'webmanifest'];
    if ($extension === 'json') {
        if (in_array($relative, ['app/website/field-schema.json', 'app/website/default-content.json'], true)) {
            return false; // Reviewed immutable source schema/default public content.
        }
        return !preg_match('/(?:^|\.)schema\.json$/i', $name)
            && !($parts[0] === 'public' && strcasecmp($name, 'manifest.json') === 0);
    }
    return !in_array($extension, $allowed, true);
}

function kcrm_package_install_manifest(string $bytes): array
{
    try { $manifest = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { kcrm_package_fail('Invalid public installation manifest JSON.'); }
    if (!is_array($manifest)) kcrm_package_fail('Public installation manifest must be an object.');
    $keys = array_keys($manifest);
    sort($keys, SORT_STRING);
    $legacy = $keys === ['setup_key_hash']
        && is_string($manifest['setup_key_hash']) && preg_match('/\A[a-f0-9]{64}\z/D', $manifest['setup_key_hash'])
        && preg_match('/\A\s*\{\s*"setup_key_hash"\s*:\s*"[a-f0-9]{64}"\s*\}\s*\z/D', $bytes);
    $pair = '(?:"format"\s*:\s*"[^"]*"|"setup_key_sha256"\s*:\s*"[a-f0-9]{64}"|"base_path"\s*:\s*"[^"]*"|"contains_secret"\s*:\s*false)';
    $canonical = $keys === ['base_path', 'contains_secret', 'format', 'setup_key_sha256']
        && $manifest['format'] === 'kosmoport-install-public-v1' && $manifest['base_path'] === '/kosmoport'
        && $manifest['contains_secret'] === false && is_string($manifest['setup_key_sha256'])
        && preg_match('/\A[a-f0-9]{64}\z/D', $manifest['setup_key_sha256'])
        && preg_match('/\A\s*\{\s*' . $pair . '(?:\s*,\s*' . $pair . '){3}\s*\}\s*\z/D', $bytes);
    // Exact pair count plus exact distinct decoded keys also rejects duplicate
    // properties, including a discarded earlier value containing raw secrets.
    if (!$legacy && !$canonical) kcrm_package_fail('Public installation manifest must be the exact versioned four-field public schema or legacy setup_key_hash-only schema; raw tokens, passwords and duplicate/unknown fields are forbidden.');
    return $manifest;
}

function kcrm_package_read(string $root, string $relative): string
{
    kcrm_package_name($relative);
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    kcrm_package_no_links($path);
    $resolved = realpath($path);
    if ($resolved === false || !kcrm_package_below($resolved, $root) || !is_file($path)) {
        kcrm_package_fail('Source is not a regular file inside the source root: ' . $relative);
    }
    $before = lstat($path);
    if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > KCRM_PACKAGE_MAX_FILE) {
        kcrm_package_fail('Invalid or oversized source file: ' . $relative);
    }
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        kcrm_package_fail('Cannot read source file: ' . $relative);
    }
    try {
        $opened = fstat($stream);
        if ($opened === false || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']) {
            kcrm_package_fail('Source changed while opening: ' . $relative);
        }
        $bytes = stream_get_contents($stream, KCRM_PACKAGE_MAX_FILE + 1);
        $after = fstat($stream);
    } finally {
        fclose($stream);
    }
    clearstatcache(true, $path);
    if ($bytes === false || strlen($bytes) > KCRM_PACKAGE_MAX_FILE || $after === false
        || $after['size'] !== $before['size'] || $after['mtime'] !== $before['mtime']
        || strlen($bytes) !== $before['size'] || kcrm_package_is_link($path) || realpath($path) !== $resolved) {
        kcrm_package_fail('Source changed while reading: ' . $relative);
    }
    if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----/', $bytes)) {
        kcrm_package_fail('Private key material detected; refusing source archive: ' . $relative);
    }
    if ($relative === 'deploy/install-public.json') {
        kcrm_package_install_manifest($bytes);
    }
    return $bytes;
}

function kcrm_package_add(array &$files, string $relative, string $bytes): void
{
    kcrm_package_name($relative);
    $key = strtolower($relative);
    if (isset($files[$key])) {
        kcrm_package_fail('Duplicate or case-colliding package path: ' . $relative);
    }
    if (count($files) >= KCRM_PACKAGE_MAX_FILES) {
        kcrm_package_fail('Too many source files.');
    }
    $total = strlen($bytes);
    foreach ($files as $file) {
        $total += strlen($file['bytes']);
    }
    if ($total > KCRM_PACKAGE_MAX_TOTAL) {
        kcrm_package_fail('Source package exceeds the uncompressed size limit.');
    }
    $files[$key] = ['path' => $relative, 'bytes' => $bytes];
}

function kcrm_package_walk(string $root, string $relative, array &$files, int &$skipped): void
{
    kcrm_package_name($relative);
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (kcrm_package_is_link($path)) {
        kcrm_package_fail('Symbolic links are not permitted: ' . $relative);
    }
    if (!file_exists($path)) {
        return;
    }
    $resolved = realpath($path);
    if ($resolved === false || !kcrm_package_below($resolved, $root)) {
        kcrm_package_fail('Source path escapes the source root: ' . $relative);
    }
    $directory = is_dir($path);
    $publicRules = str_starts_with($relative, 'public/') && basename($relative) === '.htaccess';
    if (!$publicRules && kcrm_package_excluded($relative, $directory)) {
        $skipped++;
        return;
    }
    if ($directory) {
        $entries = scandir($path, SCANDIR_SORT_ASCENDING);
        if ($entries === false) {
            kcrm_package_fail('Cannot enumerate source directory: ' . $relative);
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                kcrm_package_walk($root, $relative . '/' . $entry, $files, $skipped);
            }
        }
    } elseif (is_file($path)) {
        kcrm_package_add($files, $relative, kcrm_package_read($root, $relative));
    } else {
        kcrm_package_fail('Non-regular source filesystem entry: ' . $relative);
    }
}

function kcrm_package_collect(string $source): array
{
    $root = kcrm_package_root($source);
    $required = ['app/bootstrap.php', 'public/api/index.php', 'config/config.example.php'];
    $missing = [];
    foreach ($required as $relative) {
        $path = $root . '/' . $relative;
        kcrm_package_no_links($path);
        if (!is_file($path) || filesize($path) === 0) {
            $missing[] = $relative;
        }
    }
    if (!is_dir($root . '/migrations')) {
        $missing[] = 'migrations/ (with at least one nonempty .sql migration)';
    }
    if ($missing !== []) {
        kcrm_package_fail('Application source is not ready; required files missing or empty: ' . implode(', ', $missing));
    }
    $files = [];
    $skipped = 0;
    foreach (KCRM_PACKAGE_ROOTS as $relative) {
        kcrm_package_walk($root, $relative, $files, $skipped);
    }
    kcrm_package_add($files, 'config/config.example.php', kcrm_package_read($root, 'config/config.example.php'));
    $hostingTemplate = $root . '/config/infinityfree.example.php';
    if (file_exists($hostingTemplate) || kcrm_package_is_link($hostingTemplate)) {
        kcrm_package_add($files, 'config/infinityfree.example.php', kcrm_package_read($root, 'config/infinityfree.example.php'));
    }
    $migrations = array_filter($files, static fn(array $file): bool => str_starts_with($file['path'], 'migrations/')
        && str_ends_with(strtolower($file['path']), '.sql') && trim($file['bytes']) !== '');
    if ($migrations === []) {
        kcrm_package_fail('Application source is not ready; migrations/ contains no nonempty .sql migration.');
    }
    $deny = "# Generated source-package protection. Verify HTTP denial on the target host.\nOptions -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    foreach (KCRM_PACKAGE_PRIVATE as $private) {
        kcrm_package_add($files, $private . '/.htaccess', $deny);
    }
    $rootRules = $root . '/deploy/root.htaccess';
    if (!is_file($rootRules)) {
        kcrm_package_fail('Deployment protection template is missing: deploy/root.htaccess');
    }
    kcrm_package_add($files, '.htaccess', kcrm_package_read($root, 'deploy/root.htaccess'));
    // Ship the template too, even though arbitrary private .htaccess files are excluded.
    kcrm_package_add($files, 'deploy/root.htaccess', kcrm_package_read($root, 'deploy/root.htaccess'));
    $total = array_sum(array_map(static fn(array $file): int => strlen($file['bytes']), $files));
    if ($total > KCRM_PACKAGE_MAX_TOTAL) {
        kcrm_package_fail('Source package exceeds the uncompressed size limit.');
    }
    ksort($files, SORT_STRING);
    return ['root' => $root, 'files' => array_values($files), 'skipped' => $skipped, 'bytes' => $total,
        'empty_directories' => ['storage', 'storage/documents', 'storage/backups', 'storage/tmp']];
}

function kcrm_package_destination(string $output, string $root, bool $force): string
{
    if ($output === '' || preg_match('/[\x00-\x1f\x7f]/', $output) || strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'zip') {
        kcrm_package_fail('Output must be a .zip path.');
    }
    kcrm_package_name(basename($output));
    $normalized = str_replace('\\', '/', $output);
    if (preg_match('~(?:^|/)\.\.(?:/|$)~', $normalized)) {
        kcrm_package_fail('Output path must not contain traversal segments.');
    }
    kcrm_package_no_links($output);
    $directory = dirname($output);
    $existing = $directory;
    $suffix = [];
    while (!file_exists($existing)) {
        array_unshift($suffix, basename($existing));
        $parent = dirname($existing);
        if ($parent === $existing) {
            kcrm_package_fail('Cannot resolve package output directory.');
        }
        $existing = $parent;
    }
    $parent = realpath($existing);
    if ($parent === false) {
        kcrm_package_fail('Cannot resolve package output directory.');
    }
    kcrm_package_no_links($parent);
    foreach ($suffix as $part) {
        kcrm_package_name($part);
        $parent .= DIRECTORY_SEPARATOR . $part;
    }
    $output = $parent . DIRECTORY_SEPARATOR . basename($output);
    foreach (array_merge(KCRM_PACKAGE_ROOTS, ['config', 'storage']) as $included) {
        // This dedicated, always-excluded output tree is allowed for release
        // handoff; it is never recursively collected as deployment source.
        $artifactOutput = $included === 'deploy' && kcrm_package_below($output, $root . '/deploy/artifacts');
        if (kcrm_package_below($output, $root . '/' . $included) && !$artifactOutput) {
            kcrm_package_fail('Output must be outside all source/storage roots to prevent recursive packaging.');
        }
    }
    foreach ([$output, $output . '.sha256'] as $target) {
        if (kcrm_package_is_link($target) || (file_exists($target) && (!$force || !is_file($target)))) {
            kcrm_package_fail('Output already exists or is unsafe; choose another path or use --force for ordinary files.');
        }
    }
    if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
        kcrm_package_fail('Cannot create package output directory.');
    }
    return $output;
}

function kcrm_package_write(array $source, string $destination, bool $force = false): array
{
    if (!class_exists('ZipArchive')) {
        kcrm_package_fail('PHP ZipArchive extension is required. Enable ext/zip for CLI; no package was created.');
    }
    $destination = kcrm_package_destination($destination, $source['root'], $force);
    $metadata = ['format' => 'kosmoport-source-v1', 'created_at' => gmdate('c'), 'php_minimum' => '8.2',
        'contains_business_data' => false, 'contains_live_configuration' => false,
        'source_files' => count($source['files']), 'excluded_source_entries' => $source['skipped'],
        'deployment' => 'Preserve app/, public/, config/, migrations/ and storage/ as siblings. See deploy/README.md.',
        'checksums' => 'CHECKSUMS.sha256 covers every regular ZIP member except itself.'];
    $files = $source['files'];
    $files[] = ['path' => 'SOURCE-PACKAGE.json', 'bytes' => json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"];
    usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
    $checksums = '';
    foreach ($files as $file) {
        $checksums .= hash('sha256', $file['bytes']) . '  ' . $file['path'] . "\n";
    }
    $files[] = ['path' => 'CHECKSUMS.sha256', 'bytes' => $checksums];
    $temporary = $destination . '.part-' . bin2hex(random_bytes(8));
    $checksumTemporary = $destination . '.sha256.part-' . bin2hex(random_bytes(8));
    $zip = new ZipArchive();
    $opened = false;
    try {
        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            kcrm_package_fail('Cannot create temporary ZIP archive.');
        }
        $opened = true;
        foreach ($source['empty_directories'] as $directory) {
            if (!$zip->addEmptyDir(kcrm_package_name($directory))) {
                kcrm_package_fail('Cannot add storage scaffolding.');
            }
            $zip->setExternalAttributesName($directory . '/', ZipArchive::OPSYS_UNIX, 040750 << 16);
        }
        foreach ($files as $file) {
            if (!$zip->addFromString(kcrm_package_name($file['path']), $file['bytes'])) {
                kcrm_package_fail('Cannot write ZIP entry: ' . $file['path']);
            }
            $zip->setExternalAttributesName($file['path'], ZipArchive::OPSYS_UNIX, 0100644 << 16);
        }
        if (!$zip->close()) {
            kcrm_package_fail('Cannot finalize ZIP archive.');
        }
        $opened = false;
        if (!$force && file_exists($destination)) {
            kcrm_package_fail('Output appeared during packaging; refusing to replace it.');
        }
        if (!rename($temporary, $destination)) {
            kcrm_package_fail('Cannot promote completed ZIP archive.');
        }
        $hash = hash_file('sha256', $destination);
        if ($hash === false || file_put_contents($checksumTemporary, $hash . '  ' . basename($destination) . "\n", LOCK_EX) === false
            || !rename($checksumTemporary, $destination . '.sha256')) {
            kcrm_package_fail('ZIP created, but archive checksum sidecar could not be written.');
        }
        return ['ok' => true, 'archive' => $destination, 'sha256' => $hash, 'files' => count($files),
            'bytes' => filesize($destination), 'excluded_source_entries' => $source['skipped'], 'business_data_included' => false];
    } finally {
        if ($opened) {
            $zip->close();
        }
        if (is_file($temporary) && !kcrm_package_is_link($temporary)) {
            unlink($temporary);
        }
        if (is_file($checksumTemporary) && !kcrm_package_is_link($checksumTemporary)) {
            unlink($checksumTemporary);
        }
    }
}

function kcrm_package_main(array $arguments): int
{
    try {
        $options = ['root' => dirname(__DIR__), 'output' => null, 'force' => false, 'check' => false];
        for ($i = 1; $i < count($arguments); $i++) {
            $argument = $arguments[$i];
            if ($argument === '--help' || $argument === '-h') {
                echo "Kosmoport CRM source package (CLI only)\n\nphp tools/package.php [--root PATH] [--output FILE.zip] [--force] [--check]\n\n--check validates source without writing an archive.\nDefault output: build/kosmoport-source-UTC.zip. Secrets and business storage are excluded.\n";
                return 0;
            }
            if ($argument === '--force' || $argument === '--check') {
                $options[substr($argument, 2)] = true;
                continue;
            }
            if (preg_match('/^--(root|output)=(.+)$/D', $argument, $match)) {
                $options[$match[1]] = $match[2];
                continue;
            }
            if (($argument === '--root' || $argument === '--output') && isset($arguments[$i + 1])) {
                $options[substr($argument, 2)] = $arguments[++$i];
                continue;
            }
            kcrm_package_fail('Unknown or incomplete argument: ' . $argument);
        }
        $source = kcrm_package_collect((string)$options['root']);
        if ($options['check']) {
            echo json_encode(['ok' => true, 'mode' => 'check', 'files' => count($source['files']),
                'source_bytes' => $source['bytes'], 'excluded_source_entries' => $source['skipped'], 'archive_created' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            return 0;
        }
        $output = $options['output'] ?? $source['root'] . '/build/kosmoport-source-' . gmdate('Ymd-His') . '.zip';
        echo json_encode(kcrm_package_write($source, $output, $options['force']), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, 'Package refused: ' . $error->getMessage() . "\n");
        return 1;
    }
}

if (!defined('KCRM_PACKAGE_LIBRARY_ONLY')) {
    exit(kcrm_package_main($argv));
}
