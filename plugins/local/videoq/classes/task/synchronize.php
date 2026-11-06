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
 * Recovers pending synchronization jobs using the scheduler.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq\task;
/**
 * Recovers pending synchronization jobs using the scheduler.
 */
final class synchronize extends \core\task\scheduled_task {
    /**
     * Returns the localized name of the scheduled synchronization task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('synchronize', 'local_videoq');
    }
    /**
     * Reconciles enabled materials and recovers pending transfers.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        if (get_config('local_videoq', 'servicemode') === 'lti') {
            $DB->execute(
                'UPDATE {local_videoq_exports} SET status = ?, uploadurl = NULL, timemodified = ?'
                    . ' WHERE timecreated < ? AND status IN (?, ?)',
                ['failed', time(), time() - 3600, 'queued', 'uploading']
            );
            $DB->delete_records_select(
                'local_videoq_exports',
                'timemodified < ? AND status IN (?, ?, ?)',
                [time() - 86400,
                 'uploaded',
                 'failed',
                'stale']
            );
            $DB->delete_records_select('local_videoq_assertions', 'expires < ?', [time() - 120]);
            return;
        }
        if (!get_config('local_videoq', 'apiurl') || !get_config('local_videoq', 'synctoken')) {
            return;
        }
        $rows = $DB->get_records_select('local_videoq_sync', 'enabled = 1 OR status <> ?', ['disabled'], 'lastsynced ASC, id ASC');
        foreach ($rows as $row) {
            try {
                \local_videoq\synchronizer::run((int)$row->id);
            } catch (\Throwable $error) {
                // No HTTP body, provider diagnostic, URL signature or credential
                // is written to Moodle logs or teacher-visible status.
                $DB->set_field('local_videoq_sync', 'status', 'error', ['id' => $row->id]);
                $DB->set_field('local_videoq_sync', 'errorcode', 'SYNC_FAILED', ['id' => $row->id]);
                mtrace('VideoQ synchronization failed for activity ' . $row->cmid . '; will retry.');
            }
        }
        if (\local_videoq\course_entry::available()) {
            $entries = $DB->get_records_select(
                'local_videoq_courses',
                'enabled = 1 OR status <> ?',
                ['disabled'],
                'lastsynced ASC, id ASC'
            );
            foreach ($entries as $entry) {
                try {
                    \local_videoq\course_entry::run((int)$entry->courseid);
                } catch (\Throwable $error) {
                    $DB->set_field('local_videoq_courses', 'status', 'error', ['id' => $entry->id]);
                    $DB->set_field('local_videoq_courses', 'errorcode', 'SYNC_FAILED', ['id' => $entry->id]);
                    mtrace('VideoQ synchronization failed for course ' . $entry->courseid . '; will retry.');
                }
            }
        }
    }
}
