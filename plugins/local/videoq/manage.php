<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
require_once(__DIR__ . '/../../config.php');
$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'resource');
require_login($course, false, $cm);
$context = context_module::instance($cmid);
require_capability('moodle/course:manageactivities', $context);
$url = new moodle_url('/local/videoq/manage.php', ['cmid' => $cmid]);
$PAGE->set_url($url); $PAGE->set_context($context);
$PAGE->set_title(get_string('managesync', 'local_videoq')); $PAGE->set_heading(format_string($course->fullname));
if (data_submitted()) {
    require_sesskey(); $action = required_param('action', PARAM_ALPHA);
    if ($action === 'enable') \local_videoq\synchronizer::enable($cmid);
    else if ($action === 'disable') \local_videoq\synchronizer::disable($cmid);
    else if ($action === 'retry') $DB->execute('UPDATE {local_videoq_sync} SET generation = generation + 1, status = ?, timemodified = ? WHERE cmid = ?', ['queued', time(), $cmid]);
    else throw new moodle_exception('invalidparameter');
    redirect($url);
}
$row = $DB->get_record('local_videoq_sync', ['cmid' => $cmid]);
header('Cache-Control: private, no-store');
echo $OUTPUT->header(); echo $OUTPUT->heading(get_string('managesync', 'local_videoq'));
echo html_writer::tag('p', get_string('syncdescription', 'local_videoq'));
echo html_writer::tag('p', get_string('syncstatus', 'local_videoq') . ': ' . get_string('state_' . ($row->status ?? 'off'), 'local_videoq'));
if ($row && $row->errorcode) echo $OUTPUT->notification(get_string('syncerror', 'local_videoq'), 'warning');
foreach ($row && $row->enabled ? ['retry', 'disable'] : ['enable'] as $action) {
    echo $OUTPUT->single_button(new moodle_url($url, ['action' => $action]), get_string('sync_' . $action, 'local_videoq'), 'post');
}
if ($row && $row->enabled) echo html_writer::link(new moodle_url('/local/videoq/view.php', ['course' => $course->id, 'video' => $cmid]), get_string('courseassistant', 'local_videoq'));
echo $OUTPUT->footer();
