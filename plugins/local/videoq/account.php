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
 * Opens the connected VideoQ account without delegating Moodle authority.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
$courseid = optional_param('course', 0, PARAM_INT);
if ($courseid > 0) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    require_capability('local/videoq:managecontent', $context);
} else {
    require_login();
    $context = context_system::instance();
    require_capability('moodle/site:config', $context);
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/videoq/account.php', $courseid > 0 ? ['course' => $courseid] : []));
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
try {
    $target = \local_videoq\account_management::url();
} catch (\Throwable $error) {
    // Discovery/DB errors can contain credentials. Use a fixed local message.
    throw new moodle_exception('accountunavailable', 'local_videoq');
}
redirect($target);
