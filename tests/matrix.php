<?php
/** Refuse unsafe lifecycle targets before any Docker or source preparation can run. */
declare(strict_types=1);
require dirname(__DIR__) . '/lab/support/matrix-diagnostics.php';
$private = 'fixture-password-and-signed-url-secret';
$summary = matrix_diagnostic_locations('PHP Warning: ' . $private . ' in /site/public/course/lib.php on line 123'
    . "\n* line 456 of /public/lib/private-directory-" . $private . '/course.php'
    . "\n" . $private . ' https://storage.example/secret?signature=' . $private
    . "\nFatal error: " . $private . ' in /src/lab/probe.php:789'
    . "\n#0 /src/lab/probe.php(812): call() " . $private);
if ($summary !== 'lib.php:123, course.php:456, probe.php:789, probe.php:812' || str_contains($summary, $private)) {
    throw new RuntimeException('Matrix diagnostics exposed fixture data or lost source locations');
}
$native = matrix_diagnostic_locations('VIDEOQ_NATIVE_PAGE_FAILURE ' . json_encode([
    'kind' => 'TypeError', 'source' => 'view.php', 'line' => 67, 'code' => 'codingerror', 'proof' => $private]));
if ($native !== 'native-page-TypeError, view.php:67, codingerror' || str_contains($native, $private)) {
    throw new RuntimeException('Native page failure metadata was lost or exposed private fields');
}
if (matrix_diagnostic_locations('VIDEOQ_NATIVE_PAGE_FAILURE ' . json_encode([
    'kind' => $private, 'source' => $private, 'line' => -1, 'code' => $private])) !== '') {
    throw new RuntimeException('Unknown native page diagnostic fields were published');
}
foreach (["++ " . $private . " ++\n* line 123 of /site/public/lib.php",
    "++ Multiline Moodle diagnostic\ncontinued ++\n* line 123 of /site/public/lib.php", 'Debugging: ' . $private,
    '<div class="notifytiny debuggingmessage">' . $private . '</div>', 'PHP Warning: ' . $private] as $diagnostic) {
    if (!matrix_has_diagnostics($diagnostic)) throw new RuntimeException('Moodle developer diagnostic was not recognized');
}
foreach (["Backup completed\nRestore completed\n", "++ Success (0.25 seconds) ++\n",
    '{"passed":true,"checks":["private data omitted"]}'] as $progress) {
    if (matrix_has_diagnostics($progress)) throw new RuntimeException('Normal progress or JSON was classified as a diagnostic');
}
$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/videoq-matrix-guard-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$marker = $temporary . '/.existing-data';
file_put_contents($marker, 'preserve this unrelated data');
$symlink = $temporary . '-link';
function matrix_reject(array $args, string $expected): void {
    global $root;
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $root . '/lab/check-marketplace-matrix.php', ...$args],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch guard check');
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) === 0 || !str_contains($error, $expected) || $output !== '') {
        throw new RuntimeException('Unsafe matrix target was not rejected before orchestration');
    }
}
try {
    matrix_reject(['pg', $temporary], 'Work directory must be empty and outside the checkout');
    matrix_reject(['mysql', $root], 'Work directory must be empty and outside the checkout');
    matrix_reject(['other', $temporary . '/new'], 'Expected pg or mysql');
    if (is_dir($temporary . '/new')) throw new RuntimeException('Invalid mode created a work directory');
    if (!symlink($temporary, $symlink)) throw new RuntimeException('Cannot create guard fixture symlink');
    matrix_reject(['pg', $symlink], 'Use an absolute external work directory');
    if (file_get_contents($marker) !== 'preserve this unrelated data' || iterator_count(new FilesystemIterator($temporary)) !== 1) {
        throw new RuntimeException('Guard changed unrelated existing data');
    }
    echo "Matrix rejects nonempty/checkout/symlink targets and invalid modes before orchestration.\n";
} finally {
    if (is_link($symlink)) unlink($symlink);
    unlink($marker); rmdir($temporary);
}
