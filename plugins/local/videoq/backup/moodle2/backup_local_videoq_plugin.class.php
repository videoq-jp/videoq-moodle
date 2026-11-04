<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
defined('MOODLE_INTERNAL') || die();

/** Portable opt-in/layout markers only: no remote IDs, credentials or conversations. */
class backup_local_videoq_plugin extends backup_local_plugin {
    protected function define_module_plugin_structure() {
        $cmid = (int)$this->task->get_moduleid();
        $metadata = \local_videoq\restoration::describe($cmid);
        if ($metadata === null) return;
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);
        $binding = new backup_nested_element('binding', ['id'], ['role', 'enabled']);
        $wrapper->add_child($binding);
        $binding->set_source_array([(object)(['id' => $cmid] + $metadata)]);
        return $plugin;
    }
}
