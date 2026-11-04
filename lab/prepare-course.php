<?php
// Original multi-video course fixtures for the isolated lab only.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') throw new Exception('Use only the isolated lab');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');
\core\session\manager::set_user(get_admin());
$course = $DB->get_record('course', ['shortname' => 'VIDEOQ-LTI-LAB'], '*', MUST_EXIST);
$type = $DB->get_record('lti_types', ['name' => 'VideoQ lab'], '*', MUST_EXIST);
$lessons = array_values(\local_videoq\lesson::parse(get_config('local_videoq', 'lessons') ?: '[]'));
$fixtures = [];
foreach ([['course-review', '第2回：講座を使った復習', 'course-review.mp4', 1], ['course-hidden', '未公開の補足教材', 'course-hidden.mp4', 0]] as [$id, $title, $file, $visible]) {
    $video = $DB->get_record('resource', ['course' => $course->id, 'name' => $title]);
    if (!$video) {
        $module = add_moduleinfo((object)['modulename' => 'resource', 'module' => $DB->get_field('modules', 'id', ['name' => 'resource']),
            'course' => $course->id, 'section' => 0, 'visible' => $visible, 'name' => $title,
            'intro' => '24秒の無音デモ教材です。', 'introformat' => FORMAT_HTML,
            'display' => 0, 'printintro' => 0, 'showsize' => 0, 'showtype' => 0, 'showdate' => 0], $course);
        $video = $DB->get_record('resource', ['id' => $module->instance], '*', MUST_EXIST);
    }
    $videocm = get_coursemodule_from_instance('resource', $video->id, $course->id, false, MUST_EXIST);
    $lti = $DB->get_record('lti', ['course' => $course->id, 'name' => $title . '：質問']);
    if (!$lti) {
        $module = add_moduleinfo((object)['modulename' => 'lti', 'module' => $DB->get_field('modules', 'id', ['name' => 'lti']),
            'course' => $course->id, 'section' => 0, 'visible' => $visible, 'name' => $title . '：質問',
            'intro' => '', 'introformat' => FORMAT_HTML, 'typeid' => $type->id,
            'toolurl' => 'https://videoq.localhost:18443/api/lti/launch', 'launchcontainer' => 3,
            'showtitlelaunch' => 0, 'showdescriptionlaunch' => 0,
            'instructorchoicesendname' => 0, 'instructorchoicesendemailaddr' => 0, 'instructorchoiceacceptgrades' => 0], $course);
        $lti = $DB->get_record('lti', ['id' => $module->instance], '*', MUST_EXIST);
    }
    $lticm = get_coursemodule_from_instance('lti', $lti->id, $course->id, false, MUST_EXIST);
    $fs = get_file_storage(); $ctx = context_module::instance($videocm->id);
    if (!$fs->file_exists($ctx->id, 'mod_resource', 'content', 0, '/', $file)) {
        $fs->create_file_from_pathname(['contextid' => $ctx->id, 'component' => 'mod_resource', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => $file, 'mimetype' => 'video/mp4', 'sortorder' => 1], '/tmp/' . $file);
    }
    $entry = ['id' => $id, 'title' => $title, 'videoCmid' => (int)$videocm->id, 'ltiCmid' => (int)$lticm->id];
    $lessons = array_values(array_filter($lessons, fn($item) => $item['id'] !== $id));
    $lessons[] = $entry;
    $fixtures[] = $entry + ['resourceLinkId' => (string)$lti->id];
}
set_config('lessons', json_encode($lessons), 'local_videoq');
rebuild_course_cache($course->id, true);
echo json_encode(['courseId' => (int)$course->id, 'lessons' => $fixtures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
