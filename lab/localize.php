<?php
// Optional Japanese UI for the isolated demonstration; not a production configuration.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') {
    throw new Exception('Use only the isolated LTI lab Moodle');
}
if (!empty($argv[1])) {
    $zip = new ZipArchive();
    if ($zip->open($argv[1]) !== true) throw new Exception('Could not open the language pack');
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!str_starts_with($name, 'ja/') || str_contains($name, '..') || str_contains($name, '\\')) {
            throw new Exception('Unexpected language archive path');
        }
    }
    if (!is_dir($CFG->dataroot . '/lang')) mkdir($CFG->dataroot . '/lang', $CFG->directorypermissions, true);
    if (!$zip->extractTo($CFG->dataroot . '/lang')) throw new Exception('Could not extract the language pack');
    $zip->close();
} else {
    $controller = new \tool_langimport\controller();
    $controller->install_languagepacks(['ja']);
}
get_string_manager()->reset_caches();
if (!get_string_manager()->translation_exists('ja')) {
    throw new Exception('Japanese language pack was not installed');
}
set_config('lang', 'ja');
foreach (['learner', 'teacher'] as $username) {
    $DB->set_field('user', 'lang', 'ja', ['username' => $username, 'deleted' => 0]);
}
echo "Japanese language pack and trial user preferences configured\n";
