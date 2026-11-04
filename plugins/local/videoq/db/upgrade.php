<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
defined('MOODLE_INTERNAL') || die();
function xmldb_local_videoq_upgrade($oldversion) {
    global $DB;
    if ($oldversion < 2026110303) {
        $manager = $DB->get_manager();
        if (!$manager->table_exists(new xmldb_table('local_videoq_sync'))) {
            $manager->install_from_xmldb_file(__DIR__ . '/install.xml');
        }
        upgrade_plugin_savepoint(true, 2026110303, 'local', 'videoq');
    }
    if ($oldversion < 2026110304) {
        if (!get_config('local_videoq', 'accesssigningkey')) set_config('accesssigningkey', bin2hex(random_bytes(32)), 'local_videoq');
        upgrade_plugin_savepoint(true, 2026110304, 'local', 'videoq');
    }
    if ($oldversion < 2026110305) {
        foreach ($DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {local_videoq_sync} WHERE enabled = 1') as $courseid) {
            \local_videoq\synchronizer::ensure_course_entry((int)$courseid);
        }
        upgrade_plugin_savepoint(true, 2026110305, 'local', 'videoq');
    }
    return true;
}
