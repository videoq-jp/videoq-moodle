<?php
// Destructive steps are restricted to the isolated lab and a disposable course.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Lab only');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
\core\session\manager::set_user(get_admin());
$backup = $CFG->dataroot . '/videoq-pairing-acceptance.json';
$names = ['apiurl', 'typeid', 'synctoken', 'pendingconnection'];
$action = $argv[1] ?? '';
if ($action === 'backup') {
    if (is_file($backup)) throw new Exception('Restore previous acceptance settings first');
    $saved = [];
    foreach ($names as $name) $saved[$name] = get_config('local_videoq', $name);
    $handle = fopen($backup, 'x'); chmod($backup, 0600);
    fwrite($handle, json_encode($saved, JSON_THROW_ON_ERROR)); fclose($handle);
    foreach ($names as $name) unset_config($name, 'local_videoq');
    echo json_encode(['saved' => true]); exit;
}
if ($action === 'restore') {
    if (!is_file($backup)) throw new Exception('No backup');
    $saved = json_decode(file_get_contents($backup), true, 16, JSON_THROW_ON_ERROR);
    $testtype = (int)get_config('local_videoq', 'typeid');
    foreach ($saved as $name => $value) {
        if ($value === false) unset_config($name, 'local_videoq');
        else set_config($name, $value, 'local_videoq');
    }
    $restored = true;
    foreach ($saved as $name => $value) $restored = $restored && get_config('local_videoq', $name) === $value;
    if (!$restored) throw new Exception('Settings restoration mismatch');
    if ($testtype && $testtype !== (int)$saved['typeid'] && $DB->record_exists('lti_types', ['id' => $testtype, 'name' => 'VideoQ'])) lti_delete_type($testtype);
    unlink($backup); echo json_encode(['restored' => true]); exit;
}
if ($action === 'simulate-lost-response') {
    $code = trim(stream_get_contents(STDIN));
    if (!str_starts_with($code, 'VIDEOQ1.')) throw new Exception('Invalid test code');
    $pending = ['codehash' => hash('sha256', $code), 'origin' => get_config('local_videoq', 'apiurl'),
        'typeid' => (int)get_config('local_videoq', 'typeid'), 'token' => get_config('local_videoq', 'synctoken')];
    if (!$pending['token'] || !$pending['typeid']) throw new Exception('No paired test connection');
    set_config('pendingconnection', json_encode($pending, JSON_THROW_ON_ERROR), 'local_videoq');
    foreach (['apiurl', 'typeid', 'synctoken'] as $name) unset_config($name, 'local_videoq');
    echo json_encode(['pending' => true]); exit;
}
if ($action === 'type') {
    $type = $DB->get_record('lti_types', ['id' => get_config('local_videoq', 'typeid')], '*', MUST_EXIST);
    echo json_encode(['typeid' => (int)$type->id, 'clientId' => $type->clientid]); exit;
}
$course = $DB->get_record('course', ['shortname' => 'VIDEOQ-PAIRING-ACCEPTANCE']);
if ($action === 'setup' && !$course) {
    $course = create_course((object)['fullname' => 'VideoQ pairing acceptance', 'shortname' => 'VIDEOQ-PAIRING-ACCEPTANCE', 'category' => 1, 'format' => 'topics']);
    $added = add_moduleinfo((object)['modulename' => 'resource', 'module' => $DB->get_field('modules', 'id', ['name' => 'resource']),
        'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => 'Pairing spoken lesson', 'intro' => '',
        'introformat' => FORMAT_HTML, 'display' => 0, 'printintro' => 0, 'showsize' => 0, 'showtype' => 0, 'showdate' => 0], $course);
    get_file_storage()->create_file_from_pathname(['contextid' => context_module::instance($added->coursemodule)->id,
        'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => 'pairing-spoken.mp4',
        'mimetype' => 'video/mp4', 'sortorder' => 1], '/tmp/videoq-pairing-spoken.mp4');
}
if (!$course) { echo json_encode(['exists' => false]); exit; }
$cm = $DB->get_record_sql("SELECT cm.* FROM {course_modules} cm JOIN {modules} m ON m.id=cm.module WHERE cm.course=? AND m.name='resource'", [$course->id], MUST_EXIST);
$row = $DB->get_record('local_videoq_sync', ['cmid' => $cm->id]);
if ($action === 'cleanup') delete_course($course, false);
if (($action === 'tick' || $action === 'cleanup') && $row) \local_videoq\synchronizer::run((int)$row->id);
$row = $DB->get_record('local_videoq_sync', ['cmid' => $cm->id]);
echo json_encode(['courseid' => (int)$course->id, 'cmid' => (int)$cm->id, 'status' => $row->status ?? null]);
