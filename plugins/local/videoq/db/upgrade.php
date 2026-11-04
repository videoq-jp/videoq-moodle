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
    if ($oldversion < 2026110401) {
        $manager = $DB->get_manager();
        $schema = new xmldb_file(__DIR__ . '/install.xml');
        if (!$schema->loadXMLStructure()) throw new \RuntimeException('Invalid install schema');
        foreach (['local_videoq_assertions', 'local_videoq_exports'] as $name) {
            $table = $schema->getStructure()->getTable($name);
            if (!$manager->table_exists($table)) $manager->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110401, 'local', 'videoq');
    }
    if ($oldversion < 2026110402) {
        $manager = $DB->get_manager();
        $table = new xmldb_table('local_videoq_sync');
        $field = new xmldb_field('typeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
        if (!$manager->field_exists($table, $field)) $manager->add_field($table, $field);
        foreach ($DB->get_records('local_videoq_sync') as $row) {
            $typeid = $DB->get_field('lti', 'typeid', ['id' => $row->ltiinstance]) ?: (int)get_config('local_videoq', 'typeid');
            $DB->set_field('local_videoq_sync', 'typeid', $typeid, ['id' => $row->id]);
        }
        upgrade_plugin_savepoint(true, 2026110402, 'local', 'videoq');
    }
    if ($oldversion < 2026110403) upgrade_plugin_savepoint(true, 2026110403, 'local', 'videoq');
    return true;
}
