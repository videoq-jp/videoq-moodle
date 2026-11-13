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
require_login();
if (!$courseid) {
    $legacy = \local_videoq\lesson::find(required_param('lesson', PARAM_ALPHANUMEXT));
    [$legacycourse, $legacycm] = \local_videoq\source::course($legacy['videoCmid']);
    require_login($legacycourse, false, $legacycm);
    require_capability(\local_videoq\source::view_capability($legacycm), context_module::instance($legacycm->id));
    redirect(new moodle_url('/local/videoq/view.php', ['course' => $legacycourse->id, 'video' => $legacycm->id]));
}
$course = get_course($courseid);
require_login($course);

$lessonid = '';
$catalog = [];
$managedsources = [];
$connected = false;
try {
    $result = \local_videoq\native_api::request($courseid, '', 'materials', []);
    $materials = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR);
    $connected = $result['status'] === 200;
    if ($connected) {
        foreach ($materials['videos'] ?? [] as $video) {
            $catalog[] = ['videoId' => (int)$video['videoId'], 'lesson' => (string)$video['videoId'],
                'title' => $video['title'], 'kind' => 'linked'];
        }
        $lessonid = $catalog[0]['lesson'] ?? '';
    }
} catch (Throwable $error) {
    $connected = false;
}
// Finish every login/course check before activityheader initializes the theme.
$PAGE->set_course($course);
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_url('/local/videoq/view.php', ['course' => $courseid] + ($videoid ? ['video' => $videoid] : []));
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('courseassistant', 'local_videoq'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();
$PAGE->navbar->ignore_active();
$PAGE->navbar->add(format_string($course->shortname), new moodle_url('/course/view.php', ['id' => $courseid]));
$PAGE->navbar->add(get_string('courseassistant', 'local_videoq'));
foreach (['styles.css', 'course-history.js', 'player.js', 'learning.js', 'layout.js'] as $asset) {
    $url = new moodle_url('/local/videoq/' . $asset, ['rev' => substr(sha1_file(__DIR__ . '/' . $asset), 0, 12)]);
    if (str_ends_with($asset, '.css')) {
        $PAGE->requires->css($url);
    } else {
        $PAGE->requires->js($url);
    }
}
$PAGE->requires->js_call_amd('core/auto_rows', 'init', ['#videoq-native-chat']);
header('Cache-Control: private, no-store');
echo $OUTPUT->header();
$canconnect = $courseid !== SITEID && has_capability('local/videoq:managecontent', context_course::instance($courseid)) &&
    has_capability('moodle/course:manageactivities', context_course::instance($courseid));
if (!$connected) {
    echo $OUTPUT->notification(get_string('nativeunavailable', 'local_videoq'), 'info');
}
if (!$catalog) {
    echo $OUTPUT->notification(get_string('courseentryempty', 'local_videoq'), 'info');
}
$workspace = new \local_videoq\output\workspace(
    $courseid,
    $catalog,
    $lessonid,
    $managedsources,
    $connected,
    $materials['courseTitle'] ?? $course->fullname,
    $canconnect
);
echo $OUTPUT->render_from_template('local_videoq/workspace', $workspace->export_for_template($OUTPUT));
echo $OUTPUT->footer();
