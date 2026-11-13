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
 * Cancels queued exports from the retired Moodle video-import workflow.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq\task;

/**
 * Old queued tasks remain loadable during upgrades, but never transfer source media.
 */
final class export_material extends \core\task\adhoc_task {
    /**
     * Invalidates a legacy upload destination without reading or transferring media.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        // Upgrading must also stop exports queued by the retired upload workflow.
        $jobid = $this->get_custom_data()->jobid ?? '';
        if ($jobid !== '') {
            $DB->execute('UPDATE {local_videoq_exports} SET status = ?, uploadurl = NULL, timemodified = ?'
                . ' WHERE jobid = ? AND status IN (?, ?)', ['failed', time(), $jobid, 'queued', 'uploading']);
        }
    }
}
