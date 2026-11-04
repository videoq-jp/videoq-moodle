<?php
// CLI mutations for the isolated synchronization acceptance test only.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Use only the isolated lab');
require_once($CFG->dirroot . '/course/lib.php');
\core\session\manager::set_user(get_admin());
$cmid = (int)($argv[2] ?? 0);
$action = $argv[1] ?? 'state';
$row = $DB->get_record('local_videoq_sync', ['cmid' => $cmid]);
if ($action === 'state') {
    echo json_encode($row ? array_merge((array)$row, [
        'ltiExists' => $DB->record_exists('course_modules', ['id' => $row->lticmid]),
        'linkExists' => $DB->record_exists('course_modules', ['id' => $row->linkcmid]),
    ]) : null, JSON_THROW_ON_ERROR);
    exit;
}
$cm = get_coursemodule_from_id('resource', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
if ($course->shortname !== 'VIDEOQ-LTI-LAB') throw new Exception('Not a lab activity');
if ($action === 'rename') set_coursemodule_name($cmid, '同期テスト用の動画教材・改訂');
else if ($action === 'hide' || $action === 'show') set_coursemodule_visible($cmid, $action === 'show' ? 1 : 0);
else if ($action === 'delete') course_delete_module($cmid, false);
else if ($action === 'replace') {
    $context = context_module::instance($cmid);
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_resource', 'content');
    $fs->create_file_from_pathname(['contextid' => $context->id, 'component' => 'mod_resource', 'filearea' => 'content',
        'itemid' => 0, 'filepath' => '/', 'filename' => 'sync-replacement.mp4', 'mimetype' => 'video/mp4', 'sortorder' => 1], '/lab-data/sync-replacement.mp4');
} else if ($action === 'restrict' || $action === 'unrestrict') {
    $availability = $action === 'restrict' ? json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + 3600]], 'showc' => [true]]) : null;
    $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);
} else throw new Exception('Unknown test action');
if ($action !== 'delete') {
    rebuild_course_cache($course->id, true);
    \core\event\course_module_updated::create(['objectid' => $cmid, 'context' => context_module::instance($cmid),
        'other' => ['modulename' => 'resource', 'instanceid' => $cm->instance, 'name' => $cm->name]])->trigger();
}
echo "Lab activity updated\n";
