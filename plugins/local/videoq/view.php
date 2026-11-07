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
$PAGE->set_course($course);
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_url('/local/videoq/view.php', ['course' => $courseid] + ($videoid ? ['video' => $videoid] : []));
$PAGE->set_title(get_string('courseassistant', 'local_videoq'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();
$PAGE->navbar->ignore_active();
$PAGE->navbar->add(format_string($course->shortname), new moodle_url('/course/view.php', ['id' => $courseid]));
$PAGE->navbar->add(get_string('courseassistant', 'local_videoq'));

$selected = \local_videoq\lesson::for_course($courseid);
if ($videoid) {
    $selected = null;
    if ($DB->record_exists('local_videoq_sync', ['courseid' => $courseid, 'cmid' => $videoid, 'enabled' => 1])) {
        $selected = \local_videoq\lesson::find('sync_' . $videoid);
    } else {
        foreach (\local_videoq\course_access::lessons($courseid) as $item) {
            if ($item['videoCmid'] === $videoid) {
                $selected = \local_videoq\lesson::find($item['id']);
                break;
            }
        }
    }
    if (!$selected) {
        throw new moodle_exception('unknownlesson', 'local_videoq');
    }
}
if ($selected) {
    [$sourcecourse, $sourcecm] = \local_videoq\source::course($selected['videoCmid']);
    [$lticourse, $lticm] = get_course_and_cm_from_cmid($selected['ltiCmid'], 'lti');
    if (
        (int)$sourcecourse->id !== $courseid || (int)$lticourse->id !== $courseid ||
        $sourcecm->deletioninprogress || !$sourcecm->uservisible || $lticm->deletioninprogress || !$lticm->uservisible ||
        !\local_videoq\source::matches($sourcecm, $selected, true)
    ) {
        throw new moodle_exception('invalidmodules', 'local_videoq');
    }
    require_capability(\local_videoq\source::view_capability($sourcecm), context_module::instance($sourcecm->id));
    require_capability('mod/lti:view', context_module::instance($lticm->id));
}

$lessonid = $selected['id'] ?? '';
$connected = true;
try {
    $scope = \local_videoq\native_api::scope($courseid, $lessonid);
} catch (moodle_exception $error) {
    $scope = ['resources' => []];
    $connected = false;
}
$catalog = [];
$managedsources = [];
$resources = $scope['resources'];
// Pending source playback still belongs to Moodle. It grants no learning API access.
if ($selected && !array_filter($resources, static fn($item) => $item['lesson'] === $lessonid)) {
    $resources[] = ['lesson' => $lessonid, 'title' => $selected['title']];
}
foreach ($resources as $allowed) {
    $item = \local_videoq\lesson::find($allowed['lesson']);
    $descriptor = \local_videoq\source::read((int)$item['videoCmid']);
    if (!$descriptor) {
        continue;
    }
    $media = $descriptor['file'] ?? null;
    $module = $descriptor['kind'] === 'youtube' ? 'url' : 'resource';
    $fileurl = $media ? new moodle_url('/mod/resource/view.php', ['id' => $item['videoCmid'], 'redirect' => 1]) : null;
    $catalog[] = ['kind' => $descriptor['kind'], 'youtubeVideoId' => $descriptor['youtubeVideoId'] ?? null,
        'sourceGeneration' => (int)($allowed['generation'] ?? 0),
        'mime' => $media ? $media->get_mimetype() : 'video/mp4', 'lesson' => $item['id'], 'title' => $allowed['title'],
        'url' => $fileurl ? $fileurl->out(false) : null,
        'activityUrl' => (new moodle_url('/mod/' . $module . '/view.php', ['id' => $item['videoCmid']]))->out(false)];
    if (isset($allowed['management'])) {
        $links = [];
        $actions = [
            [new moodle_url('/course/modedit.php', ['update' => $item['videoCmid']]), 'sourceedit'],
            [new moodle_url('/local/videoq/manage.php', ['cmid' => $item['videoCmid']]), 'sourcesync'],
            [new moodle_url('/course/view.php', ['id' => $courseid], 'module-' . $item['videoCmid']), 'sourcecourse'],
        ];
        foreach ($actions as [$url, $label]) {
            $links[] = ['url' => $url->out(false), 'label' => get_string($label, 'local_videoq')];
        }
        $managedsources[] = ['title' => $allowed['title'], 'links' => $links];
    }
}
if ($catalog && !$lessonid) {
    $lessonid = $catalog[0]['lesson'];
}
foreach (['styles.css', 'player.js', 'learning.js'] as $asset) {
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
if (
    $courseid !== SITEID && has_capability('local/videoq:managecontent', context_course::instance($courseid)) &&
    has_capability('moodle/course:manageactivities', context_course::instance($courseid))
) {
    foreach (['catalog.php' => 'coursecatalog', 'course.php' => 'managecourseentry'] as $path => $label) {
        echo html_writer::link(
            new moodle_url('/local/videoq/' . $path, ['course' => $courseid]),
            get_string($label, 'local_videoq'),
            ['class' => 'btn btn-secondary me-2 mb-3']
        );
    }
}
if (!$connected) {
    echo $OUTPUT->notification(get_string('nativeunavailable', 'local_videoq'), 'info');
}
if (!$catalog) {
    echo $OUTPUT->notification(get_string('courseentryempty', 'local_videoq'), 'info');
}
if (!$connected || (isset($selected['syncStatus']) && $selected['syncStatus'] !== 'completed')) {
    if (isset($selected['syncStatus'])) {
        echo $OUTPUT->notification(get_string('state_' . $selected['syncStatus'], 'local_videoq'), 'info');
    }
    echo html_writer::link($PAGE->url, get_string('refreshstatus', 'local_videoq'), ['class' => 'btn btn-secondary mb-3']);
}
$workspace = new \local_videoq\output\workspace($courseid, $catalog, $lessonid, $managedsources, $connected);
echo $OUTPUT->render_from_template('local_videoq/workspace', $workspace->export_for_template($OUTPUT));
echo $OUTPUT->footer();
