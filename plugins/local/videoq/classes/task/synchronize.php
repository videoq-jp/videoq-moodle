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
        // Retain credential/transfer cleanup, but never import Moodle source files.
        $DB->execute('UPDATE {local_videoq_exports} SET status = ?, uploadurl = NULL, timemodified = ?'
            . ' WHERE status IN (?, ?)', ['failed', time(), 'queued', 'uploading']);
        $DB->delete_records_select(
            'local_videoq_exports',
            'timemodified < ? AND status IN (?, ?, ?)',
            [time() - 86400, 'uploaded', 'failed', 'stale']
        );
        $DB->delete_records_select('local_videoq_assertions', 'expires < ?', [time() - 120]);
    }
}
