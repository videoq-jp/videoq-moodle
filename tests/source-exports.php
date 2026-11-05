<?php
/** Root source publication must match committed packages and exclude working-tree changes. */
declare(strict_types=1);
$root = dirname(__DIR__);
$base = sys_get_temp_dir() . '/videoq-source-export-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
function export_test_command(array $args, bool $success = true): string {
    $process = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch export test');
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($process);
    if (($code === 0) !== $success) throw new RuntimeException('Unexpected export fixture result');
    return $success ? $out : $out . $error;
}
try {
    $fixture = $base . '/fixture';
    foreach (['scripts/export-plugin-sources.php', 'scripts/package-plugins.php'] as $file) {
        if (!is_dir(dirname($fixture . '/' . $file))) mkdir(dirname($fixture . '/' . $file), 0700, true);
        copy($root . '/' . $file, $fixture . '/' . $file);
    }
    foreach (['local_videoq' => 'local', 'ltisource_videoq' => 'ltisource'] as $component => $type) {
        foreach (['version.php', 'LICENSE', 'lang/en/' . $component . '.php'] as $file) {
            $target = $fixture . '/plugins/' . $type . '/videoq/' . $file;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            copy($root . '/plugins/' . $type . '/videoq/' . $file, $target);
        }
    }
    mkdir($fixture . '/plugins/local/videoq/lang/ja');
    file_put_contents($fixture . '/plugins/local/videoq/lang/ja/local_videoq.php', "<?php\n");
    export_test_command(['git', 'init', '--initial-branch=main', $fixture]);
    export_test_command(['git', '-C', $fixture, 'add', '--all']);
    $commit = ['git', '-C', $fixture, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid',
        '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '-m', 'Committed source fixture'];
    export_test_command($commit);
    $revision = trim(export_test_command(['git', '-C', $fixture, 'rev-parse', 'HEAD']));
    $original = file_get_contents($fixture . '/plugins/local/videoq/version.php');
    file_put_contents($fixture . '/plugins/local/videoq/version.php', 'uncommitted source must not be published');
    file_put_contents($fixture . '/plugins/local/videoq/untracked.php', 'untracked source must not be published');
    file_put_contents($fixture . '/scripts/package-plugins.php', '<?php throw new RuntimeException("dirty builder must not run");');
    $builder = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $fixture . '/scripts/export-plugin-sources.php'];
    $reference = null;
    foreach (['first', 'repeat'] as $name) {
        $destination = $base . '/' . $name;
        export_test_command([...$builder, $destination, $revision]);
        $report = json_decode(file_get_contents($destination . '/source-exports.json'), true, 32, JSON_THROW_ON_ERROR);
        if ($report['status'] !== 'local-only-no-remotes' || $report['sourceRevision'] !== $revision) throw new RuntimeException('Wrong source provenance');
        $identities = [];
        foreach ($report['components'] as $component => $data) {
            $directory = $destination . '/' . $data['directory'];
            if (!is_file($directory . '/version.php') || is_dir($directory . '/plugins') || is_dir($directory . '/lang/ja')
                    || is_file($directory . '/untracked.php') || count($data['files']) !== 3) throw new RuntimeException('Root export content is incorrect');
            if ($component === 'local_videoq' && file_get_contents($directory . '/version.php') !== $original) throw new RuntimeException('Working-tree source leaked into export');
            if (trim(export_test_command(['git', '-C', $directory, 'remote'])) !== '') throw new RuntimeException('Export unexpectedly has a remote');
            $zip = new ZipArchive(); $zip->open($destination . '/archives/' . $component . '.zip');
            foreach ($data['files'] as $file => $hash) {
                $blob = export_test_command(['git', '-C', $directory, 'show', 'HEAD:' . $file]);
                if ($blob !== $zip->getFromName('videoq/' . $file) || hash('sha256', $blob) !== $hash) throw new RuntimeException('Committed export differs from package');
            }
            $zip->close();
            $identities[$component] = [$data['commit'], $data['tree'], $data['archiveSha256']];
        }
        if ($reference !== null && $reference !== $identities) throw new RuntimeException('Export commits/packages are not reproducible');
        $reference = $identities;
    }
    $error = export_test_command([...$builder, $base . '/first'], false);
    if (!str_contains($error, 'Output must be empty')) throw new RuntimeException('Existing output was not refused');
    $link = $fixture . '/plugins/local/videoq/leak.php';
    symlink($base . '/first/source-exports.json', $link);
    export_test_command(['git', '-C', $fixture, 'add', '--', 'plugins/local/videoq/leak.php']);
    export_test_command($commit);
    $error = export_test_command([...$builder, $base . '/symlink'], false);
    if (!str_contains($error, 'Only regular source files')) throw new RuntimeException('Source symlink was not refused');
    echo "Root exports match committed ZIPs, reproduce, exclude dirty/untracked source and reject symlinks/nonempty targets.\n";
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($base);
}
