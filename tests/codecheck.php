<?php
/** Exercise the actual development runner's failure boundaries without network access. */
declare(strict_types=1);
$root = dirname(__DIR__);
$base = sys_get_temp_dir() . '/videoq-codecheck-test-' . bin2hex(random_bytes(12));
mkdir($base, 0700);
function videoq_check_test_run(string $root, string $mode, string $work): array {
    $process = proc_open([PHP_BINARY, $root . '/scripts/check-moodle-code.php', $mode, $work],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot run development tool fixture');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $output];
}
try {
    $unrelated = $base . '/unrelated'; mkdir($unrelated, 0700);
    file_put_contents($unrelated . '/keep.txt', 'keep this content');
    [$code, $output] = videoq_check_test_run($root, 'prepare', $unrelated);
    if ($code === 0 || !str_contains($output, 'unrelated nonempty work directory')
            || file_get_contents($unrelated . '/keep.txt') !== 'keep this content'
            || file_exists($unrelated . '/composer.json')) {
        throw new RuntimeException('Unrelated work directory was not preserved');
    }
    $corrupt = $base . '/corrupt'; mkdir($corrupt, 0700);
    file_put_contents($corrupt . '/.videoq-codecheck', 'videoq-moodle-codecheck-v1');
    file_put_contents($corrupt . '/composer.phar', '<?php file_put_contents(' . var_export($corrupt . '/executed', true) . ', "unexpected");');
    [$code, $output] = videoq_check_test_run($root, 'prepare', $corrupt);
    if ($code === 0 || !str_contains($output, 'Cached Composer checksum mismatch') || file_exists($corrupt . '/executed')) {
        throw new RuntimeException('Corrupted tool must be rejected before execution');
    }
    $drift = $base . '/drift'; mkdir($drift, 0700);
    file_put_contents($drift . '/.videoq-codecheck', 'videoq-moodle-codecheck-v1');
    copy($root . '/.github/moodle-codecheck/composer.json', $drift . '/composer.json');
    file_put_contents($drift . '/composer.lock', 'different dependency graph');
    [$code, $output] = videoq_check_test_run($root, 'scan', $drift);
    if ($code === 0 || !str_contains($output, 'Tool manifests changed')) {
        throw new RuntimeException('Offline scan must reject stale tools');
    }
    echo "Codecheck runner rejects unrelated directories, corrupted binaries and stale manifests offline.\n";
} finally {
    foreach (glob($base . '/*') as $directory) {
        foreach (scandir($directory) as $name) {
            if (!in_array($name, ['.', '..'], true)) unlink($directory . '/' . $name);
        }
        rmdir($directory);
    }
    rmdir($base);
}
