<?php
// Reproducible fixtures; all credentials belong to the isolated lab only.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Use only the isolated LTI lab');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
if (($argv[1] ?? '') === 'configure') {
    $credential = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (($credential['installationId'] ?? '') !== 'moodle-sync-lab' || !preg_match('/^[a-f0-9]{64}$/D', $credential['token'] ?? '')) throw new Exception('Invalid lab credential');
    $type = $DB->get_record('lti_types', ['name' => 'VideoQ synchronization lab'], '*', MUST_EXIST);
    set_config('apiurl', 'https://videoq.localhost:18443', 'local_videoq');
    set_config('typeid', $type->id, 'local_videoq');
    set_config('synctoken', $credential['token'], 'local_videoq');
    echo "Synchronization lab connection configured\n";
    exit;
}
$course = $DB->get_record('course', ['shortname' => 'VIDEOQ-LTI-LAB'], '*', MUST_EXIST);
$type = $DB->get_record('lti_types', ['name' => 'VideoQ synchronization lab']);
if (!$type) {
    $id = lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
        'lti_typename' => 'VideoQ synchronization lab', 'lti_toolurl' => 'https://videoq.localhost:18443/api/lti/launch',
        'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 1,
        'lti_initiatelogin' => 'https://videoq.localhost:18443/api/lti/login',
        'lti_redirectionuris' => 'https://videoq.localhost:18443/api/lti/launch',
        'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS, 'lti_contentitem' => 0,
    ]);
    $type = $DB->get_record('lti_types', ['id' => $id], '*', MUST_EXIST);
}
$resource = $DB->get_record('resource', ['course' => $course->id, 'name' => '同期テスト用の動画教材']);
if (!$resource) {
    $module = add_moduleinfo((object)[
        'modulename' => 'resource', 'module' => $DB->get_field('modules', 'id', ['name' => 'resource']),
        'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => '同期テスト用の動画教材',
        'intro' => '音声抽出の検証用にテスト音を含みます。推論結果は固定のテストデータです。',
        'introformat' => FORMAT_HTML, 'display' => 0, 'printintro' => 0, 'showsize' => 0, 'showtype' => 0, 'showdate' => 0,
    ], $course);
    $resource = $DB->get_record('resource', ['id' => $module->instance], '*', MUST_EXIST);
}
$cm = get_coursemodule_from_instance('resource', $resource->id, $course->id, false, MUST_EXIST);
$context = context_module::instance($cm->id);
$fs = get_file_storage();
if (!$fs->file_exists($context->id, 'mod_resource', 'content', 0, '/', 'sync-lesson.mp4')) {
    $fs->create_file_from_pathname(['contextid' => $context->id, 'component' => 'mod_resource', 'filearea' => 'content',
        'itemid' => 0, 'filepath' => '/', 'filename' => 'sync-lesson.mp4', 'mimetype' => 'video/mp4', 'sortorder' => 1], '/lab-data/sync-lesson.mp4');
}
rebuild_course_cache($course->id, true);
echo json_encode(['cmid' => (int)$cm->id, 'courseId' => (int)$course->id, 'connection' => [
    'id' => 'moodle-sync-lab', 'ownerUserId' => 'lti-lab-owner', 'name' => 'Moodle sync lab', 'issuer' => $CFG->wwwroot,
    'clientId' => $type->clientid, 'deploymentId' => (string)$type->id,
    'authorizationUrl' => $CFG->wwwroot . '/mod/lti/auth.php', 'jwksUrl' => $CFG->wwwroot . '/mod/lti/certs.php',
]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
