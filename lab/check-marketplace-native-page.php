<?php
/** Execute the actual native page with an installed Moodle learner and HTML renderer. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
define('PREFERRED_RENDERER_TARGET', 'general');
if (($argv[1] ?? '') !== '/site') throw new RuntimeException('Use the isolated /site mount');
require '/site/config.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbhost !== 'audit-db'
        || $CFG->dbname !== 'moodleaudit_upgrade') {
    throw new RuntimeException('Refusing to mutate an unrelated Moodle database');
}
$state = json_decode(file_get_contents($CFG->dataroot . '/videoq-capabilities-state.json'), true, 16, JSON_THROW_ON_ERROR);
$courseid = (int)$state['courses'][0];
$sourceid = (int)$state['materials']['completed']['cmid'];
$launchid = (int)$state['materials']['completed']['ltiid'];
$course = get_course($courseid);
$user = $DB->get_record('user', ['id' => $state['users']['student']], '*', MUST_EXIST);
if (!str_starts_with($course->shortname, 'VIDEOQ-CAPABILITY-PRIMARY-')
        || !str_starts_with($user->username, 'videoq-capability-student-')) {
    throw new RuntimeException('Owned native page fixture missing');
}
$binding = $DB->get_record('local_videoq_sync', ['cmid' => $sourceid, 'courseid' => $courseid,
    'ltiinstance' => $launchid, 'enabled' => 1], '*', MUST_EXIST);
$saved = [];
foreach (['typeid', 'apiurl'] as $name) $saved[$name] = get_config('local_videoq', $name);
$file = null;
$level = ob_get_level();
try {
    set_config('typeid', $binding->typeid, 'local_videoq');
    set_config('apiurl', 'https://videoq.example.invalid', 'local_videoq');
    $file = get_file_storage()->create_file_from_string((object)[
        'contextid' => context_module::instance($sourceid)->id, 'component' => 'mod_resource',
        'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => 'native-render.mp4',
        'mimetype' => 'video/mp4', 'sortorder' => 1,
    ], 'Synthetic page-render fixture; no playable video or AI response.');
    \core\session\manager::set_user($user);
    $_GET = ['course' => $courseid, 'video' => $sourceid];
    ob_start();
    require $CFG->dirroot . '/local/videoq/view.php';
    $html = ob_get_clean();
    if (!str_contains($html, 'data-videoq-native="1"') || !str_contains($html, 'id="videoq-native-form"')
            || str_contains($html, '<iframe') || str_contains($html, 'data-rel="debugging"')
            || str_contains($html, 'Cannot modify header information')) {
        throw new RuntimeException('Actual Moodle native page did not render its authenticated workspace cleanly');
    }
    echo json_encode(['passed' => true, 'database' => $CFG->dbtype, 'nativePageRendered' => true,
        'limits' => ['Actual PHP page and HTML renderer; synthetic media bytes, no browser/API/model execution.']],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    while (ob_get_level() > $level) ob_end_clean();
    \core\session\manager::set_user(get_admin());
    if ($file) $file->delete();
    foreach ($saved as $name => $value) {
        if ($value === false) unset_config($name, 'local_videoq'); else set_config($name, $value, 'local_videoq');
    }
}
