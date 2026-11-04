<?php
// A disposable, unenrolled course exercises managed-activity repair without touching real lessons.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Lab only');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
\core\session\manager::set_user(get_admin());
$action = $argv[1] ?? 'state';
$course = $DB->get_record('course', ['shortname' => 'VIDEOQ-MANAGED-REPAIR']);
if ($action === 'setup' && !$course) {
    $course = create_course((object)['fullname' => 'VideoQ internal repair acceptance', 'shortname' => 'VIDEOQ-MANAGED-REPAIR', 'category' => 1, 'format' => 'topics']);
    $section = course_create_section($course);
    $added = add_moduleinfo((object)['modulename' => 'resource', 'module' => $DB->get_field('modules', 'id', ['name' => 'resource']),
        'course' => $course->id, 'section' => $section->section, 'visible' => 1, 'name' => 'Managed repair fixture',
        'intro' => '', 'introformat' => FORMAT_HTML, 'display' => 0, 'printintro' => 0,
        'showsize' => 0, 'showtype' => 0, 'showdate' => 0], $course);
    get_file_storage()->create_file_from_storedfile(['contextid' => context_module::instance($added->coursemodule)->id,
        'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/',
        'filename' => 'managed-repair.mp4', 'mimetype' => 'video/mp4', 'sortorder' => 1], \local_videoq\synchronizer::video_file(5));
    \local_videoq\synchronizer::enable((int)$added->coursemodule);
}
if (!$course) throw new Exception('Disposable course missing');
$row = $DB->get_record('local_videoq_sync', ['courseid' => $course->id], '*', MUST_EXIST);
if ($action === 'url' || $action === 'lti') {
    course_delete_module($action === 'url' ? $row->linkcmid : $row->lticmid, false);
} else if ($action === 'section') {
    $source = get_coursemodule_from_id('resource', $row->cmid, $course->id, false, MUST_EXIST);
    $old = $DB->get_record('course_sections', ['id' => $source->section], '*', MUST_EXIST);
    if (!$old->section) throw new Exception('Never delete General');
    $next = course_create_section($course);
    moveto_module($source, $next);
    course_delete_section($course, $old, true, false);
} else if ($action === 'disable') {
    \local_videoq\synchronizer::disable((int)$row->cmid);
} else if ($action === 'source') {
    course_delete_module((int)$row->cmid, false);
} else if ($action === 'cleanup') {
    delete_course($course, false);
}
if ($action !== 'state' && $action !== 'source') \local_videoq\synchronizer::run((int)$row->id);
$row = $DB->get_record('local_videoq_sync', ['id' => $row->id], '*', MUST_EXIST);
echo json_encode(['courseid' => (int)$course->id, 'cmid' => (int)$row->cmid, 'lticmid' => (int)$row->lticmid,
    'linkcmid' => (int)$row->linkcmid, 'enabled' => (bool)$row->enabled, 'status' => $row->status,
    'sourceExists' => $DB->record_exists('course_modules', ['id' => $row->cmid]),
    'ltiExists' => $DB->record_exists('course_modules', ['id' => $row->lticmid]),
    'linkExists' => $DB->record_exists('course_modules', ['id' => $row->linkcmid])]) . "\n";
