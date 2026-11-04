<?php
// A temporary opted-in File in the existing lab course; never changes connection configuration.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db' ||
        get_config('local_videoq', 'servicemode') !== 'lti' || (int)get_config('local_videoq', 'typeid') !== 2) throw new \RuntimeException('Migrated lab only');
require_once($CFG->dirroot . '/course/lib.php'); require_once($CFG->dirroot . '/course/modlib.php');
\core\session\manager::set_user(get_admin());
$component = 'local_videoq_live_acceptance';
$state = json_decode((string)get_config($component, 'fixture'), true);
$action = $argv[1] ?? '';
if ($action === 'create') {
    if ($state) throw new \RuntimeException('Another live acceptance fixture exists');
    $run = bin2hex(random_bytes(16)); $course = get_course(5);
    $added = add_moduleinfo((object)['modulename' => 'resource', 'module' => $DB->get_field('modules','id',['name'=>'resource']),
        'course' => 5, 'section' => 0, 'visible' => 1, 'name' => 'VideoQ acceptance ' . $run,
        'intro' => '', 'introformat' => FORMAT_HTML, 'display' => 0, 'printintro' => 0, 'showsize' => 0, 'showtype' => 0, 'showdate' => 0], $course);
    $state = ['run' => $run, 'cmid' => (int)$added->coursemodule];
    set_config('fixture', json_encode($state, JSON_THROW_ON_ERROR), $component);
    get_file_storage()->create_file_from_pathname(['contextid' => context_module::instance($state['cmid'])->id, 'component' => 'mod_resource',
        'filearea' => 'content','itemid' => 0,'filepath' => '/','filename' => 'acceptance.mp4','mimetype' => 'video/mp4','sortorder' => 1], '/tmp/videoq-materials-acceptance.mp4');
    \local_videoq\synchronizer::enable($state['cmid']);
}
if (!$state) throw new \RuntimeException('No acceptance fixture');
if ($action === 'replace') {
    $cm = get_coursemodule_from_id('resource', $state['cmid'], 5, false, MUST_EXIST);
    if ($cm->name !== 'VideoQ acceptance ' . $state['run']) throw new \RuntimeException('Unexpected source identity');
    $transaction = $DB->start_delegated_transaction();
    $context = context_module::instance($cm->id);
    get_file_storage()->delete_area_files($context->id, 'mod_resource', 'content', 0);
    get_file_storage()->create_file_from_pathname(['contextid' => $context->id, 'component' => 'mod_resource',
        'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => 'acceptance.mp4',
        'mimetype' => 'video/mp4', 'sortorder' => 1], '/tmp/videoq-materials-replacement.mp4');
    $DB->execute('UPDATE {resource} SET revision = revision + 1, timemodified = ? WHERE id = ?', [time(), $cm->instance]);
    $transaction->allow_commit();
    rebuild_course_cache(5, true);
    \core\event\course_module_updated::create(['objectid' => $cm->id, 'context' => $context,
        'other' => ['modulename' => 'resource', 'instanceid' => $cm->instance, 'name' => $cm->name]])->trigger();
} else if ($action === 'delete') {
    $cm = get_coursemodule_from_id('resource', $state['cmid'], 5, false, IGNORE_MISSING);
    if ($cm) {
        if ($cm->name !== 'VideoQ acceptance ' . $state['run']) throw new \RuntimeException('Unexpected source identity');
        course_delete_module($cm->id, false);
    }
} else if ($action === 'cleanup') {
    if ($DB->record_exists('course_modules', ['id'=>$state['cmid']])) throw new \RuntimeException('Delete the source first');
    $row = $DB->get_record('local_videoq_sync', ['cmid'=>$state['cmid']]);
    if ($row && $row->status !== 'disabled') throw new \RuntimeException('Wait for source deletion acknowledgement');
    $DB->delete_records('local_videoq_exports', ['cmid'=>$state['cmid'],'typeid'=>2]);
    $DB->delete_records('local_videoq_sync', ['cmid'=>$state['cmid']]);
    unset_all_config_for_plugin($component);
}
$row = $DB->get_record('local_videoq_sync', ['cmid'=>$state['cmid']]);
echo json_encode($state + ['status'=>$row->status ?? null,'error'=>$row->errorcode ?? null]);
