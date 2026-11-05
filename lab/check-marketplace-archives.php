<?php
/** Run Moodle's web-installer ZIP validator against isolated scratch destinations. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
if (($argv[1] ?? '') !== '/site') throw new RuntimeException('Use the isolated /site mount');
require '/site/config.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbhost !== 'audit-db'
        || !in_array($CFG->dbname, ['moodleaudit', 'moodleaudit_upgrade'], true)) {
    throw new RuntimeException('Refusing to use an unrelated Moodle site');
}
class videoq_marketplace_zip_validator extends \core\update\validator {
    public function get_plugintype_location($plugintype) {
        return make_request_directory();
    }
}
$directory = $argv[2] ?? '/audit/marketplace-archives';
$report = ['validation' => 'Moodle web-installer ZIP validator; scratch destination only', 'components' => []];
foreach (['local_videoq' => 'local', 'ltisource_videoq' => 'ltisource'] as $component => $type) {
    $archive = $directory . '/' . $component . '.zip';
    $scratch = make_request_directory();
    $files = core_plugin_manager::instance()->unzip_plugin_file($archive, $scratch, 'videoq');
    if (!$files) throw new RuntimeException('Cannot unpack component archive');
    $validator = videoq_marketplace_zip_validator::instance($scratch, $files);
    $validator->assert_plugin_type($type);
    $validator->assert_moodle_version($CFG->version);
    $passed = $validator->execute();
    $messages = $validator->get_messages();
    $report['components'][$component] = ['passed' => $passed, 'archiveSha256' => hash_file('sha256', $archive),
        'fileCount' => count($files), 'messages' => $messages];
    if (!$passed) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        exit(1);
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
