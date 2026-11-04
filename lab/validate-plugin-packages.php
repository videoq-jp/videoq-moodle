<?php
// Validate archive layout, metadata, language and Moodle-version compatibility without
// replacing the read-only live plugin mounts. Target-location checks use scratch roots.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Lab only');
class videoq_archive_validator extends \core\update\validator {
    public function get_plugintype_location($plugintype) {
        $root = make_request_directory();
        return $root;
    }
}
$results = [];
foreach (['local_videoq' => 'local', 'ltisource_videoq' => 'ltisource'] as $component => $type) {
    $temp = make_request_directory();
    $files = core_plugin_manager::instance()->unzip_plugin_file('/tmp/videoq-plugin-packages/' . $component . '.zip', $temp, 'videoq');
    if (!$files) throw new Exception('Cannot extract plugin package');
    $validator = videoq_archive_validator::instance($temp, $files);
    $validator->assert_plugin_type($type);
    $validator->assert_moodle_version($CFG->version);
    $passed = $validator->execute();
    $results[$component] = ['passed' => $passed, 'fileCount' => count($files), 'messages' => $validator->get_messages()];
    if (!$passed) { echo json_encode($results, JSON_PRETTY_PRINT); exit(1); }
}
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
