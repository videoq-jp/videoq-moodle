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
[$course, $cm] = \local_videoq\source::course($cmid);
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
$PAGE->activityheader->disable();
echo $OUTPUT->header();
$enabled = $row && $row->enabled;
$status = $row->status ?? 'off';
$ready = $enabled && $status === 'completed';
$failed = $enabled && in_array($status, ['error', 'blocked'], true);
if (!$enabled) {
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'enable']),
        get_string('sync_enable', 'local_videoq'),
        'post',
        ['type' => 'primary']
    );
} else {
    echo html_writer::start_div('d-flex flex-wrap align-items-center gap-3 mb-3');
    echo html_writer::tag('span', get_string('state_' . $status, 'local_videoq'), [
        'class' => 'badge ' . ($ready ? 'bg-success' : ($failed ? 'bg-danger' : 'bg-secondary')) . ' text-white',
        'role' => 'status',
    ]);
    if ($ready) {
        echo html_writer::link(
            new moodle_url('/local/videoq/view.php', ['course' => $course->id, 'video' => $cmid]),
            get_string('askvideo', 'local_videoq'),
            ['class' => 'btn btn-primary']
        );
    }
    echo html_writer::end_div();
    if ($failed) {
        if ($row->errorcode) {
            $message = $row->errorcode === 'YOUTUBE_DISABLED' ? 'youtubedisabled' : 'syncerror';
            echo $OUTPUT->notification(get_string($message, 'local_videoq'), 'warning');
        }
        echo $OUTPUT->single_button(
            new moodle_url($url, ['action' => 'retry']),
            get_string('sync_retry', 'local_videoq'),
            'post',
            ['type' => 'primary']
        );
    } else if (!$ready) {
        echo html_writer::link($url, get_string('refreshstatus', 'local_videoq'), ['class' => 'btn btn-secondary']);
    }
    echo html_writer::start_div('mt-4');
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'disable']),
        get_string('sync_disable', 'local_videoq'),
        'post'
    );
    echo html_writer::end_div();
}
echo $OUTPUT->footer();
