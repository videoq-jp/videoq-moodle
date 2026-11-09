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
 * Upgrades synchronization tables and plugin configuration.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Applies schema and configuration upgrades with Moodle savepoints.
 *
 * @param int $oldversion Previously installed plugin version.
 * @return bool
 */
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
        if (!get_config('local_videoq', 'accesssigningkey')) {
            set_config('accesssigningkey', bin2hex(random_bytes(32)), 'local_videoq');
        }
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
        if (!$schema->loadXMLStructure()) {
            throw new \RuntimeException('Invalid install schema');
        }
        foreach (['local_videoq_assertions', 'local_videoq_exports'] as $name) {
            $table = $schema->getStructure()->getTable($name);
            if (!$manager->table_exists($table)) {
                $manager->create_table($table);
            }
        }
        upgrade_plugin_savepoint(true, 2026110401, 'local', 'videoq');
    }
    if ($oldversion < 2026110402) {
        $manager = $DB->get_manager();
        $table = new xmldb_table('local_videoq_sync');
        $field = new xmldb_field('typeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
        if (!$manager->field_exists($table, $field)) {
            $manager->add_field($table, $field);
        }
        foreach ($DB->get_records('local_videoq_sync') as $row) {
            $typeid = $DB->get_field('lti', 'typeid', ['id' => $row->ltiinstance]) ?: (int)get_config('local_videoq', 'typeid');
            $DB->set_field('local_videoq_sync', 'typeid', $typeid, ['id' => $row->id]);
        }
        upgrade_plugin_savepoint(true, 2026110402, 'local', 'videoq');
    }
    if ($oldversion < 2026110403) {
        upgrade_plugin_savepoint(true, 2026110403, 'local', 'videoq');
    }
    if ($oldversion < 2026110701) {
        $schema = new xmldb_file(__DIR__ . '/install.xml');
        if (!$schema->loadXMLStructure()) {
            throw new \RuntimeException('Invalid install schema');
        }
        $table = $schema->getStructure()->getTable('local_videoq_courses');
        if (!$DB->get_manager()->table_exists($table)) {
            $DB->get_manager()->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110701, 'local', 'videoq');
    }
    if ($oldversion < 2026110702) {
        upgrade_plugin_savepoint(true, 2026110702, 'local', 'videoq');
    }
    if ($oldversion < 2026110703) {
        upgrade_plugin_savepoint(true, 2026110703, 'local', 'videoq');
    }
    if ($oldversion < 2026110704) {
        $manager = $DB->get_manager();
        $schema = new xmldb_file(__DIR__ . '/install.xml');
        if (!$schema->loadXMLStructure()) {
            throw new \RuntimeException('Invalid install schema');
        }
        $table = $schema->getStructure()->getTable('local_videoq_sync');
        foreach (['sourcekind', 'youtubeid'] as $name) {
            $field = $table->getField($name);
            if (!$manager->field_exists($table, $field)) {
                $manager->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026110704, 'local', 'videoq');
    }
    if ($oldversion < 2026110801) {
        // Moodle's upgrade installs the new core/ajax function declarations.
        upgrade_plugin_savepoint(true, 2026110801, 'local', 'videoq');
    }
    if ($oldversion < 2026110802) {
        upgrade_plugin_savepoint(true, 2026110802, 'local', 'videoq');
    }
    if ($oldversion < 2026110900) {
        // Rebuild hook registrations for the simplified course navigation.
        upgrade_plugin_savepoint(true, 2026110900, 'local', 'videoq');
    }
    if ($oldversion < 2026110901) {
        $manager = $DB->get_manager();
        $schema = new xmldb_file(__DIR__ . '/install.xml');
        if (!$schema->loadXMLStructure()) {
            throw new \RuntimeException('Invalid install schema');
        }
        $table = $schema->getStructure()->getTable('local_videoq_languages');
        if (!$manager->table_exists($table)) {
            $manager->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110901, 'local', 'videoq');
    }
    return true;
}
