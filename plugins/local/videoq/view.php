<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
// SPDX-License-Identifier: GPL-3.0-or-later.

/**
 * Displays the Moodle video player and VideoQ assistant.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
$courseid = optional_param('course', 0, PARAM_INT);
$videoid = optional_param('video', 0, PARAM_INT);
$lessonid = optional_param('lesson', '', PARAM_ALPHANUMEXT);
require_login();
if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $PAGE->set_url('/local/videoq/view.php', ['course' => $courseid] + ($videoid ? ['video' => $videoid] : []));
    $PAGE->set_context(context_course::instance($courseid));
    $PAGE->set_title(get_string('courseassistant', 'local_videoq'));
    $PAGE->set_heading(format_string($course->fullname));
    $lesson = \local_videoq\lesson::for_course($courseid);
    if ($videoid) {
        $lesson = null;
        if ($DB->record_exists('local_videoq_sync', ['courseid' => $courseid, 'cmid' => $videoid, 'enabled' => 1])) {
            $lesson = \local_videoq\lesson::find('sync_' . $videoid);
        } else {
            foreach (\local_videoq\course_access::lessons($courseid) as $item) {
                if ($item['videoCmid'] === $videoid) {
                    $lesson = \local_videoq\lesson::find($item['id']);
                    break;
                }
            }
        }
        if (!$lesson) {
            throw new moodle_exception('unknownlesson', 'local_videoq');
        }
    }
    if (!$lesson) {
        header('Cache-Control: private, no-store');
        echo $OUTPUT->header();
        echo $OUTPUT->notification(get_string('nocoursevideos', 'local_videoq'), 'info');
        echo $OUTPUT->footer();
        exit;
    }
    $lessonid = $lesson['id'];
} else {
    $lessonid = required_param('lesson', PARAM_ALPHANUMEXT);
    $PAGE->set_url('/local/videoq/view.php', ['lesson' => $lessonid]);
    $lesson = \local_videoq\lesson::find($lessonid);
}
$lessonid = $lesson['id'];
[$course, $lticm] = get_course_and_cm_from_cmid($lesson['ltiCmid'], 'lti');
[$videocourse, $videocm] = get_course_and_cm_from_cmid($lesson['videoCmid'], 'resource');
if ($course->id !== $videocourse->id || ($courseid && (int)$course->id !== $courseid)) {
    throw new moodle_exception('invalidmodules', 'local_videoq');
}
// Core checks enrolment, hidden activities, conditional availability and deletion.
require_login($course, false, $videocm);
require_capability('mod/resource:view', context_module::instance($videocm->id));
require_login($course, false, $lticm);
require_capability('mod/lti:view', context_module::instance($lticm->id));
// Legacy per-video bookmarks lead to the same course assistant, preselecting the video.
if (!$courseid) {
    redirect(new moodle_url('/local/videoq/view.php', ['course' => $course->id, 'video' => $videocm->id]));
}
require_once($CFG->dirroot . '/mod/lti/locallib.php');
$lti = $DB->get_record('lti', ['id' => $lticm->instance], '*', MUST_EXIST);
$type = $DB->get_record('lti_types', ['id' => $lti->typeid], '*', MUST_EXIST);
if (
    $type->ltiversion !== LTI_VERSION_1P3 || !in_array(
        (int)lti_get_launch_container($lti, lti_get_type_type_config($type->id)),
        [LTI_LAUNCH_CONTAINER_EMBED, LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS],
        true
    )
) {
    throw new moodle_exception('invalidmodules', 'local_videoq');
}
$PAGE->activityheader->disable();
$pagetitle = $courseid ? get_string('courseassistant', 'local_videoq') : format_string($lesson['title']);
$PAGE->set_title($pagetitle);
$PAGE->set_context(context_course::instance($course->id));
$PAGE->set_heading($courseid ? format_string($course->fullname) : format_string($lesson['title']));
$PAGE->requires->css(new moodle_url('/local/videoq/styles.css', ['rev' => substr(sha1_file(__DIR__ . '/styles.css'), 0, 12)]));
$PAGE->requires->js(new moodle_url('/local/videoq/player.js', ['rev' => substr(sha1_file(__DIR__ . '/player.js'), 0, 12)]));
$PAGE->requires->js(new moodle_url('/local/videoq/chat.js', ['rev' => substr(sha1_file(__DIR__ . '/chat.js'), 0, 12)]));
$PAGE->requires->js_call_amd('core/auto_rows', 'init', ['#videoq-native-chat']);
$PAGE->navbar->ignore_active();
$PAGE->navbar->add(format_string($course->shortname), new moodle_url('/course/view.php', ['id' => $course->id]));
$PAGE->navbar->add($pagetitle);
// The resource module serves its own file and enforces file access on every request.
$videourl = new moodle_url('/mod/resource/view.php', ['id' => $videocm->id, 'redirect' => 1]);
$launchurl = new moodle_url('/mod/lti/launch.php', ['id' => $lticm->id]);
$toolparts = parse_url($type->baseurl);
if (
    !$toolparts || ($toolparts['scheme'] ?? '') !== 'https' || empty($toolparts['host'])
        || isset($toolparts['user']) || isset($toolparts['pass'])
) {
    throw new moodle_exception('invalidmodules', 'local_videoq');
}
$toolorigin = 'https://' . strtolower($toolparts['host']) . (isset($toolparts['port']) && $toolparts['port'] !== 443 ? ':' . $toolparts['port'] : '');
header('Cache-Control: private, no-store');
echo $OUTPUT->header();

// Render only activities the current learner may view. API independently rechecks this on each question.
$scope = ['resources' => [['lesson' => $lessonid, 'title' => $lesson['title']]]];
if (!isset($lesson['syncStatus']) || $lesson['syncStatus'] === 'completed') {
    $launchproof = \local_videoq\course_access::launch((int)$lti->id, (int)$USER->id);
    $scope = \local_videoq\course_access::resolve($launchproof['custom_videoq_access'], (string)$USER->id, (string)$course->id, (string)$lti->id);
}
$catalog = [];
foreach ($scope['resources'] as $allowed) {
    $item = \local_videoq\lesson::find($allowed['lesson']);
    $media = \local_videoq\synchronizer::video_file((int)$item['videoCmid']);
    $catalog[] = ['mime' => $media ? $media->get_mimetype() : 'video/mp4', 'lesson' => $item['id'], 'title' => $allowed['title'],
        'url' => (new moodle_url('/mod/resource/view.php', ['id' => $item['videoCmid'], 'redirect' => 1]))->out(false),
        'activityUrl' => (new moodle_url('/mod/resource/view.php', ['id' => $item['videoCmid']]))->out(false)];
}
echo html_writer::start_div('local-videoq-workspace', ['data-videoq-origin' => $toolorigin,
    'data-videoq-lessons' => json_encode($catalog, JSON_THROW_ON_ERROR), 'data-videoq-lesson' => $lessonid]);
echo html_writer::start_tag('section', ['class' => 'local-videoq-panel local-videoq-video card', 'aria-label' => get_string('video', 'local_videoq')]);
echo html_writer::start_div('card-body py-3');
echo html_writer::tag('label', get_string('video', 'local_videoq'), ['for' => 'videoq-lesson', 'class' => 'form-label']);
$options = [];
foreach ($catalog as $item) {
    $options[$item['lesson']] = $item['title'];
}
echo html_writer::select($options, 'videoq-lesson', $lessonid, false, ['id' => 'videoq-lesson', 'class' => 'form-select']);
echo html_writer::end_div();
echo html_writer::start_div('local-videoq-media mediaplugin mediaplugin_videojs');
$sourcefile = \local_videoq\synchronizer::video_file((int)$videocm->id);
echo html_writer::tag('video', html_writer::empty_tag('source', ['src' => $videourl->out(false), 'type' => $sourcefile ? $sourcefile->get_mimetype() : 'video/mp4']), [
    'id' => 'videoq-player', 'class' => get_config('media_videojs', 'videocssclass') ?: 'video-js',
    'controls' => 'controls', 'playsinline' => 'playsinline', 'preload' => 'metadata',
    'data-setup-lazy' => json_encode([
        'fluid' => true, 'playbackRates' => [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2],
        'userActions' => ['hotkeys' => true], 'language' => current_language(),
    ]),
    'aria-label' => get_string('video', 'local_videoq'),
]);
echo html_writer::end_div();
echo html_writer::end_tag('section');
echo html_writer::start_tag('section', ['class' => 'local-videoq-panel local-videoq-questions card', 'aria-label' => get_string('questions', 'local_videoq')]);
echo html_writer::tag('h2', get_string('questions', 'local_videoq'), ['class' => 'visually-hidden']);
if (isset($lesson['syncStatus']) && $lesson['syncStatus'] !== 'completed') {
    echo html_writer::tag('p', get_string('state_' . $lesson['syncStatus'], 'local_videoq'), ['role' => 'status']);
    echo html_writer::link($PAGE->url, get_string('refreshstatus', 'local_videoq'));
} else {
    $message = ['id' => '', 'text' => '', 'formattedtime' => userdate(time(), get_string('strftimetime', 'langconfig'))];
    echo $OUTPUT->render_from_template('local_videoq/chat', [
    'heading' => get_string('coursequestions', 'local_videoq'),
    'connectionerror' => get_string('chatconnectionerror', 'local_videoq'),
    'seeksuffix' => get_string('chatseek', 'local_videoq'),
    'empty' => get_string('chatempty', 'local_videoq'),
    'connecting' => get_string('chatconnecting', 'local_videoq'),
    'hint' => get_string('chathint', 'local_videoq'),
    'messagemaxlength' => 4000, 'showemojipicker' => false,
    'questionmessage' => $message + ['fromloggedinuser' => true],
    'answermessage' => $message + ['fromloggedinuser' => false, 'userfrom' => [
        'fullname' => get_string('assistant', 'local_videoq'), 'profileimageurl' => $OUTPUT->image_url('i/course', 'core')->out(false),
    ]],
    ]);
    echo html_writer::tag('iframe', '', [
    'id' => 'videoq-questions', 'class' => 'local-videoq-transport', 'tabindex' => '-1', 'aria-hidden' => 'true', 'title' => get_string('questions', 'local_videoq'),
    'src' => $launchurl->out(false), 'allow' => 'clipboard-write',
    'referrerpolicy' => 'strict-origin-when-cross-origin',
    ]);
}
echo html_writer::end_tag('section');
echo html_writer::end_div();
echo $OUTPUT->footer();
