<?php
/** Seed and verify teaching data across an actual old-code Moodle plugin upgrade. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
if (($argv[1] ?? '') !== '/site') {
    throw new RuntimeException('Use the isolated /site fixture mount');
}
require '/site/config.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbname !== 'moodleaudit_upgrade'
        || $CFG->dbhost !== 'audit-db') {
    throw new RuntimeException('Refusing to mutate an unrelated Moodle site');
}
if ((int)$CFG->debug !== DEBUG_DEVELOPER || !$CFG->debugdisplay) {
    throw new RuntimeException('Developer debugging must be enabled');
}
$phase = $argv[2] ?? '';
$statefile = $CFG->dataroot . '/videoq-upgrade-state.json';
if ($phase === 'seed') {
    if ((int)get_config('local_videoq', 'version') !== 2026110501 || file_exists($statefile)) {
        throw new RuntimeException('Expected an unseeded 0.11.0 baseline installation');
    }
    require_once $CFG->dirroot . '/course/lib.php';
    require_once $CFG->dirroot . '/course/modlib.php';
    \core\session\manager::set_user(get_admin());
    $course = create_course((object)['fullname' => 'VideoQ upgrade preservation fixture',
        'shortname' => 'VIDEOQ-MARKETPLACE-UPGRADE', 'category' => 1]);
    $module = add_moduleinfo((object)[
        'modulename' => 'resource', 'module' => $DB->get_field('modules', 'id', ['name' => 'resource']),
        'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => 'Preserved video',
        'intro' => 'Original generated VideoQ test lesson; no learner data.', 'introformat' => FORMAT_HTML,
        'files' => 0, 'display' => 0, 'printintro' => 0, 'showsize' => 0, 'showtype' => 0, 'showdate' => 0,
    ], $course);
    $cm = get_coursemodule_from_instance('resource', $module->instance, $course->id, false, MUST_EXIST);
    $file = get_file_storage()->create_file_from_pathname([
        'contextid' => context_module::instance($cm->id)->id, 'component' => 'mod_resource',
        'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => 'upgrade-lesson.mp4',
        'mimetype' => 'video/mp4', 'sortorder' => 1,
    ], '/audit/source-fixture.mp4');
    $binding = $DB->insert_record('local_videoq_sync', (object)[
        'typeid' => 0, 'cmid' => $cm->id, 'courseid' => $course->id, 'lticmid' => 0, 'ltiinstance' => 0,
        'linkcmid' => 0, 'enabled' => 0, 'generation' => 7, 'fingerprint' => $file->get_contenthash(),
        'status' => 'disabled', 'errorcode' => '', 'timemodified' => time(), 'lastsynced' => 0,
    ]);
    $assertion = $DB->insert_record('local_videoq_assertions', (object)[
        'assertionhash' => hash('sha256', 'isolated-marketplace-upgrade-assertion'), 'expires' => time() + 3600,
    ]);
    $export = $DB->insert_record('local_videoq_exports', (object)[
        'jobid' => hash('sha256', 'isolated-marketplace-upgrade-export'),
        'requesthash' => hash('sha256', 'isolated-marketplace-upgrade-request'),
        'typeid' => 0, 'cmid' => $cm->id, 'courseid' => $course->id, 'generation' => 7,
        'uploadurl' => null, 'status' => 'queued', 'bytes' => 0, 'timecreated' => time(), 'timemodified' => time(),
    ]);
    $state = ['courseId' => (int)$course->id, 'cmid' => (int)$cm->id, 'fileId' => (int)$file->get_id(),
        'fileHash' => $file->get_contenthash(), 'fileBytesHash' => hash('sha256', $file->get_content()),
        'keyHash' => hash('sha256', (string)get_config('local_videoq', 'accesssigningkey')),
        'rows' => []];
    foreach (['local_videoq_sync' => $binding, 'local_videoq_assertions' => $assertion,
            'local_videoq_exports' => $export] as $table => $id) {
        $state['rows'][$table] = (array)$DB->get_record($table, ['id' => $id], '*', MUST_EXIST);
    }
    file_put_contents($statefile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($statefile, 0600);
    echo json_encode(['passed' => true, 'phase' => 'seed', 'database' => $CFG->dbtype,
        'baselineVersion' => 2026110501, 'courseCreated' => true, 'sourceVideoCreated' => true,
        'synchronizationTablesSeeded' => 3]) . "\n";
    exit;
}
if (!in_array($phase, ['verify', 'verify-uninstalled', 'verify-reinstalled'], true) || !is_file($statefile)) {
    throw new RuntimeException('Expected seed or verify phase with a saved baseline');
}
$state = json_decode(file_get_contents($statefile), true, 16, JSON_THROW_ON_ERROR);
if ($phase !== 'verify-uninstalled' && (int)get_config('local_videoq', 'version') < 2026110503) {
    throw new RuntimeException('The candidate plugin upgrade has not been applied');
}
$course = $DB->get_record('course', ['id' => $state['courseId']], '*', MUST_EXIST);
$cm = get_coursemodule_from_id('resource', $state['cmid'], $course->id, false, MUST_EXIST);
$file = get_file_storage()->get_file_by_id($state['fileId']);
if ($course->shortname !== 'VIDEOQ-MARKETPLACE-UPGRADE' || $cm->name !== 'Preserved video'
        || !$file || $file->get_contenthash() !== $state['fileHash']
        || hash('sha256', $file->get_content()) !== $state['fileBytesHash']) {
    throw new RuntimeException('Teaching data or video bytes changed during lifecycle verification');
}
if ($phase === 'verify-reinstalled') {
    if (!$DB->get_manager()->table_exists('local_videoq_courses') || $DB->count_records('local_videoq_courses') !== 0) {
        throw new RuntimeException('Expected a fresh course-entry table after reinstall');
    }
    $newkey = (string)get_config('local_videoq', 'accesssigningkey');
    if (!preg_match('/^[a-f0-9]{64}$/D', $newkey) || hash('sha256', $newkey) === $state['keyHash']) {
        throw new RuntimeException('Reinstallation must create a new access key after complete uninstall');
    }
    foreach (array_keys($state['rows']) as $table) {
        if (!$DB->get_manager()->table_exists($table) || $DB->count_records($table) !== 0) {
            throw new RuntimeException('Expected fresh synchronization tables after reinstall');
        }
    }
    echo json_encode(['passed' => true, 'phase' => $phase, 'database' => $CFG->dbtype,
        'coursePreserved' => true, 'sourceVideoBytesPreserved' => true,
        'freshPluginTables' => true, 'accessKeyRenewed' => true], JSON_PRETTY_PRINT) . "\n";
    exit;
}
if ($phase === 'verify-uninstalled') {
    foreach (array_keys($state['rows']) as $table) {
        if ($DB->get_manager()->table_exists($table)) {
            throw new RuntimeException('Plugin table remains after uninstall');
        }
    }
    foreach (['local_videoq', 'ltisource_videoq'] as $component) {
        if ($DB->record_exists('config_plugins', ['plugin' => $component])) {
            throw new RuntimeException('Plugin configuration remains after uninstall');
        }
    }
    echo json_encode(['passed' => true, 'phase' => $phase, 'database' => $CFG->dbtype,
        'coursePreserved' => true, 'sourceVideoBytesPreserved' => true,
        'pluginTablesRemoved' => true, 'pluginSettingsRemoved' => true], JSON_PRETTY_PRINT) . "\n";
    exit;
}
if (hash('sha256', (string)get_config('local_videoq', 'accesssigningkey')) !== $state['keyHash']) {
    throw new RuntimeException('Site access key changed during upgrade');
}
foreach ($state['rows'] as $table => $expected) {
    $actual = (array)$DB->get_record($table, ['id' => $expected['id']], '*', MUST_EXIST);
    if ($actual !== $expected) {
        throw new RuntimeException('Synchronization record changed during upgrade: ' . $table);
    }
}
echo json_encode(['passed' => true, 'phase' => 'verify', 'database' => $CFG->dbtype,
    'version' => (int)get_config('local_videoq', 'version'), 'coursePreserved' => true,
    'sourceVideoBytesPreserved' => true, 'accessKeyPreserved' => true,
    'allSynchronizationRecordsPreserved' => true], JSON_PRETTY_PRINT) . "\n";
