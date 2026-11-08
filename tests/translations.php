<?php
/** Verify real installer profiles, AMOS exports and rejection of unsafe/incorrect language data. */
declare(strict_types=1);
$root = dirname(__DIR__);
$base = sys_get_temp_dir() . '/videoq-translations-' . bin2hex(random_bytes(12));
mkdir($base, 0700);
function videoq_translation_test_run(array $args, ?array $env = null): array {
    $process = proc_open([PHP_BINARY, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($process)) throw new RuntimeException('Cannot run translation fixture');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); return [proc_close($process), $output];
}
try {
    foreach (['development' => [], 'marketplace' => ['--marketplace']] as $profile => $flags) {
        [$code, $output] = videoq_translation_test_run([$root . '/scripts/package-plugins.php', $base . '/' . $profile, ...$flags]);
        if ($code !== 0) throw new RuntimeException('Installer profile failed');
    }
    foreach (['local_videoq', 'ltisource_videoq'] as $component) {
        $dev = new ZipArchive(); $official = new ZipArchive();
        $dev->open($base . '/development/' . $component . '.zip');
        $official->open($base . '/marketplace/' . $component . '.zip');
        if ($official->locateName('videoq/lang/en/' . $component . '.php') === false) throw new RuntimeException('English missing');
        for ($index = 0; $index < $official->numFiles; $index++) {
            $name = $official->getNameIndex($index);
            if (str_starts_with($name, 'videoq/lang/') && !str_starts_with($name, 'videoq/lang/en/')) {
                throw new RuntimeException('Official archive contains non-English language files');
            }
            if ($official->getFromName($name) !== $dev->getFromName($name)) {
                throw new RuntimeException('Installer profile modified runtime content');
            }
        }
        if ($component === 'local_videoq' && $dev->locateName('videoq/lang/ja/local_videoq.php') === false) {
            throw new RuntimeException('Development Japanese UI was lost');
        }
        $dev->close(); $official->close();
    }
    [$code, $output] = videoq_translation_test_run([$root . '/scripts/package-translations.php', $base . '/amos']);
    if ($code !== 0) throw new RuntimeException('AMOS export failed');
    $report = json_decode(file_get_contents($base . '/amos/amos-ja.json'), true, 16, JSON_THROW_ON_ERROR);
    if ($report['status'] !== 'prepared-not-submitted' || count($report['components']) !== 2) {
        throw new RuntimeException('AMOS status/components incorrect');
    }
    if ($report['archiveSha256'] !== hash_file('sha256', $base . '/amos/amos-ja.zip')) throw new RuntimeException('AMOS checksum mismatch');
    $zip = new ZipArchive(); $zip->open($base . '/amos/amos-ja.zip');
    if ($zip->numFiles !== 2 || $zip->locateName('local_videoq.php') === false || $zip->locateName('ltisource_videoq.php') === false) {
        throw new RuntimeException('AMOS requires a flat component-named import');
    }
    if ($zip->getFromName('local_videoq.php') !== file_get_contents($root . '/plugins/local/videoq/lang/ja/local_videoq.php')
            || $zip->getFromName('ltisource_videoq.php') !== file_get_contents($root . '/translations/ja/ltisource_videoq.php')) {
        throw new RuntimeException('AMOS import must preserve reviewed Japanese source values');
    }
    $zip->close();
    [$code] = videoq_translation_test_run([$root . '/scripts/package-translations.php', $base . '/amos-repeat']);
    if ($code !== 0 || hash_file('sha256', $base . '/amos/amos-ja.zip') !== hash_file('sha256', $base . '/amos-repeat/amos-ja.zip')) {
        throw new RuntimeException('AMOS export is not reproducible');
    }
    $fixture = $base . '/fixture';
    foreach (['scripts/package-translations.php', 'scripts/package-plugins.php',
            'plugins/local/videoq/version.php', 'plugins/local/videoq/LICENSE',
            'plugins/ltisource/videoq/version.php', 'plugins/ltisource/videoq/LICENSE', 'plugins/local/videoq/lang/en/local_videoq.php',
            'plugins/local/videoq/lang/ja/local_videoq.php', 'plugins/ltisource/videoq/lang/en/ltisource_videoq.php',
            'translations/ja/ltisource_videoq.php'] as $name) {
        $target = $fixture . '/' . $name;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        copy($root . '/' . $name, $target);
    }
    $reference = null;
    foreach (['Pacific/Auckland' => 0600, 'America/Los_Angeles' => 0664, 'UTC' => 0644] as $timezone => $mode) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) if ($item->isFile()) chmod($item->getPathname(), $mode);
        $directory = $base . '/portable-' . str_replace('/', '-', $timezone);
        [$code] = videoq_translation_test_run([$fixture . '/scripts/package-plugins.php', $directory, '--marketplace'], ['TZ' => $timezone]);
        if ($code !== 0) throw new RuntimeException('Portable plugin build failed');
        [$code] = videoq_translation_test_run([$fixture . '/scripts/package-translations.php', $directory . '/amos'], ['TZ' => $timezone]);
        if ($code !== 0) throw new RuntimeException('Portable AMOS build failed');
        $hashes = array_map(static fn($name) => hash_file('sha256', $directory . '/' . $name),
            ['local_videoq.zip', 'ltisource_videoq.zip', 'amos/amos-ja.zip']);
        if ($reference !== null && $reference !== $hashes) throw new RuntimeException('Archives vary with source permissions or process timezone');
        $reference = $hashes;
    }
    $japanese = $fixture . '/plugins/local/videoq/lang/ja/local_videoq.php';
    $original = file_get_contents($japanese);
    file_put_contents($japanese, str_replace("'へ移動'", "'へ移動 {\$a->unexpected}'", $original));
    [$code, $output] = videoq_translation_test_run([$fixture . '/scripts/package-translations.php', $base . '/bad-placeholder']);
    if ($code === 0 || !str_contains($output, 'placeholders differ') || file_exists($base . '/bad-placeholder/amos-ja.zip')) {
        throw new RuntimeException('Incorrect placeholders were accepted');
    }
    $marker = $base . '/executed';
    file_put_contents($japanese, $original . "\nfile_put_contents(" . var_export($marker, true) . ', "unexpected");');
    [$code, $output] = videoq_translation_test_run([$fixture . '/scripts/package-translations.php', $base . '/bad-code']);
    if ($code === 0 || file_exists($marker) || file_exists($base . '/bad-code/amos-ja.zip')
            || !preg_match('/data-only|literal string assignments/', $output)) {
        throw new RuntimeException('Executable language content was not rejected before evaluation');
    }
    echo "Installer/AMOS profiles reproduce across timezones and permissions; invalid placeholders/code rejected.\n";
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($base);
}
