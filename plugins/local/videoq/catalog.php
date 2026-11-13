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
$courseid = optional_param('course', 0, PARAM_INT);
if (!$courseid) {
    $cmid = required_param('cmid', PARAM_INT);
    [$course, $cm] = get_course_and_cm_from_cmid($cmid);
    $courseid = (int)$course->id;
}
require_login(get_course($courseid));
\local_videoq\course_entry::require_manager($courseid);
redirect(new moodle_url('/local/videoq/teacherconnect.php', ['course' => $courseid]));
