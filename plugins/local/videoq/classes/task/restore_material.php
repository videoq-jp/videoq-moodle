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
 * Recreates restored material bindings in an asynchronous task.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq\task;
defined('MOODLE_INTERNAL') || die();

/**
 * Retire copied transports and initialize sources after Moodle finishes restoring.
 */
final class restore_material extends \core\task\adhoc_task {
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $data = $this->get_custom_data();
        $courseid = (int)$data->courseid;
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return;
        }
        $status = $DB->get_field('backup_controllers', 'status', ['backupid' => $data->restoreid]);
        if ($status !== false && (int)$status !== \backup::STATUS_FINISHED_OK) {
            if ((int)$status === \backup::STATUS_FINISHED_ERR) {
                foreach ($data->cmids as $cmid) {
                    $DB->set_field(
                        'local_videoq_sync',
                        'enabled',
                        0,
                        ['cmid' => (int)$cmid, 'courseid' => $courseid, 'status' => 'restoring']
                    );
                }
                return;
            }
            throw new \RuntimeException('Waiting for Moodle course restoration to finish.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('restore-course-' . $courseid, 10);
        if (!$lock) {
            throw new \RuntimeException('Restored materials are being initialized.');
        }
        try {
            foreach ($data->retired as $retired) {
                $cm = get_coursemodule_from_id('', (int)$retired->cmid, $courseid, false, IGNORE_MISSING);
                if (!$cm) {
                    continue;
                }
                if (
                    !in_array($retired->module, ['lti', 'url'], true) || $cm->modname !== $retired->module ||
                        $cm->visible || $DB->record_exists_select(
                            'local_videoq_sync',
                            'cmid = ? OR lticmid = ? OR linkcmid = ?',
                            [$cm->id, $cm->id, $cm->id]
                        )
                ) {
                    throw new \RuntimeException('A restored transport was changed; refusing to delete it.');
                }
                course_delete_module($cm->id, false);
            }
            foreach ($data->cmids as $cmid) {
                $row = $DB->get_record('local_videoq_sync', ['cmid' => (int)$cmid, 'courseid' => $courseid]);
                if (!$row || !$row->enabled) {
                    continue;
                }
                $cm = get_coursemodule_from_id('resource', $row->cmid, $courseid, false, IGNORE_MISSING);
                if (!$cm || $cm->deletioninprogress) {
                    continue;
                }
                $DB->set_field('local_videoq_sync', 'status', 'queued', ['id' => $row->id, 'status' => 'restoring', 'enabled' => 1]);
                \local_videoq\synchronizer::restore_source((int)$row->cmid);
            }
        } catch (\Throwable $error) {
            throw new \RuntimeException('VideoQ restored material initialization failed; check the site connection and retry.');
        } finally {
            $lock->release();
        }
    }
}
