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
     * Removes historical language metadata without queuing imports or changing connections.
     *
     * @param \core\event\base $event Moodle deletion event.
     * @return void
     */
    public static function cleanup(\core\event\base $event): void {
        global $DB;
        $data = $event->get_data();
        if ($data['eventname'] === '\\core\\event\\course_module_deleted') {
            $DB->delete_records('local_videoq_languages', ['cmid' => (int)$data['objectid']]);
        } else if ($data['eventname'] === '\\core\\event\\course_deleted') {
            $DB->delete_records_select('local_videoq_languages', 'cmid NOT IN (SELECT id FROM {course_modules})');
        }
    }

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
            if ($data['eventname'] === '\\core\\event\\course_module_deleted') {
                $DB->delete_records('local_videoq_languages', ['cmid' => $cmid]);
            }
            $rows = $DB->get_records_select('local_videoq_sync', 'cmid = ? OR lticmid = ? OR linkcmid = ?', [$cmid, $cmid, $cmid]);
        } else {
            if ($data['eventname'] === '\\core\\event\\course_deleted') {
                // Bulk course deletion does not emit individual module-deleted events.
                $DB->delete_records_select('local_videoq_languages', 'cmid NOT IN (SELECT id FROM {course_modules})');
            }
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
        if (course_entry::available()) {
            $entries = str_starts_with($data['eventname'], '\core\event\course_module_')
                ? $DB->get_records('local_videoq_courses', ['lticmid' => (int)$data['objectid']])
                : $DB->get_records('local_videoq_courses', ['courseid' => (int)$data['objectid']]);
            foreach ($entries as $entry) {
                $DB->execute(
                    'UPDATE {local_videoq_courses} SET generation = generation + 1, status = ?, timemodified = ? WHERE id = ?',
                    ['queued', time(), $entry->id]
                );
            }
        }
    }
}
