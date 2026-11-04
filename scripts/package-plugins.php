<?php
/** Build standard Moodle installers with PHP + ext-zip only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Usage: php scripts/package-plugins.php OUTPUT_DIRECTORY (PHP with ext-zip required)\n"); exit(1);
}
$root = dirname(__DIR__);
$destination = $argv[1];
if (!is_dir($destination) && !mkdir($destination, 0755, true)) throw new RuntimeException('Cannot create output directory');
$destination = realpath($destination);
$plugins = ['local_videoq' => 'plugins/local/videoq', 'ltisource_videoq' => 'plugins/ltisource/videoq'];
$checksums = [];
foreach ($plugins as $component => $relative) {
    $base = $root . '/' . $relative;
    if (is_link($base) || !is_dir($base)) throw new RuntimeException('Invalid plugin directory');
    if ($destination === realpath($base) || str_starts_with($destination . '/', realpath($base) . '/')) throw new RuntimeException('Output must be outside plugin sources');
    $files = [];
    $walk = function(string $directory, string $prefix = '') use (&$walk, &$files): void {
        foreach (scandir($directory) as $name) {
            if (str_starts_with($name, '.')) continue;
            $path = $directory . '/' . $name;
            if (is_link($path)) throw new RuntimeException('Plugin symlinks are not distributable');
            if (is_dir($path)) $walk($path, $prefix . $name . '/');
            else if (is_file($path) && ($name === 'LICENSE' || in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['php','js','css','mustache','md','xml'], true))) {
                $files['videoq/' . $prefix . $name] = $path;
            }
        }
    };
    $walk($base); ksort($files);
    foreach (['version.php', 'LICENSE', 'lang/en/' . $component . '.php'] as $required) {
        if (!isset($files['videoq/' . $required])) throw new RuntimeException('Missing plugin metadata');
    }
    if (!str_contains(file_get_contents($base . '/version.php'), '$plugin->component = \'' . $component . '\'')) throw new RuntimeException('Wrong Moodle component');
    $temporary = tempnam($destination, '.videoq-package-');
    $archive = new ZipArchive();
    try {
        if ($archive->open($temporary, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot open ZIP');
        foreach ($files as $name => $path) {
            if (!$archive->addFile($path, $name) || !$archive->setMtimeName($name, 315532800)) throw new RuntimeException('Cannot add plugin file');
        }
        if (!$archive->close()) throw new RuntimeException('Cannot finish ZIP');
        $name = $component . '.zip';
        if (!rename($temporary, $destination . '/' . $name)) throw new RuntimeException('Cannot publish ZIP');
        chmod($destination . '/' . $name, 0644);
        $checksums[] = hash_file('sha256', $destination . '/' . $name) . '  ' . $name;
        echo $name . "\n";
    } finally { if (is_file($temporary)) unlink($temporary); }
}
$temporary = tempnam($destination, '.videoq-checksums-');
try {
    $contents = implode("\n", $checksums) . "\n";
    if (file_put_contents($temporary, $contents) !== strlen($contents) || !rename($temporary, $destination . '/SHA256SUMS')) {
        throw new RuntimeException('Cannot publish installer checksums');
    }
    chmod($destination . '/SHA256SUMS', 0644);
} finally { if (is_file($temporary)) unlink($temporary); }
