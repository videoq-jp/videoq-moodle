<?php
/** Actual .mbz course/activity restore, destination authority and delayed work; no provider requests. */
declare(strict_types=1);
define('CLI_SCRIPT', true);
if (($argv[1] ?? '') !== '/site') throw new RuntimeException('Use the isolated /site fixture');
require '/site/config.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbhost !== 'audit-db' || $CFG->dbname !== 'moodleaudit_upgrade') {
    throw new RuntimeException('Refusing unrelated Moodle database');
}
require_once $CFG->dirroot . '/course/lib.php';
require_once $CFG->dirroot . '/course/modlib.php';
require_once $CFG->dirroot . '/user/lib.php';
require_once $CFG->dirroot . '/mod/lti/locallib.php';
require_once $CFG->dirroot . '/backup/util/includes/backup_includes.php';
require_once $CFG->dirroot . '/backup/util/includes/restore_includes.php';
$CFG->noemailever = true;
// Keep normal backup warnings/errors, without its verbose developer-stage log.
// PHP/Moodle developer diagnostics remain enabled and are checked by the matrix.
$CFG->backup_error_log_logger_level = backup::LOG_WARNING;
\core\session\manager::set_user(get_admin());
$saved = [];
foreach (['typeid', 'apiurl', 'servicemode'] as $key) $saved[$key] = get_config('local_videoq', $key);
$recycle = get_config('tool_recyclebin', 'coursebinenable');
set_config('coursebinenable', 0, 'tool_recyclebin');
$courses = $directories = $files = $taskids = $checks = [];
$check = static function(bool $valid, string $label) use (&$checks): void {
    if (!$valid) throw new RuntimeException('YouTube archive check failed: ' . $label);
    $checks[] = $label;
};
$newtype = static function(string $label): int {
    return (int)lti_add_type((object)['state' => LTI_TOOL_STATE_CONFIGURED], (object)[
        'lti_typename' => 'Owned archive ' . $label, 'lti_toolurl' => 'https://videoq.example.invalid/api/lti/launch',
        'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 1, 'lti_contentitem' => 0,
        'lti_initiatelogin' => 'https://videoq.example.invalid/api/lti/login',
        'lti_redirectionuris' => 'https://videoq.example.invalid/api/lti/launch', 'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS,
    ]);
};
try {
    $suffix = bin2hex(random_bytes(6));
    $source = create_course((object)['fullname' => 'Owned YouTube backup ' . $suffix,
        'shortname' => 'VIDEOQ-YT-ARCHIVE-' . $suffix, 'category' => 1]);
    $courses[] = (int)$source->id;
    $teacherrole = $DB->get_record('role', ['archetype' => 'editingteacher'], '*', MUST_EXIST);
    $teacherid = user_create_user((object)['username' => 'videoq-yt-archive-' . $suffix,
        'firstname' => 'Synthetic', 'lastname' => 'Archive teacher', 'email' => 'archive@example.invalid',
        'password' => 'Only-fixture-123!', 'auth' => 'manual', 'confirmed' => 1]);
    $teacher = $DB->get_record('user', ['id' => $teacherid], '*', MUST_EXIST);
    $manual = enrol_get_plugin('manual');
    $enrol = static function(object $course) use ($manual, $teacherid, $teacherrole, $DB): void {
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        if (!$instance) $instance = $DB->get_record('enrol', ['id' => $manual->add_default_instance($course)], '*', MUST_EXIST);
        $DB->set_field('enrol', 'customint1', ENROL_DO_NOT_SEND_EMAIL, ['id' => $instance->id]);
        $instance->customint1 = ENROL_DO_NOT_SEND_EMAIL;
        $manual->enrol_user($instance, $teacherid, $teacherrole->id);
    };
    $enrol($source);
    $sourcetype = $newtype('source-' . $suffix);
    set_config('typeid', $sourcetype, 'local_videoq');
    set_config('apiurl', 'https://videoq.example.invalid', 'local_videoq');
    set_config('servicemode', 'lti', 'local_videoq');
    $common = ['course' => $source->id, 'section' => 0, 'visible' => 1, 'intro' => '', 'introformat' => FORMAT_HTML];
    $url = static function(string $name, string $target) use ($common, $source, $DB): object {
        return add_moduleinfo((object)array_merge($common, ['modulename' => 'url',
            'module' => $DB->get_field('modules', 'id', ['name' => 'url']), 'name' => $name,
            'externalurl' => $target, 'display' => 0, 'printintro' => 0, 'parameters' => []]), $source);
    };
    $enabled = $url('Owned enabled provider', 'https://youtu.be/abcDEF123_-');
    $disabled = $url('Owned disabled provider', 'https://youtu.be/xyzUVW987_-');
    $unrelated = $url('Owned ordinary reference', 'https://example.invalid/ordinary');
    $transport = add_moduleinfo((object)array_merge($common, ['modulename' => 'lti',
        'module' => $DB->get_field('modules', 'id', ['name' => 'lti']), 'name' => 'Owned original transport',
        'typeid' => $sourcetype, 'toolurl' => 'https://videoq.example.invalid/api/lti/launch',
        'launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS, 'showtitlelaunch' => 0, 'showdescriptionlaunch' => 0,
        'instructorchoicesendname' => 0, 'instructorchoicesendemailaddr' => 0, 'instructorchoiceacceptgrades' => 0]), $source);
    $shortcut = $url('Owned original shortcut', $CFG->wwwroot . '/local/videoq/view.php?course=' . $source->id . '&video=' . $enabled->coursemodule);
    foreach ([$enabled, $disabled] as $activity) {
        $active = $activity === $enabled;
        $DB->insert_record('local_videoq_sync', (object)['sourcekind' => 'youtube',
            'youtubeid' => $active ? 'abcDEF123_-' : 'xyzUVW987_-', 'typeid' => $sourcetype,
            'cmid' => $activity->coursemodule, 'courseid' => $source->id,
            'lticmid' => $active ? $transport->coursemodule : 0, 'ltiinstance' => $active ? $transport->instance : 0,
            'linkcmid' => $active ? $shortcut->coursemodule : 0, 'generation' => 1000001,
            'fingerprint' => str_repeat('a', 64), 'enabled' => (int)$active,
            'status' => $active ? 'completed' : 'disabled', 'timemodified' => time()]);
    }
    \local_videoq\synchronizer::ensure_course_entry((int)$source->id);
    $original = $DB->get_records('local_videoq_sync', ['courseid' => $source->id]);
    $destinationtype = $newtype('destination-' . $suffix);
    foreach (['course', 'activity', 'unauthorized'] as $mode) {
        \core\session\manager::set_user($teacher);
        reload_all_capabilities();
        $full = $mode === 'course';
        $bc = new backup_controller($full ? backup::TYPE_1COURSE : backup::TYPE_1ACTIVITY,
            $full ? $source->id : $enabled->coursemodule, backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO, backup::MODE_GENERAL, $teacherid);
        $usersetting = $bc->get_plan()->get_setting('users');
        // A normal teacher cannot back up user data; core already locks this
        // setting off. Keep that permission decision instead of overriding it.
        if ($usersetting->get_value()) $usersetting->set_value(false);
        $check(!$usersetting->get_value(), $mode . ': backup excludes user data without widening teacher authority');
        $basepath = $bc->get_plan()->get_basepath(); $directories[] = $basepath;
        $bc->execute_plan();
        $archive = $bc->get_results()['backup_destination']; $files[] = $archive;
        $bc->destroy();
        $directory = 'videoq-youtube-archive-' . bin2hex(random_bytes(12));
        $path = $CFG->tempdir . '/backup/' . $directory; $directories[] = $path;
        $extracted = $archive->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);
        // Moodle returns a per-file result map, including individual failures.
        $check(is_array($extracted) && $extracted !== []
            && !array_filter($extracted, static fn($status) => $status !== true)
            && is_file($path . '/moodle_backup.xml'), $mode . ': extract actual mbz into a fresh directory');
        $xml = simplexml_load_file($path . '/activities/url_' . $enabled->coursemodule . '/module.xml');
        $markers = $xml->xpath('//plugin_local_videoq_module/binding');
        $check(count($markers) === 1 && array_keys((array)$markers[0]) === ['@attributes', 'role', 'enabled', 'sourcekind']
            && (string)$markers[0]->role === 'source' && (string)$markers[0]->enabled === '1'
            && (string)$markers[0]->sourcekind === 'youtube', $mode . ': portable source metadata contains only role, opt-in and kind');
        \core\session\manager::set_user(get_admin());
        $destination = restore_dbops::create_new_course('Owned restored provider ' . $suffix,
            'VIDEOQ-YT-RESTORED-' . $mode . '-' . $suffix, 1);
        $courses[] = (int)$destination;
        $enrol(get_course($destination));
        $destinationcontext = context_course::instance($destination);
        if ($mode === 'unauthorized') {
            assign_capability('local/videoq:managecontent', CAP_PREVENT, $teacherrole->id, $destinationcontext->id, true);
        }
        accesslib_clear_all_caches(true);
        set_config('typeid', $destinationtype, 'local_videoq');
        \core\session\manager::set_user($teacher);
        // Simulate the teacher's next request after the administrator enrolled
        // them in a new course; set_user alone retains the old accessdata.
        reload_all_capabilities();
        $check(has_capability('local/videoq:managecontent', $destinationcontext) === ($mode !== 'unauthorized'),
            $mode . ': destination teacher authority is explicit');
        $rc = new restore_controller($directory, $destination, backup::INTERACTIVE_NO, backup::MODE_GENERAL, $teacherid,
            $full ? backup::TARGET_NEW_COURSE : backup::TARGET_EXISTING_ADDING);
        $usersetting = $rc->get_plan()->get_setting('users');
        if ($usersetting->get_value()) $usersetting->set_value(false);
        $check(!$usersetting->get_value(), $mode . ': restore excludes user data without widening teacher authority');
        if (!$rc->execute_precheck() && !empty($rc->get_precheck_results()['errors'])) throw new RuntimeException('Owned archive restore precheck failed');
        $rc->execute_plan(); $rc->destroy();
        $rows = $DB->get_records('local_videoq_sync', ['courseid' => $destination]);
        $check(count($rows) === ($mode === 'unauthorized' ? 0 : 1), $mode . ': only authorized opted-in sources acquire destination bindings');
        $restored = $DB->get_record('url', ['course' => $destination, 'name' => 'Owned enabled provider'], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('url', $restored->id, $destination, false, MUST_EXIST);
        $check($restored->externalurl === 'https://youtu.be/abcDEF123_-' && (int)$cm->id !== (int)$enabled->coursemodule,
            $mode . ': current canonical provider URL survives with a new module ID');
        $tasks = [];
        foreach ($DB->get_records('task_adhoc', ['classname' => '\\local_videoq\\task\\restore_material']) as $record) {
            $data = json_decode($record->customdata);
            if ((int)($data->courseid ?? 0) === (int)$destination) {
                $tasks[] = [$record, $data]; $taskids[] = (int)$record->id;
            }
        }
        if ($mode === 'unauthorized') {
            $check($tasks === [], 'unauthorized: no deferred source activation is queued');
            unassign_capability('local/videoq:managecontent', $teacherrole->id, $destinationcontext->id);
            accesslib_clear_all_caches(true);
            continue;
        }
        $row = reset($rows);
        $check((int)$row->typeid === $destinationtype && (int)$row->typeid !== $sourcetype
            && (int)$row->cmid === (int)$cm->id && $row->sourcekind === 'youtube' && $row->youtubeid === 'abcDEF123_-'
            && $row->enabled && $row->status === 'restoring' && (int)$row->generation < 1000001
            && $row->fingerprint === '' && !$row->lticmid && !$row->ltiinstance && !$row->linkcmid,
            $mode . ': destination connection and fresh pending identity replace original transport and generation');
        $check(\local_videoq\synchronizer::run((int)$row->id, true, true) === null,
            $mode . ': unfinished restore cannot publish a source manifest');
        $check(count($tasks) === 1 && array_map('intval', $tasks[0][1]->cmids) === [(int)$cm->id],
            $mode . ': one deferred task targets only the new source');
        $entrycount = 0;
        foreach ($DB->get_records('url', ['course' => $destination]) as $candidate) {
            $candidatecm = get_coursemodule_from_instance('url', $candidate->id, $destination, false, MUST_EXIST);
            if ($candidatecm->visible && $candidate->externalurl === $CFG->wwwroot . '/local/videoq/view.php?course=' . $destination) $entrycount++;
            $check(!str_contains($candidate->externalurl, '/local/videoq/view.php?course=' . $source->id . '&')
                && $candidate->externalurl !== $CFG->wwwroot . '/local/videoq/view.php?course=' . $source->id,
                $mode . ': copied shortcut never points back to the source course');
        }
        $check($entrycount === 1, $mode . ': one visible assistant entry points at the destination course');
        $retired = $tasks[0][1]->retired;
        $check(count($retired) === ($full ? 2 : 0), $mode . ': only copied transport and shortcut are retired');
        foreach ($retired as $item) {
            $retiredcm = get_coursemodule_from_id($item->module, (int)$item->cmid, $destination, false, MUST_EXIST);
            $check(!$retiredcm->visible && (int)$retiredcm->id !== (int)$cm->id, $mode . ': copied transport is hidden before deferred deletion');
        }
        // A teacher can opt out before initialization. Running that already
        // queued task must retire only its own clones and never re-enable it.
        \local_videoq\synchronizer::disable((int)$cm->id);
        $task = new \local_videoq\task\restore_material();
        $task->set_custom_data($tasks[0][1]); $task->set_userid($teacherid); $task->execute();
        $after = $DB->get_record('local_videoq_sync', ['id' => $row->id], '*', MUST_EXIST);
        $check(!$after->enabled && !$after->lticmid && !$after->ltiinstance && !$after->linkcmid
            && $DB->record_exists('url', ['id' => $restored->id, 'course' => $destination]),
            $mode . ': delayed initialization preserves an explicit disable and the core URL');
        foreach ($retired as $item) {
            $check(!$DB->record_exists('course_modules', ['id' => (int)$item->cmid]), $mode . ': delayed cleanup deletes its retired clone');
        }
        $check($DB->get_records('local_videoq_sync', ['courseid' => $source->id]) == $original,
            $mode . ': restoring and retiring clones preserves the exact original bindings');
    }
    echo json_encode(['passed' => true, 'database' => $CFG->dbtype, 'checks' => $checks,
        'limits' => ['Real Moodle mbz course/activity restore and core roles; original source binding/readiness is synthetic.',
            'No backend discovery/provider/AI processing, recycle-bin/cross-site restore or completed destination initialization.']],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} finally {
    \core\session\manager::set_user(get_admin());
    foreach ($taskids as $id) $DB->delete_records('task_adhoc', ['id' => $id, 'classname' => '\\local_videoq\\task\\restore_material']);
    foreach ($files as $file) $file->delete();
    foreach (array_reverse($directories) as $path) if (is_dir($path)) fulldelete($path);
    foreach ($courses as $courseid) $DB->delete_records('local_videoq_sync', ['courseid' => $courseid]);
    foreach ($saved as $key => $value) {
        if ($value === false) unset_config($key, 'local_videoq'); else set_config($key, $value, 'local_videoq');
    }
    if ($recycle === false) unset_config('coursebinenable', 'tool_recyclebin'); else set_config('coursebinenable', $recycle, 'tool_recyclebin');
}
