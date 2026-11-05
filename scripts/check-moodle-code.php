<?php
/** Prepare locked PHP development tools, then run the official Moodle standard offline. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 3 || !in_array($argv[1], ['prepare', 'scan'], true)) {
    throw new InvalidArgumentException('Usage: php scripts/check-moodle-code.php prepare|scan EXTERNAL_WORK_DIRECTORY');
}
$root = dirname(__DIR__);
$mode = $argv[1];
if (is_link($argv[2])) throw new RuntimeException('Work directory must not be a symlink');
if (!is_dir($argv[2]) && ($mode !== 'prepare' || !mkdir($argv[2], 0700, true))) {
    throw new RuntimeException('Work directory is unavailable');
}
$work = realpath($argv[2]);
if (!$work || $work === $root || str_starts_with($work . '/', $root . '/')) {
    throw new RuntimeException('Development tools must remain outside the repository');
}
$marker = $work . '/.videoq-codecheck';
if (!is_file($marker)) {
    if ($mode !== 'prepare' || array_diff(scandir($work), ['.', '..'])) {
        throw new RuntimeException('Refusing to use an unrelated nonempty work directory');
    }
    file_put_contents($marker, 'videoq-moodle-codecheck-v1');
    chmod($marker, 0600);
} else if (is_link($marker) || file_get_contents($marker) !== 'videoq-moodle-codecheck-v1') {
    throw new RuntimeException('Invalid codecheck work directory');
}
$manifest = $root . '/.github/moodle-codecheck/';
$toolchain = json_decode(file_get_contents($manifest . 'toolchain.json'), true, 16, JSON_THROW_ON_ERROR);
$version = $toolchain['composerVersion'] ?? '';
$checksum = $toolchain['composerSha256'] ?? '';
if (!preg_match('/^\d+\.\d+\.\d+$/D', $version) || !preg_match('/^[a-f0-9]{64}$/D', $checksum)) {
    throw new RuntimeException('Invalid Composer identity');
}
function videoq_codecheck_run(array $command, string $directory): int {
    $environment = getenv();
    $environment['COMPOSER_HOME'] = $directory . '/composer-home';
    $environment['COMPOSER_AUTH'] = '{}';
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory, $environment);
    if (!is_resource($process)) throw new RuntimeException('Cannot start PHP codecheck tool');
    return proc_close($process);
}
if ($mode === 'prepare') {
    foreach (['composer.json', 'composer.lock'] as $name) {
        if (is_link($work . '/' . $name)) throw new RuntimeException('Unexpected manifest symlink');
        if (!copy($manifest . $name, $work . '/' . $name)) throw new RuntimeException('Cannot copy tool manifest');
    }
    $composer = $work . '/composer.phar';
    if (is_link($composer)) throw new RuntimeException('Unexpected Composer symlink');
    if (!is_file($composer)) {
        $context = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $binary = file_get_contents('https://getcomposer.org/download/' . $version . '/composer.phar', false, $context, 0, 8388608);
        if ($binary === false || !hash_equals($checksum, hash('sha256', $binary))) {
            throw new RuntimeException('Composer download failed integrity verification');
        }
        if (file_put_contents($composer, $binary, LOCK_EX) !== strlen($binary)) {
            throw new RuntimeException('Cannot store Composer');
        }
        chmod($composer, 0600);
    }
    if (!hash_equals($checksum, hash_file('sha256', $composer))) {
        throw new RuntimeException('Cached Composer checksum mismatch; refusing execution');
    }
    exit(videoq_codecheck_run([PHP_BINARY, $composer, 'install', '--no-dev', '--no-plugins', '--no-scripts',
        '--no-interaction', '--no-progress', '--prefer-dist'], $work));
}
foreach (['composer.json', 'composer.lock'] as $name) {
    if (!is_file($work . '/' . $name) || !hash_equals(hash_file('sha256', $manifest . $name), hash_file('sha256', $work . '/' . $name))) {
        throw new RuntimeException('Tool manifests changed; prepare the isolated tools again');
    }
}
$scanner = $work . '/vendor/bin/phpcs';
if (!is_file($scanner)) throw new RuntimeException('Run prepare before the offline scan');
$code = videoq_codecheck_run([PHP_BINARY, $scanner, '--runtime-set', 'installed_paths',
    $work . '/vendor/phpcsstandards/phpcsextra,' . $work . '/vendor/moodlehq/moodle-cs',
    '--standard=' . $work . '/vendor/moodlehq/moodle-cs/moodle', '--report=json',
    '--report-file=' . $work . '/phpcs.json', $root . '/plugins/local/videoq', $root . '/plugins/ltisource/videoq'], $work);
if (!is_file($work . '/phpcs.json')) throw new RuntimeException('Scanner did not produce a report');
$report = json_decode(file_get_contents($work . '/phpcs.json'), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(['moodleCodingStandard' => $report['totals']], JSON_THROW_ON_ERROR) . "\n";
if ($code !== 0 || $report['totals']['errors'] !== 0 || $report['totals']['warnings'] !== 0) exit(1);
