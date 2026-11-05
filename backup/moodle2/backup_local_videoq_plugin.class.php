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
 * Backs up source bindings with Moodle activities.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Portable opt-in/layout markers only: no remote IDs, credentials or conversations.
 */
class backup_local_videoq_plugin extends backup_local_plugin {
    /**
     * Defines the source-binding metadata included in a module backup.
     *
     * @return \backup_plugin_element|null
     */
    protected function define_module_plugin_structure() {
        $cmid = (int)$this->task->get_moduleid();
        $metadata = \local_videoq\restoration::describe($cmid);
        if ($metadata === null) {
            return;
        }
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);
        $binding = new backup_nested_element('binding', ['id'], ['role', 'enabled']);
        $wrapper->add_child($binding);
        $binding->set_source_array([(object)(['id' => $cmid] + $metadata)]);
        return $plugin;
    }
}
