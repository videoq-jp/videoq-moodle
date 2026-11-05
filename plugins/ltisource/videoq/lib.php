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
 * Provides Moodle callbacks for the VideoQ integration.
 *
 * @package ltisource_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Moodle signs this custom claim as part of the normal LTI 1.3 launch.
 */
function ltisource_videoq_before_launch($instance, $endpoint, $params) {
    global $USER;
    try {
        return \local_videoq\course_access::launch((int)$instance->id, (int)$USER->id);
    } catch (\Throwable $error) {
        // A VideoQ configuration error must not disrupt unrelated LTI tools.
        // VideoQ registrations requiring a proof will reject the launch safely.
        return [];
    }
}
