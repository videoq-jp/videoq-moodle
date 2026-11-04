<?php
// Reversible, idempotent presentation cleanup for the isolated demo course.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') {
    throw new Exception('Use only the isolated LTI lab Moodle');
}
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
\core\session\manager::set_user(get_admin());
$course = $DB->get_record('course', ['shortname' => 'VIDEOQ-LTI-LAB'], '*', MUST_EXIST);
// Moodle's supported "available but not shown on course page" visibility mode.
set_config('allowstealth', 1);
$sections = [];
foreach (['第1章：LMSとVideoQ', '第2章：講座を使った復習', '実習：アップロードした教材で質問', '補足教材（準備中）'] as $name) {
    $section = $DB->get_record('course_sections', ['course' => $course->id, 'name' => $name]);
    if (!$section) {
        $section = course_create_section($course);
        course_update_section($course, $section, (object)['name' => $name]);
        $section = $DB->get_record('course_sections', ['id' => $section->id], '*', MUST_EXIST);
    }
    $sections[] = $section;
}
set_section_visible($course->id, $sections[3]->section, 0);
$manual = \local_videoq\lesson::parse(get_config('local_videoq', 'lessons'));
$entries = [];
foreach (\local_videoq\course_access::lessons((int)$course->id) as $lesson) {
    $video = get_coursemodule_from_id('resource', $lesson['videoCmid'], $course->id, false, MUST_EXIST);
    $lti = get_coursemodule_from_id('lti', $lesson['ltiCmid'], $course->id, false, MUST_EXIST);
    $sync = $DB->get_record('local_videoq_sync', ['cmid' => $video->id, 'enabled' => 1]);
    $index = !$video->visible ? 3 : ((int)$video->id === $manual['lms-videoq']['videoCmid'] ? 0 : ((int)$video->id === $manual['course-review']['videoCmid'] ? 1 : 2));
    $section = $sections[$index];
    $url = $CFG->wwwroot . '/local/videoq/view.php?lesson=' . $lesson['id'];
    $link = $sync ? get_coursemodule_from_id('url', $sync->linkcmid, $course->id, false, MUST_EXIST) : null;
    if (!$link) {
        $instance = $DB->get_record('url', ['course' => $course->id, 'externalurl' => $url]);
        if ($instance) $link = get_coursemodule_from_instance('url', $instance->id, $course->id, false, MUST_EXIST);
    }
    if (!$link) {
        $added = add_moduleinfo((object)[
            'modulename' => 'url', 'module' => $DB->get_field('modules', 'id', ['name' => 'url']),
            'course' => $course->id, 'section' => $section->section, 'visible' => $video->visible,
            'availability' => $video->availability, 'name' => $video->name . '：動画を見ながら質問する',
            'intro' => '', 'introformat' => FORMAT_HTML, 'externalurl' => $url, 'display' => 0,
            'printintro' => 0, 'parameters' => [],
        ], $course);
        $link = get_coursemodule_from_id('url', $added->coursemodule, $course->id, false, MUST_EXIST);
    }
    foreach ([$video, $lti, $link] as $cm) {
        if ((int)$cm->section !== (int)$section->id) moveto_module($cm, $section);
        set_coursemodule_visible($cm->id, (int)$video->visible, (int)($cm->id === $video->id));
    }
    $entries[] = ['lesson' => $lesson['id'], 'section' => $section->name, 'entryCmid' => (int)$link->id,
        'sourceCmid' => (int)$video->id, 'ltiCmid' => (int)$lti->id, 'visible' => (bool)$video->visible];
}
\local_videoq\synchronizer::ensure_course_entry((int)$course->id);
$common = $DB->get_record('url', ['course' => $course->id, 'externalurl' => $CFG->wwwroot . '/local/videoq/view.php?course=' . $course->id], '*', MUST_EXIST);
$assistantcmid = (int)get_coursemodule_from_instance('url', $common->id, $course->id, false, MUST_EXIST)->id;
set_coursemodule_name($assistantcmid, 'AIアシスタント（講座全体に質問）');
// Retire the old shared entry bound to a particular video.
$old = $DB->get_record('url', ['course' => $course->id, 'externalurl' => $CFG->wwwroot . '/local/videoq/view.php?lesson=lms-videoq']);
if ($old) set_coursemodule_visible(get_coursemodule_from_instance('url', $old->id, $course->id)->id, 1, 0);
// Keep standalone interoperability fixtures accessible, without learner-facing duplicates.
foreach (['VideoQ new window', 'Unbound questions'] as $name) {
    $instance = $DB->get_record('lti', ['course' => $course->id, 'name' => $name]);
    if ($instance) {
        $cm = get_coursemodule_from_instance('lti', $instance->id, $course->id, false, MUST_EXIST);
        if ((int)$cm->section !== (int)$sections[0]->id) moveto_module($cm, $sections[0]);
        set_coursemodule_visible($cm->id, (int)$cm->visible, 0);
    }
}
rebuild_course_cache($course->id, true);
echo json_encode(['courseId' => (int)$course->id, 'assistantCmid' => $assistantcmid, 'entries' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
