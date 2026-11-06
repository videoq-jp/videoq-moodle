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
 * Manages synchronization for source video activities.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'resource');
require_login($course, false, $cm);
$context = context_module::instance($cmid);
\local_videoq\source_management::require_manager($cmid);
$url = new moodle_url('/local/videoq/manage.php', ['cmid' => $cmid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('managesync', 'local_videoq'));
$PAGE->set_heading(format_string($course->fullname));
if (data_submitted()) {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'enable') {
        \local_videoq\synchronizer::enable($cmid);
    } else if ($action === 'disable') {
        \local_videoq\synchronizer::disable($cmid);
    } else if ($action === 'retry') {
        \local_videoq\synchronizer::retry($cmid);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect($url);
}
$row = $DB->get_record('local_videoq_sync', ['cmid' => $cmid]);
header('Cache-Control: private, no-store');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managesync', 'local_videoq'));
echo html_writer::tag('p', get_string('syncdescription', 'local_videoq'));
echo html_writer::tag(
    'p',
    get_string(
        'syncstatus',
        'local_videoq'
    ) . ': ' . get_string(
        'state_' . ($row->status ?? 'off'),
        'local_videoq'
    )
);
if ($row && $row->errorcode) {
    echo $OUTPUT->notification(get_string('syncerror', 'local_videoq'), 'warning');
}
foreach ($row && $row->enabled ? ['retry', 'disable'] : ['enable'] as $action) {
    echo $OUTPUT->single_button(new moodle_url($url, ['action' => $action]), get_string('sync_' . $action, 'local_videoq'), 'post');
}
if ($row && $row->enabled) {
    echo html_writer::link(
        new moodle_url(
            '/local/videoq/view.php',
            ['course' => $course->id,
            'video' => $cmid]
        ),
        get_string(
            'courseassistant',
            'local_videoq'
        )
    );
}
echo $OUTPUT->footer();
