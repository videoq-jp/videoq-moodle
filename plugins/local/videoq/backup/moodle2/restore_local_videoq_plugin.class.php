<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
defined('MOODLE_INTERNAL') || die();

/** Restore against the destination site's connection, never the source site's binding. */
class restore_local_videoq_plugin extends restore_local_plugin {
    protected function define_module_plugin_structure() {
        return [new restore_path_element('videoq_binding', $this->get_pathfor('/binding'))];
    }
    public function process_videoq_binding($data) {
        $data = (object)$data;
        if ((int)$data->id !== (int)$this->task->get_old_moduleid() ||
                !in_array($data->role ?? '', ['source', 'transport', 'shortcut', 'entry'], true) ||
                !in_array((string)($data->enabled ?? ''), ['0', '1'], true)) {
            throw new restore_step_exception('invalid_videoq_backup_metadata');
        }
        restore_dbops::set_backup_ids_record($this->get_restoreid(), 'local_videoq_restore', (int)$data->id,
            (int)$this->task->get_moduleid(), (int)$this->task->get_courseid(),
            ['role' => $data->role, 'enabled' => (int)$data->enabled]);
    }
    public function after_restore_module() {
        \local_videoq\restoration::finish($this->get_restoreid(), (int)$this->task->get_courseid());
    }
}
