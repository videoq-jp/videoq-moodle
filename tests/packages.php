<?php
/** Installer contract, runnable with PHP alone and no repository-local packages. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !class_exists(ZipArchive::class)) throw new RuntimeException('PHP CLI with ext-zip required');
$root = dirname(__DIR__);
$output = sys_get_temp_dir() . '/videoq-packages-' . bin2hex(random_bytes(12));
mkdir($output, 0700);
function verify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
try {
    file_put_contents($output . '/SHA256SUMS', 'stale checksums');
    file_put_contents($output . '/ltisource_videoq.zip', 'retired installer');
    chmod($output . '/SHA256SUMS', 0444); // Rebuilds must replace an old read-only manifest atomically.
    // Run outside the checkout; the child receives no Node executable or package path.
    $process = proc_open([PHP_BINARY, $root . '/scripts/package-plugins.php', $output], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, sys_get_temp_dir(), ['PATH'=>'/no-node-required']);
    verify(is_resource($process), 'Cannot start PHP builder'); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    verify(proc_close($process) === 0, 'Builder failed: ' . $stderr);
    foreach (['local_videoq'] as $component) {
        $zip = new ZipArchive(); verify($zip->open($output . '/' . $component . '.zip') === true, 'Cannot read installer');
        verify(str_contains($zip->getFromName('videoq/version.php'), '$plugin->component = \'' . $component . '\''), 'Wrong installer root/component');
        verify($zip->locateName('videoq/lang/en/' . $component . '.php') !== false, 'Missing language file');
        verify(str_contains($zip->getFromName('videoq/LICENSE'), 'GNU GENERAL PUBLIC LICENSE'), 'Missing license');
        for ($index=0; $index<$zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            verify(str_starts_with($name, 'videoq/') && !str_contains($name, '../'), 'Unsafe archive path');
            verify(!preg_match('/(?:node_modules|api\.env|compose\.env|\.mp4|\.pem|credential)/', $name), 'Non-plugin content in installer');
            if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['php', 'js', 'css', 'mustache'], true)) {
                $contents = $zip->getFromIndex($index);
                verify(str_contains($contents, '@copyright') && (str_contains($contents, 'GNU GPL')
                    || str_contains($contents, 'SPDX-License-Identifier: GPL-3.0-or-later')), 'Missing source copyright/license: ' . $name);
            }
        }
        if ($component === 'local_videoq') foreach (['connect.php','classes/lti_connection_setup.php','classes/browser_connection.php',
            'templates/workspace.mustache', 'templates/chat.mustache', 'player.js', 'learning.js',
            'classes/native_context.php', 'classes/native_api.php', 'classes/output/workspace.php', 'classes/ui_strings.php',
            'classes/external/request_learning.php', 'db/services.php', 'stream.php',
            'templates/personal_history.mustache', 'templates/management.mustache',
            'templates/reports.mustache', 'templates/qa.mustache', 'templates/tags.mustache', 'templates/transcript.mustache',
            'backup/moodle2/backup_local_videoq_plugin.class.php', 'backup/moodle2/restore_local_videoq_plugin.class.php',
            'classes/restoration.php', 'classes/task/restore_material.php'] as $file) {
            verify($zip->locateName('videoq/' . $file) !== false, 'Missing runtime asset: ' . $file);
        }
        $zip->close();
    }
    verify(!file_exists($output . '/ltisource_videoq.zip'), 'Retired installer must not remain in output');
    $lines = file($output . '/SHA256SUMS', FILE_IGNORE_NEW_LINES);
    verify(count($lines) === 1, 'Missing installer checksums');
    foreach ($lines as $line) { [$hash,$name] = explode('  ', $line); verify(hash_equals($hash, hash_file('sha256', $output . '/' . $name)), 'Wrong installer checksum'); }
    $blocked = $output . '/blocked'; mkdir($blocked); mkdir($blocked . '/SHA256SUMS');
    try {
        $process = proc_open([PHP_BINARY, $root . '/scripts/package-plugins.php', $blocked], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        verify(proc_close($process) !== 0, 'Failed checksum publication must fail the build');
    } finally {
        foreach (scandir($blocked) as $name) if (is_file($blocked . '/' . $name)) unlink($blocked . '/' . $name);
        rmdir($blocked . '/SHA256SUMS'); rmdir($blocked);
    }
    echo "PHP-only installer contract passed for the single local plugin.\n";
} finally {
    foreach (scandir($output) as $name) if ($name !== '.' && $name !== '..') unlink($output . '/' . $name);
    rmdir($output);
}
