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
 * Queues synchronization after source activity changes.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;
/**
 * Queues synchronization after source activity changes.
 */
final class observer {
    /**
     * Queues reconciliation after a source activity is changed or removed.
     *
     * @param \core\event\base $event Moodle activity event.
     * @return void
     */
    public static function changed(\core\event\base $event): void {
        global $DB;
        $data = $event->get_data();
        if (str_starts_with($data['eventname'], '\core\event\course_module_')) {
            $cmid = (int)$data['objectid'];
            $rows = $DB->get_records_select('local_videoq_sync', 'cmid = ? OR lticmid = ? OR linkcmid = ?', [$cmid, $cmid, $cmid]);
        } else {
            $rows = $DB->get_records('local_videoq_sync', ['courseid' => (int)$data['objectid']]);
        }
        foreach ($rows as $row) {
            // Bump even when content is unchanged: permissions and availability
            // changes must revoke already-open question sessions as well.
            $DB->execute(
                "UPDATE {local_videoq_sync} SET generation = generation + 1,
                status = CASE WHEN status = 'restoring' THEN status ELSE ? END, timemodified = ? WHERE id = ?",
                ['queued', time(), $row->id]
            );
        }
    }
}
