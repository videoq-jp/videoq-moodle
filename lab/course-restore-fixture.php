<?php
// Exercise actual Moodle .mbz processing using only disposable, disabled materials.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new RuntimeException('Isolated lab only');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
\core\session\manager::set_user(get_admin());
$component = 'local_videoq_restore_acceptance';
if (get_config($component, 'courses')) throw new RuntimeException('A previous restore fixture needs cleanup');
$courses = [];
$remember = static function(int $id) use (&$courses, $component): void {
    $courses[] = $id; set_config('courses', json_encode($courses), $component);
};
$run = bin2hex(random_bytes(8));
try {
    $course = create_course((object)['fullname' => 'VideoQ restore acceptance ' . $run,
        'shortname' => 'vqrestore-' . $run, 'category' => 1]);
    $remember((int)$course->id);
    $source = add_moduleinfo((object)['modulename' => 'resource',
        'module' => $DB->get_field('modules', 'id', ['name' => 'resource']), 'course' => $course->id,
        'section' => 0, 'visible' => 1, 'name' => 'Disabled source', 'intro' => '', 'introformat' => FORMAT_HTML,
        'display' => 0, 'printintro' => 0, 'showsize' => 0, 'showtype' => 0, 'showdate' => 0], $course);
    get_file_storage()->create_file_from_pathname(['contextid' => context_module::instance($source->coursemodule)->id,
        'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/',
        'filename' => 'lesson.mp4', 'mimetype' => 'video/mp4', 'sortorder' => 1], '/lab-data/lesson.mp4');
    $DB->insert_record('local_videoq_sync', (object)['typeid' => 2, 'cmid' => $source->coursemodule,
        'courseid' => $course->id, 'lticmid' => 0, 'ltiinstance' => 0, 'linkcmid' => 0,
        'enabled' => 0, 'status' => 'disabled', 'timemodified' => time()]);
    \local_videoq\synchronizer::ensure_course_entry((int)$course->id);
    $bc = new backup_controller(backup::TYPE_1COURSE, $course->id, backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO, backup::MODE_GENERAL, $USER->id);
    $bc->get_plan()->get_setting('users')->set_value(false);
    $backupid = $bc->get_backupid(); $basepath = $bc->get_plan()->get_basepath();
    $bc->execute_plan(); $file = $bc->get_results()['backup_destination']; $bc->destroy();
    if (!file_exists($basepath . '/moodle_backup.xml')) {
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $basepath);
    }
    $xml = file_get_contents($basepath . '/activities/resource_' . $source->coursemodule . '/module.xml');
    if (!str_contains($xml, '<role>source</role>') || !str_contains($xml, '<enabled>0</enabled>')) {
        throw new RuntimeException('Source opt-in metadata missing from actual backup');
    }
    $destination = restore_dbops::create_new_course('Restored acceptance ' . $run, 'vqrestored-' . $run, 1);
    $remember((int)$destination);
    $import = in_array('--merge', $argv, true);
    if ($import) {
        \local_videoq\synchronizer::ensure_course_entry((int)$destination);
        $originalentry = $DB->get_field('url', 'id', ['course' => $destination,
            'externalurl' => $CFG->wwwroot . '/local/videoq/view.php?course=' . $destination], MUST_EXIST);
    }
    $rc = new restore_controller($backupid, $destination, backup::INTERACTIVE_NO,
        backup::MODE_GENERAL, $USER->id, $import ? backup::TARGET_EXISTING_ADDING : backup::TARGET_NEW_COURSE);
    $rc->get_plan()->get_setting('users')->set_value(false);
    if (!$rc->execute_precheck()) {
        $results = $rc->get_precheck_results();
        if (!empty($results['errors'])) throw new RuntimeException('Restore precheck failed');
    }
    $rc->execute_plan(); $rc->destroy();
    $urls = $DB->get_records('url', ['course' => $destination]);
    $visibleurls = array_filter($urls, static fn($url) => get_coursemodule_from_instance('url', $url->id, $destination)->visible);
    if (count($visibleurls) !== 1 || reset($visibleurls)->externalurl !== $CFG->wwwroot . '/local/videoq/view.php?course=' . $destination) {
        throw new RuntimeException('Restored course entry points to wrong course');
    }
    if ($import && !$DB->record_exists('url', ['id' => $originalentry, 'course' => $destination])) throw new RuntimeException('Existing course entry removed');
    if ($DB->record_exists('local_videoq_sync', ['courseid' => $destination])) throw new RuntimeException('Disabled source unexpectedly enabled');
    if (!$DB->record_exists('local_videoq_sync', ['cmid' => $source->coursemodule, 'enabled' => 0])) throw new RuntimeException('Source changed');
    echo json_encode(['passed' => true, 'actualMoodleBackupRestore' => true, 'disabledSourceStaysOff' => true,
        'entryRewrittenToDestination' => true, 'sourcePreserved' => true]);
    $file->delete(); fulldelete($basepath);
} finally {
    foreach (array_reverse($courses) as $id) {
        $course = $DB->get_record('course', ['id' => $id]);
        if (!$course || !str_starts_with($course->shortname, 'vqrestore')) continue;
        delete_course($id, false);
        $DB->delete_records('local_videoq_sync', ['courseid' => $id]);
    }
    unset_all_config_for_plugin($component);
}
