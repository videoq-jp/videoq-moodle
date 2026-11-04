<?php
// Idempotent fixtures for this isolated interoperability lab only.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') {
    throw new Exception('Use only the isolated LTI lab Moodle');
}
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
\core\session\manager::set_user(get_admin());
$course = $DB->get_record('course', ['shortname' => 'VIDEOQ-LTI-LAB']);
if (!$course) {
    $course = create_course((object)['fullname' => 'VideoQ LTI interoperability lab', 'shortname' => 'VIDEOQ-LTI-LAB', 'category' => 1, 'format' => 'topics']);
}
$users = [];
foreach (['learner' => 'student', 'teacher' => 'editingteacher', 'outsider' => null] as $username => $role) {
    $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
    if (!$user) {
        $userid = user_create_user((object)['username' => $username, 'password' => 'Lti-lab-only-123!', 'firstname' => ucfirst($username), 'lastname' => 'Lab', 'email' => $username . '@example.invalid', 'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id]);
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    }
    if ($role === null) {
        $users[$username] = $user->id;
        continue;
    }
    $plugin = enrol_get_plugin('manual');
    $instances = enrol_get_instances($course->id, true);
    $manual = null;
    foreach ($instances as $instance) if ($instance->enrol === 'manual') $manual = $instance;
    if (!$manual) {
        $id = $plugin->add_default_instance($course);
        $manual = $DB->get_record('enrol', ['id' => $id], '*', MUST_EXIST);
    }
    $roleid = $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST);
    $plugin->enrol_user($manual, $user->id, $roleid);
    $users[$username] = $user->id;
}
$type = $DB->get_record('lti_types', ['name' => 'VideoQ lab']);
if (!$type) {
    $typeid = lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
        'lti_typename' => 'VideoQ lab', 'lti_toolurl' => 'https://videoq.localhost:18443/api/lti/launch',
        'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 1,
        'lti_initiatelogin' => 'https://videoq.localhost:18443/api/lti/login',
        'lti_redirectionuris' => 'https://videoq.localhost:18443/api/lti/launch',
        'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS,
        'lti_contentitem' => 0,
    ]);
    $type = $DB->get_record('lti_types', ['id' => $typeid], '*', MUST_EXIST);
}
$activities = [];
foreach (['VideoQ questions' => 3, 'VideoQ new window' => 4, 'Unbound questions' => 3] as $name => $container) {
    $lti = $DB->get_record('lti', ['course' => $course->id, 'name' => $name]);
    if (!$lti) {
        $module = add_moduleinfo((object)[
            'modulename' => 'lti', 'module' => $DB->get_field('modules', 'id', ['name' => 'lti']),
            'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => $name,
            'intro' => '', 'introformat' => FORMAT_HTML, 'typeid' => $type->id,
            'toolurl' => 'https://videoq.localhost:18443/api/lti/launch',
            'launchcontainer' => $container, 'showtitlelaunch' => 0, 'showdescriptionlaunch' => 0,
            'instructorchoicesendname' => 0, 'instructorchoicesendemailaddr' => 0,
            'instructorchoiceacceptgrades' => 0,
        ], $course);
        $lti = $DB->get_record('lti', ['id' => $module->instance], '*', MUST_EXIST);
    }
    $cm = get_coursemodule_from_instance('lti', $lti->id, $course->id, false, MUST_EXIST);
    $activities[$name] = ['resourceLinkId' => strval($lti->id), 'url' => $CFG->wwwroot . '/mod/lti/view.php?id=' . $cm->id];
}
// The video remains a standard Moodle File activity, served by mod_resource.
$video = $DB->get_record('resource', ['course' => $course->id, 'name' => '動画教材：LMSとVideoQ']);
if (!$video) {
    $module = add_moduleinfo((object)[
        'modulename' => 'resource', 'module' => $DB->get_field('modules', 'id', ['name' => 'resource']),
        'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => '動画教材：LMSとVideoQ',
        'intro' => '24秒の無音デモ動画です。', 'introformat' => FORMAT_HTML,
        'display' => 0, 'printintro' => 0, 'showsize' => 0, 'showtype' => 0, 'showdate' => 0,
    ], $course);
    $video = $DB->get_record('resource', ['id' => $module->instance], '*', MUST_EXIST);
}
$videocm = get_coursemodule_from_instance('resource', $video->id, $course->id, false, MUST_EXIST);
$fs = get_file_storage();
$context = context_module::instance($videocm->id);
if (!$fs->file_exists($context->id, 'mod_resource', 'content', 0, '/', 'lesson.mp4')) {
    $fs->create_file_from_pathname([
        'contextid' => $context->id, 'component' => 'mod_resource', 'filearea' => 'content',
        'itemid' => 0, 'filepath' => '/', 'filename' => 'lesson.mp4', 'mimetype' => 'video/mp4', 'sortorder' => 1,
    ], '/lab-data/lesson.mp4');
}
$lti = $DB->get_record('lti', ['course' => $course->id, 'name' => 'VideoQ questions'], '*', MUST_EXIST);
$lticm = get_coursemodule_from_instance('lti', $lti->id, $course->id, false, MUST_EXIST);
set_config('lessons', json_encode([[
    'id' => 'lms-videoq', 'title' => '動画を見ながら、教材に質問',
    'videoCmid' => (int)$videocm->id, 'ltiCmid' => (int)$lticm->id,
]]), 'local_videoq');
$lessonurl = $CFG->wwwroot . '/local/videoq/view.php?lesson=lms-videoq';
// An ordinary course link makes the companion screen discoverable to learners.
if (!$DB->record_exists('url', ['course' => $course->id, 'name' => '動画を見ながら質問する'])) {
    add_moduleinfo((object)[
        'modulename' => 'url', 'module' => $DB->get_field('modules', 'id', ['name' => 'url']),
        'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => '動画を見ながら質問する',
        'intro' => '', 'introformat' => FORMAT_HTML, 'externalurl' => $lessonurl, 'display' => 0,
        'printintro' => 0, 'parameters' => [],
    ], $course);
}
rebuild_course_cache($course->id, true);
echo json_encode(['issuer' => $CFG->wwwroot, 'clientId' => $type->clientid, 'deploymentId' => strval($type->id), 'contextId' => strval($course->id), 'activities' => $activities, 'users' => $users, 'lessonUrl' => $lessonurl, 'videoCmid' => (int)$videocm->id], JSON_PRETTY_PRINT) . "\n";
