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
 * Lists video sources and permitted canonical Moodle management workflows.
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
$PAGE->set_url('/local/videoq/catalog.php', ['course' => $courseid]);
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_title(get_string('coursecatalog', 'local_videoq'));
$PAGE->set_heading(format_string($course->fullname));
header('Cache-Control: private, no-store');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursecatalog', 'local_videoq'));
echo html_writer::tag('p', get_string('cataloghelp', 'local_videoq'));
echo html_writer::start_div('d-flex flex-wrap gap-2 mb-3');
foreach (\local_videoq\course_catalog::actions($courseid) as $label => $url) {
    echo html_writer::link($url, get_string($label, 'local_videoq'), ['class' => 'btn btn-secondary']);
}
echo html_writer::end_div();
$sources = \local_videoq\course_catalog::sources($courseid);
if (!$sources) {
    echo $OUTPUT->notification(get_string('catalogempty', 'local_videoq'), 'info');
}
echo html_writer::start_tag('ol', ['class' => 'local-videoq-source-catalog']);
foreach ($sources as $source) {
    echo html_writer::start_tag('li', ['class' => 'mb-3', 'data-cmid' => $source['cmid']]);
    echo html_writer::tag('p', s($source['title']), ['class' => 'fw-semibold mb-1 text-break']);
    echo html_writer::tag('p', get_string('syncstatus', 'local_videoq') . ': '
        . get_string('state_' . $source['status'], 'local_videoq'));
    echo html_writer::start_div('d-flex flex-wrap gap-2');
    $links = [
        'sourceedit' => new moodle_url('/course/modedit.php', ['update' => $source['cmid']]),
        'sourcesync' => new moodle_url('/local/videoq/manage.php', ['cmid' => $source['cmid']]),
    ];
    foreach ($links as $label => $url) {
        echo html_writer::link($url, get_string($label, 'local_videoq'), ['class' => 'btn btn-secondary']);
    }
    echo html_writer::end_div();
    echo html_writer::end_tag('li');
}
echo html_writer::end_tag('ol');
echo $OUTPUT->footer();
