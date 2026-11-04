<?php
// Isolated disposable fixture. Does not edit existing VideoQ plugin settings.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Lab only');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
\core\session\manager::set_user(get_admin());
$component = 'local_videoq_pull_spike';
$action = $argv[1] ?? '';
$state = json_decode((string)get_config($component, 'fixture'), true);
if ($action === 'setup') {
    if ($state) throw new Exception('A spike is already active');
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (!preg_match('/^[a-f0-9]{32}$/D', $input['run'] ?? '') || !str_starts_with($input['publicKey'] ?? '', '-----BEGIN PUBLIC KEY-----')) throw new Exception('Invalid fixture input');
    $course = create_course((object)['fullname' => 'VideoQ LTI pull spike ' . $input['run'], 'shortname' => 'VQ-PULL-' . $input['run'], 'category' => 1, 'format' => 'topics']);
    $state = ['run' => $input['run'], 'courseid' => (int)$course->id, 'generation' => 1, 'deleted' => false, 'active' => true];
    set_config('fixture', json_encode($state, JSON_THROW_ON_ERROR), $component);
    $typeid = lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
        'lti_typename' => 'VideoQ pull spike ' . $input['run'], 'lti_toolurl' => 'https://videoq.localhost:18443/api/lti/launch',
        'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 0,
        'lti_initiatelogin' => 'https://videoq.localhost:18443/api/lti/login',
        'lti_redirectionuris' => 'https://videoq.localhost:18443/api/lti/launch',
        'lti_keytype' => LTI_RSA_KEY, 'lti_publickey' => $input['publicKey'],
    ]);
    $state['typeid'] = (int)$typeid;
    $state['clientid'] = $DB->get_field('lti_types', 'clientid', ['id' => $typeid], MUST_EXIST);
    set_config('fixture', json_encode($state, JSON_THROW_ON_ERROR), $component);
    $added = add_moduleinfo((object)['modulename' => 'resource', 'module' => $DB->get_field('modules', 'id', ['name' => 'resource']),
        'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => 'Spoken lesson', 'intro' => '',
        'introformat' => FORMAT_HTML, 'display' => 0, 'printintro' => 0, 'showsize' => 0, 'showtype' => 0, 'showdate' => 0], $course);
    $state['cmid'] = (int)$added->coursemodule;
    get_file_storage()->create_file_from_pathname(['contextid' => context_module::instance($state['cmid'])->id,
        'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => 'spoken.mp4',
        'mimetype' => 'video/mp4', 'sortorder' => 1], '/tmp/videoq-pull-spoken.mp4');
    $manual = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
    if (!$instance) $instance = $DB->get_record('enrol', ['id' => $manual->add_instance($course)]);
    $state['learnerid'] = (int)$DB->get_field('user', 'id', ['username' => 'learner'], MUST_EXIST);
    $manual->enrol_user($instance, $state['learnerid'], $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST));
    set_config('fixture', json_encode($state, JSON_THROW_ON_ERROR), $component);
}
if (!$state) { echo json_encode(['exists' => false]); exit; }
if ($action === 'hide' || $action === 'show') {
    set_coursemodule_visible($state['cmid'], $action === 'show' ? 1 : 0); $state['generation']++;
} else if ($action === 'replace') {
    $contextid = context_module::instance($state['cmid'])->id;
    get_file_storage()->delete_area_files($contextid, 'mod_resource', 'content', 0);
    get_file_storage()->create_file_from_pathname(['contextid' => $contextid, 'component' => 'mod_resource', 'filearea' => 'content',
        'itemid' => 0, 'filepath' => '/', 'filename' => 'spoken.mp4', 'mimetype' => 'video/mp4', 'sortorder' => 1], '/tmp/videoq-pull-replacement.mp4');
    $state['generation']++;
} else if ($action === 'delete') {
    course_delete_module($state['cmid'], false); $state['deleted'] = true; $state['generation']++;
} else if ($action === 'revoke') { $state['active'] = false; }
if ($action === 'cleanup') {
    if ($DB->record_exists('course', ['id' => $state['courseid'], 'shortname' => 'VQ-PULL-' . $state['run']])) delete_course($state['courseid'], false);
    if (!empty($state['typeid'])) { $DB->delete_records('lti_access_tokens', ['typeid' => $state['typeid']]); lti_delete_type($state['typeid']); }
    unset_all_config_for_plugin($component);
    echo json_encode(['removed' => true]); exit;
}
set_config('fixture', json_encode($state, JSON_THROW_ON_ERROR), $component);
rebuild_course_cache($state['courseid'], true);
echo json_encode($state, JSON_UNESCAPED_SLASHES) . "\n";
