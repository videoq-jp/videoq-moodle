<?php
// CLI checks for the isolated lab's administrator configuration boundary.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') {
    throw new Exception('Use only the isolated LTI lab Moodle');
}
require_once($CFG->libdir . '/adminlib.php');
$valid = ['id' => 'lesson-1', 'title' => 'Lesson 1', 'videoCmid' => 10, 'ltiCmid' => 11];
$parsed = \local_videoq\lesson::parse(json_encode([$valid]));
if ($parsed['lesson-1'] !== $valid) throw new Exception('Valid association did not round-trip');
$invalid = [
    'not json', '{}', '[null]',
    json_encode([$valid, $valid]),
    json_encode([array_merge($valid, ['videoCmid' => '10'])]),
    json_encode([array_merge($valid, ['ltiCmid' => -1])]),
    json_encode([array_merge($valid, ['id' => '../another'])]),
    json_encode([array_merge($valid, ['title' => ' '])]),
    json_encode([array_merge($valid, ['unexpected' => 'value'])]),
];
foreach ($invalid as $json) {
    $rejected = false;
    try { \local_videoq\lesson::parse($json); } catch (Throwable $error) { $rejected = true; }
    if (!$rejected) throw new Exception('Invalid association was accepted');
}
$setting = new \local_videoq\admin_setting_lessons('local_videoq/lessons', 'Lessons', '', '[]');
if ($setting->validate(json_encode([$valid])) !== true || $setting->validate('bad json') === true) {
    throw new Exception('Admin setting validation failed');
}
echo "Plugin configuration checks passed (11 cases)\n";
