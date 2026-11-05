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
 * Restores source bindings without reusing connection credentials.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore against the destination site's connection, never the source site's binding.
 */
class restore_local_videoq_plugin extends restore_local_plugin {
    /**
     * Declares the metadata paths read during module restoration.
     *
     * @return array
     */
    protected function define_module_plugin_structure() {
        return [new restore_path_element('videoq_binding', $this->get_pathfor('/binding'))];
    }
    /**
     * Validates and maps restored source metadata to the destination activity.
     *
     * @param array|\stdClass $data Stored connection or restored binding metadata.
     * @return void
     */
    public function process_videoq_binding($data) {
        $data = (object)$data;
        if (
            (int)$data->id !== (int)$this->task->get_old_moduleid() ||
                !in_array($data->role ?? '', ['source', 'transport', 'shortcut', 'entry'], true) ||
                !in_array((string)($data->enabled ?? ''), ['0', '1'], true)
        ) {
            throw new restore_step_exception('invalid_videoq_backup_metadata');
        }
        restore_dbops::set_backup_ids_record(
            $this->get_restoreid(),
            'local_videoq_restore',
            (int)$data->id,
            (int)$this->task->get_moduleid(),
            (int)$this->task->get_courseid(),
            ['role' => $data->role, 'enabled' => (int)$data->enabled]
        );
    }
    /**
     * Queues reconstruction of material bindings after a module restore.
     *
     * @return void
     */
    public function after_restore_module() {
        \local_videoq\restoration::finish($this->get_restoreid(), (int)$this->task->get_courseid());
    }
}
