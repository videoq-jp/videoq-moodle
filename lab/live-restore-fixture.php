<?php
// Disposable courses for real copy/.mbz restoration and independent synchronization.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db' ||
        get_config('local_videoq', 'servicemode') !== 'lti' || (int)get_config('local_videoq', 'typeid') !== 2) {
    throw new RuntimeException('Migrated isolated lab only');
}
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
\core\session\manager::set_user(get_admin());
set_exception_handler(static function(Throwable $error): void {
    $diagnostic = ['class' => get_class($error), 'message' => $error->getMessage(),
        'code' => $error->errorcode ?? null, 'trace' => array_map(static fn($frame) =>
            ['file' => $frame['file'] ?? '', 'line' => $frame['line'] ?? 0, 'function' => $frame['function']], $error->getTrace())];
    $file = $GLOBALS['CFG']->dataroot . '/videoq-restore-errors.jsonl';
    file_put_contents($file, json_encode($diagnostic, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND); chmod($file, 0600);
    exit(1);
});
$component = 'local_videoq_live_restore';
$state = json_decode((string)get_config($component, 'fixture'), true);
$action = $argv[1] ?? 'status';
$save = static function() use (&$state, $component): void { set_config('fixture', json_encode($state, JSON_THROW_ON_ERROR), $component); };
if ($action === 'create') {
    if ($state) throw new RuntimeException('Another live restore fixture exists');
    $run = bin2hex(random_bytes(12));
    $state = ['run' => $run, 'courses' => [], 'sources' => []]; $save();
    $course = create_course((object)['fullname' => 'VideoQ copy acceptance ' . $run,
        'shortname' => 'vq-source-' . $run, 'category' => 1]);
    $state['courses']['source'] = (int)$course->id; $save();
    foreach (['enabled', 'disabled'] as $kind) {
        $source = add_moduleinfo((object)['modulename' => 'resource', 'module' => $DB->get_field('modules', 'id', ['name' => 'resource']),
            'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => $kind . ' acceptance',
            'intro' => '', 'introformat' => FORMAT_HTML, 'display' => 0, 'printintro' => 0, 'showsize' => 0, 'showtype' => 0, 'showdate' => 0], $course);
        $cmid = (int)$source->coursemodule;
        get_file_storage()->create_file_from_pathname(['contextid' => context_module::instance($cmid)->id,
            'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/',
            'filename' => 'lesson.mp4', 'mimetype' => 'video/mp4', 'sortorder' => 1], '/tmp/videoq-restore-acceptance.mp4');
        if ($kind === 'enabled') {
            $state['sources']['source'] = $cmid; $save();
            \local_videoq\synchronizer::enable($cmid);
        } else {
            $DB->insert_record('local_videoq_sync', (object)['typeid' => 2, 'cmid' => $cmid,
                'courseid' => $course->id, 'lticmid' => 0, 'ltiinstance' => 0, 'linkcmid' => 0,
                'enabled' => 0, 'status' => 'disabled', 'timemodified' => time()]);
        }
    }
    add_moduleinfo((object)['modulename' => 'url', 'module' => $DB->get_field('modules', 'id', ['name' => 'url']),
        'course' => $course->id, 'section' => 0, 'visible' => 1, 'name' => 'Unrelated reference', 'intro' => '',
        'introformat' => FORMAT_HTML, 'externalurl' => 'https://example.org/learning', 'display' => 0, 'printintro' => 0], $course);
}
if (!$state) throw new RuntimeException('No live restore fixture');
if (in_array($action, ['copy', 'restore'], true)) {
    if (isset($state['courses'][$action])) throw new RuntimeException('Destination already exists');
    require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
    require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
    $shortname = 'vq-' . $action . '-' . $state['run'];
    if ($action === 'copy') {
        require_once($CFG->dirroot . '/course/externallib.php');
        $existing = array_keys($DB->get_records('course', null, '', 'id'));
        try {
            $copy = core_course_external::duplicate_course($state['courses']['source'], 'Copy acceptance ' . $state['run'],
                $shortname, 1, 1, [['name' => 'users', 'value' => 0]]);
        } finally {
            // Core can leave a partially restored course when a plugin throws.
            foreach ($DB->get_records_select('course', $DB->sql_like('shortname', '?'), ['%' . $state['run'] . '%']) as $candidate) {
                if (!in_array((int)$candidate->id, $existing, true)) {
                    $state['courses'][$action] = (int)$candidate->id;
                    $bindings = $DB->get_records('local_videoq_sync', ['courseid' => $candidate->id, 'enabled' => 1]);
                    if (count($bindings) === 1) $state['sources'][$action] = (int)reset($bindings)->cmid;
                    $save();
                }
            }
        }
        $destination = (int)$copy['id'];
        $state['courses'][$action] = $destination; $save();
    } else {
        $bc = new backup_controller(backup::TYPE_1COURSE, $state['courses']['source'], backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO, backup::MODE_GENERAL, $USER->id);
        $bc->get_plan()->get_setting('users')->set_value(false);
        $bc->execute_plan(); $file = $bc->get_results()['backup_destination']; $bc->destroy();
        // Extract the actual archive into a new directory, not a same-site backup workspace.
        $directory = 'videoq-acceptance-' . bin2hex(random_bytes(16));
        $path = $CFG->tempdir . '/backup/' . $directory;
        if (!$file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path)) throw new RuntimeException('Archive extraction failed');
        $xml = file_get_contents($path . '/activities/resource_' . $state['sources']['source'] . '/module.xml');
        if (!str_contains($xml, '<role>source</role>') || !str_contains($xml, '<enabled>1</enabled>')) throw new RuntimeException('Opt-in marker missing');
        $marker = simplexml_load_string($xml)->xpath('//plugin_local_videoq_module/binding');
        if (!$marker || array_keys((array)$marker[0]) !== ['@attributes', 'role', 'enabled']) throw new RuntimeException('Unexpected portable metadata');
        $destination = restore_dbops::create_new_course('Archive acceptance ' . $state['run'], $shortname, 1);
        $state['courses'][$action] = (int)$destination; $save();
        $rc = new restore_controller($directory, $destination, backup::INTERACTIVE_NO,
            backup::MODE_GENERAL, $USER->id, backup::TARGET_NEW_COURSE);
        $rc->get_plan()->get_setting('users')->set_value(false);
        if (!$rc->execute_precheck() && !empty($rc->get_precheck_results()['errors'])) throw new RuntimeException('Restore precheck failed');
        $rc->execute_plan(); $rc->destroy(); $file->delete(); fulldelete($path);
    }
    $rows = $DB->get_records('local_videoq_sync', ['courseid' => $destination]);
    if (count($rows) !== 1) throw new RuntimeException('Only the opted-in source should have a destination mapping');
    $row = reset($rows); $state['sources'][$action] = (int)$row->cmid; $save();
    if (!$row->enabled || $row->cmid == $state['sources']['source']) throw new RuntimeException('Invalid destination source identity');
    $urls = $DB->get_records('url', ['course' => $destination]); $entry = $unrelated = 0;
    foreach ($urls as $url) {
        $cm = get_coursemodule_from_instance('url', $url->id, $destination, false, MUST_EXIST);
        if ($cm->visible && $url->externalurl === $CFG->wwwroot . '/local/videoq/view.php?course=' . $destination) $entry++;
        if ($url->externalurl === 'https://example.org/learning') $unrelated++;
        if (str_contains($url->externalurl, 'course=' . $state['courses']['source'])) throw new RuntimeException('Source link survived restoration');
    }
    if ($entry !== 1 || $unrelated !== 1) throw new RuntimeException('Restored links invalid');
}
if ($action === 'delete') {
    $name = $argv[2] ?? '';
    if (!isset($state['courses'][$name])) throw new RuntimeException('Unknown fixture course');
    $id = $state['courses'][$name];
    $course = $DB->get_record('course', ['id' => $id]);
    if ($course) {
        if (!preg_match('/^vq-(source|copy|restore)-' . preg_quote($state['run'], '/') . '(?:_\d+)?$/D', $course->shortname)) throw new RuntimeException('Unexpected course identity');
        delete_course($id, false);
    }
}
if ($action === 'cleanup') {
    foreach ($state['courses'] as $id) {
        if ($DB->record_exists('course', ['id' => $id])) throw new RuntimeException('Delete fixture courses first');
        if ($DB->record_exists_select('local_videoq_sync', 'courseid = ? AND enabled = 1 AND status <> ?', [$id, 'disabled'])) throw new RuntimeException('Wait for deletion acknowledgement');
        $DB->delete_records('local_videoq_exports', ['courseid' => $id, 'typeid' => 2]);
        $DB->delete_records('local_videoq_sync', ['courseid' => $id]);
    }
    unset_all_config_for_plugin($component);
}
$state['sync'] = [];
foreach ($state['sources'] as $name => $cmid) {
    $row = $DB->get_record('local_videoq_sync', ['cmid' => $cmid]);
    $state['sync'][$name] = $row ? ['status' => $row->status, 'lticmid' => (int)$row->lticmid,
        'linkcmid' => (int)$row->linkcmid, 'typeid' => (int)$row->typeid, 'error' => $row->errorcode] : null;
}
echo json_encode($state, JSON_THROW_ON_ERROR);
