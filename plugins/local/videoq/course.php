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
 * Manages the course-owned VideoQ entry independently of video activities.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
$courseid = required_param('course', PARAM_INT);
$course = get_course($courseid);
require_login($course);
\local_videoq\course_entry::require_manager($courseid);
$url = new moodle_url('/local/videoq/course.php', ['course' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_title(get_string('managecourseentry', 'local_videoq'));
$PAGE->set_heading(format_string($course->fullname));
$failed = false;
if (data_submitted()) {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if (!in_array($action, ['enable', 'disable', 'repair'], true)) {
        throw new moodle_exception('invalidparameter');
    }
    try {
        \local_videoq\course_entry::configure($courseid, $action !== 'disable');
        redirect($url);
    } catch (\Throwable $error) {
        $failed = true;
    }
}
$row = \local_videoq\course_entry::find($courseid);
header('Cache-Control: private, no-store');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managecourseentry', 'local_videoq'));
echo html_writer::link(
    new moodle_url('/local/videoq/catalog.php', ['course' => $courseid]),
    get_string('coursecatalog', 'local_videoq'),
    ['class' => 'btn btn-secondary mb-3']
);
echo html_writer::tag('p', get_string('courseentrydescription', 'local_videoq'));
if ($failed || ($row && $row->errorcode)) {
    echo $OUTPUT->notification(get_string('courseentryerror', 'local_videoq'), 'warning');
}
echo html_writer::tag('p', get_string('syncstatus', 'local_videoq') . ': '
    . get_string('state_' . ($row->status ?? 'off'), 'local_videoq'));
foreach ($row && $row->enabled ? ['repair', 'disable'] : ['enable'] as $action) {
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => $action]),
        get_string('courseentry_' . $action, 'local_videoq'),
        'post'
    );
}
echo html_writer::link(
    new moodle_url('/local/videoq/view.php', ['course' => $courseid]),
    get_string('courseassistant', 'local_videoq')
);
echo html_writer::tag('p', get_string('accountmanagementhelp', 'local_videoq'));
echo html_writer::link(
    new moodle_url('/local/videoq/account.php', ['course' => $courseid]),
    get_string('accountmanagement', 'local_videoq'),
    ['target' => '_blank', 'rel' => 'noopener noreferrer', 'class' => 'btn btn-secondary']
);
echo $OUTPUT->footer();
