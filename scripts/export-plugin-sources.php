<?php
/** Prepare local, root-layout Git repositories matching committed Marketplace ZIP contents. No remote operations. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !class_exists(ZipArchive::class)) throw new RuntimeException('PHP CLI with ext-zip required');
$root = dirname(__DIR__);
$requested = $argv[1] ?? '';
if (!$requested || !str_starts_with($requested, '/') || is_link($requested)) throw new RuntimeException('Use a fresh absolute output directory');
if (!is_dir($requested) && !mkdir($requested, 0700, true)) throw new RuntimeException('Cannot create output directory');
$output = realpath($requested);
if (!$output || $output === $root || str_starts_with($output, $root . '/') || iterator_count(new FilesystemIterator($output)) !== 0) {
    throw new RuntimeException('Output must be empty and outside the checkout');
}
chmod($output, 0700);
function source_command(array $args, ?array $env = null): string {
    $process = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch source export command');
    $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Source export command failed: ' . basename($args[0]));
    return $text;
}
function source_path(string $path): void {
    if (!$path || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
        throw new RuntimeException('Unsafe source path');
    }
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || strtolower($part) === '.git') throw new RuntimeException('Unsafe source segment');
    }
}
$revision = trim(source_command(['git', '-C', $root, 'rev-parse', '--verify', '--end-of-options', ($argv[2] ?? 'HEAD') . '^{commit}']));
if (!preg_match('/^[a-f0-9]{40}$/D', $revision)) throw new RuntimeException('Expected a full source commit');
$date = trim(source_command(['git', '-C', $root, 'show', '-s', '--format=%cI', $revision]));
$environment = array_replace(getenv(), ['GIT_AUTHOR_NAME' => 'VideoQ source export', 'GIT_AUTHOR_EMAIL' => 'source-export@videoq.invalid',
    'GIT_COMMITTER_NAME' => 'VideoQ source export', 'GIT_COMMITTER_EMAIL' => 'source-export@videoq.invalid',
    'GIT_AUTHOR_DATE' => $date, 'GIT_COMMITTER_DATE' => $date]);
$snapshot = $output . '/.snapshot';
mkdir($snapshot . '/scripts', 0700, true);
// Use the committed HEAD packager with selected committed plugin blobs, never dirty build scripts.
$builderrevision = trim(source_command(['git', '-C', $root, 'rev-parse', 'HEAD']));
$builder = source_command(['git', '-C', $root, 'show', $builderrevision . ':scripts/package-plugins.php']);
if (file_put_contents($snapshot . '/scripts/package-plugins.php', $builder) !== strlen($builder)) throw new RuntimeException('Cannot write committed builder');
$components = ['local_videoq' => 'plugins/local/videoq'];
foreach ($components as $component => $prefix) {
    $entries = source_command(['git', '-C', $root, 'ls-tree', '-r', '-z', $revision . ':' . $prefix]);
    if ($entries === '') throw new RuntimeException('Missing committed plugin tree');
    foreach (explode("\0", rtrim($entries, "\0")) as $entry) {
        if (!preg_match('/^(100644|100755) blob ([a-f0-9]{40})\t(.+)$/D', $entry, $match)) throw new RuntimeException('Only regular source files can be exported');
        $name = $match[3]; source_path($name);
        $destination = $snapshot . '/' . $prefix . '/' . $name;
        if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0700, true);
        $bytes = source_command(['git', '-C', $root, 'cat-file', 'blob', $match[2]]);
        if (file_put_contents($destination, $bytes) !== strlen($bytes)) throw new RuntimeException('Cannot write committed source');
        chmod($destination, 0644);
    }
}
source_command([PHP_BINARY, $snapshot . '/scripts/package-plugins.php', $output . '/archives', '--marketplace']);
$report = ['status' => 'local-only-no-remotes', 'sourceRepository' => 'https://github.com/videoq-jp/videoq-moodle',
    'sourceRevision' => $revision, 'builderRevision' => $builderrevision, 'builderSha256' => hash('sha256', $builder), 'components' => []];
foreach ($components as $component => $prefix) {
    $name = 'moodle-' . $component;
    $directory = $output . '/' . $name;
    mkdir($directory, 0700);
    $archive = $output . '/archives/' . $component . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) throw new RuntimeException('Cannot open source package');
    $files = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entry = $zip->getNameIndex($index);
        if (!str_starts_with($entry, 'videoq/')) throw new RuntimeException('Unexpected package root');
        $relative = substr($entry, 7); source_path($relative);
        $bytes = $zip->getFromIndex($index);
        if ($bytes === false) throw new RuntimeException('Cannot read source package entry');
        $destination = $directory . '/' . $relative;
        if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0755, true);
        if (file_put_contents($destination, $bytes) !== strlen($bytes)) throw new RuntimeException('Cannot write repository source');
        chmod($destination, 0644);
        $files[$relative] = hash('sha256', $bytes);
    }
    $zip->close();
    foreach (['version.php', 'LICENSE', 'lang/en/' . $component . '.php'] as $required) {
        if (!isset($files[$required])) throw new RuntimeException('Missing root repository metadata');
    }
    source_command(['git', 'init', '--initial-branch=main', $directory]);
    source_command(['git', '-C', $directory, 'add', '--all']);
    source_command(['git', '-C', $directory, '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null',
        'commit', '-m', 'Export ' . $component . ' from videoq-jp/videoq-moodle@' . $revision], $environment);
    if (trim(source_command(['git', '-C', $directory, 'status', '--porcelain'])) !== ''
            || trim(source_command(['git', '-C', $directory, 'remote'])) !== '') {
        throw new RuntimeException('Export must be clean and have no remotes');
    }
    foreach ($files as $relative => $expected) {
        $committed = source_command(['git', '-C', $directory, 'show', 'HEAD:' . $relative]);
        if (hash('sha256', $committed) !== $expected) throw new RuntimeException('Git filtering changed exported content');
    }
    $report['components'][$component] = ['directory' => $name, 'commit' => trim(source_command(['git', '-C', $directory, 'rev-parse', 'HEAD'])),
        'tree' => trim(source_command(['git', '-C', $directory, 'rev-parse', 'HEAD^{tree}'])),
        'archiveSha256' => hash_file('sha256', $archive), 'files' => $files];
}
$manifest = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($output . '/source-exports.json', $manifest) !== strlen($manifest)) throw new RuntimeException('Cannot write source export manifest');
echo json_encode(['status' => $report['status'], 'sourceRevision' => $revision,
    'components' => array_map(static fn($component) => ['directory' => $component['directory'], 'files' => count($component['files']),
        'archiveSha256' => $component['archiveSha256']], $report['components'])], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
