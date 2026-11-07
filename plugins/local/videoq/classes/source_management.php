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
 * Checks current authority for changing a Moodle source's VideoQ opt-in.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Source synchronization requires course content and module management rights.
 */
final class source_management {
    /**
     * Tests the current user's authority without accepting a caller-selected course.
     *
     * @param int $cmid Source course-module identifier.
     * @return bool
     */
    public static function can_manage(int $cmid): bool {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        return $cm && source::supports($cm) && !$cm->deletioninprogress && (int)$cm->course !== SITEID
            && has_capability('local/videoq:managecontent', \context_course::instance($cm->course))
            && has_capability('moodle/course:manageactivities', \context_module::instance($cmid));
    }

    /**
     * Requires both grants afresh before any interactive synchronization mutation.
     *
     * @param int $cmid Source course-module identifier.
     * @return void
     */
    public static function require_manager(int $cmid): void {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        if (!source::supports($cm) || $cm->deletioninprogress || (int)$cm->course === SITEID) {
            throw new \moodle_exception('unknownlesson', 'local_videoq');
        }
        require_capability('local/videoq:managecontent', \context_course::instance($cm->course));
        require_capability('moodle/course:manageactivities', \context_module::instance($cmid));
    }
}
