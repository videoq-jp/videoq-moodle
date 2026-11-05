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
 * Rebuilds material bindings after Moodle course restore.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Moodle backup markers and destination-only restoration of source opt-ins.
 */
final class restoration {
    /**
     * Produces portable source-role metadata for a course activity backup.
     *
     * @param int $cmid Source course-module identifier.
     * @return array|null
     */
    public static function describe(int $cmid): ?array {
        global $CFG, $DB;
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $rows = $DB->get_records_select(
            'local_videoq_sync',
            'courseid = ? AND (cmid = ? OR lticmid = ? OR linkcmid = ?)',
            [(int)$cm->course, $cmid, $cmid, $cmid]
        );
        foreach ($rows as $row) {
            if ((int)$row->cmid === $cmid && $cm->modname === 'resource') {
                return ['role' => 'source', 'enabled' => (int)(bool)$row->enabled];
            }
            if ((int)$row->lticmid === $cmid && $cm->modname === 'lti') {
                return ['role' => 'transport', 'enabled' => 0];
            }
            if ((int)$row->linkcmid === $cmid && $cm->modname === 'url') {
                return ['role' => 'shortcut', 'enabled' => 0];
            }
        }
        if (
            $cm->modname === 'url' && $DB->get_field('url', 'externalurl', ['id' => $cm->instance]) ===
                $CFG->wwwroot . '/local/videoq/view.php?course=' . $cm->course
        ) {
            return ['role' => 'entry', 'enabled' => 0];
        }
        return null;
    }
    /**
     * Called after all module data/files/mappings have been restored, once per restore/course.
     *
     * @param string $restoreid Restore operation identifier.
     * @param int $courseid Restored course identifier.
     * @return void
     */
    public static function finish(string $restoreid, int $courseid): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('restore-' . $restoreid . '-' . $courseid, 10);
        if (!$lock) {
            throw new \RuntimeException('Restored materials are being initialized');
        }
        try {
            if (\restore_dbops::get_backup_ids_record($restoreid, 'local_videoq_restore_done', $courseid)) {
                return;
            }
            $records = $DB->get_records(
                'backup_ids_temp',
                ['backupid' => $restoreid,
                 'itemname' => 'local_videoq_restore',
                 'parentitemid' => $courseid],
                'id ASC'
            );
            $sources = $retired = [];
            $transaction = $DB->start_delegated_transaction();
            foreach ($records as $record) {
                $mapped = \restore_dbops::get_backup_ids_record($restoreid, 'local_videoq_restore', $record->itemid);
                $module = \restore_dbops::get_backup_ids_record($restoreid, 'course_module', $record->itemid);
                if (!$module || (int)$module->newitemid !== (int)$mapped->newitemid) {
                    throw new \RuntimeException('Invalid restored material mapping');
                }
                $cm = get_coursemodule_from_id('', (int)$mapped->newitemid, $courseid, false, IGNORE_MISSING);
                if (!$cm) {
                    continue;
                }
                $metadata = (array)$mapped->info;
                if ($metadata['role'] === 'source') {
                    if ($cm->modname !== 'resource') {
                        throw new \RuntimeException('Restored source is not a File');
                    }
                    if (
                        $metadata['enabled']
                        && has_capability(
                            'moodle/course:manageactivities',
                            \context_module::instance($cm->id)
                        )
                    ) {
                        $sources[] = $cm;
                    }
                } else if ($metadata['role'] === 'entry') {
                    if ($cm->modname !== 'url') {
                        throw new \RuntimeException('Restored entry is not a URL');
                    }
                    $target = $CFG->wwwroot . '/local/videoq/view.php?course=' . $courseid;
                    $existing = $DB->get_records_sql(
                        "SELECT cm.id FROM {course_modules} cm
                        JOIN {modules} m ON m.id = cm.module JOIN {url} u ON u.id = cm.instance
                        WHERE m.name = 'url' AND u.course = ? AND u.externalurl = ? AND u.id <> ?",
                        [$courseid, $target, $cm->instance]
                    );
                    $existing = array_diff(array_map('intval', array_keys($existing)), array_column($retired, 'cmid'));
                    if ($existing) {
                        // Keep the destination's existing course assistant. Delete
                        // only this backup's redundant entry after restore finishes.
                        $DB->update_record('course_modules', (object)['id' => $cm->id,
                            'visible' => 0, 'visibleold' => 0, 'visibleoncoursepage' => 0]);
                        $retired[] = ['cmid' => (int)$cm->id, 'module' => 'url'];
                    }
                    $DB->set_field('url', 'externalurl', $target, ['id' => $cm->instance]);
                } else {
                    $expected = $metadata['role'] === 'transport' ? 'lti' : 'url';
                    if (
                        $cm->modname !== $expected || $DB->record_exists_select(
                            'local_videoq_sync',
                            'cmid = ? OR lticmid = ? OR linkcmid = ?',
                            [$cm->id, $cm->id, $cm->id]
                        )
                    ) {
                        throw new \RuntimeException('Refusing to delete an activity outside restored transport metadata');
                    }
                    // Recycle-bin deletion creates another backup and drops the
                    // active restore's temporary ID table. Retire these clones now
                    // and delete them from a separate, post-restore task instead.
                    $DB->update_record('course_modules', (object)['id' => $cm->id,
                        'visible' => 0, 'visibleold' => 0, 'visibleoncoursepage' => 0]);
                    if ($cm->modname === 'url') {
                        $DB->set_field(
                            'url',
                            'externalurl',
                            $CFG->wwwroot . '/local/videoq/view.php?course=' . $courseid,
                            ['id' => $cm->instance]
                        );
                    }
                    $retired[] = ['cmid' => (int)$cm->id, 'module' => $cm->modname];
                }
            }
            foreach ($sources as $cm) {
                if ($DB->record_exists('local_videoq_sync', ['cmid' => $cm->id])) {
                    continue;
                }
                $DB->insert_record('local_videoq_sync', (object)['typeid' => (int)get_config('local_videoq', 'typeid'),
                    'cmid' => $cm->id, 'courseid' => $courseid, 'lticmid' => 0, 'ltiinstance' => 0, 'linkcmid' => 0,
                    'enabled' => 1, 'status' => 'restoring', 'timemodified' => time()]);
            }
            if ($sources || $retired) {
                $task = new \local_videoq\task\restore_material();
                $task->set_userid((int)$USER->id);
                $task->set_custom_data(['cmids' => array_map(static fn($cm) => (int)$cm->id, $sources),
                    'retired' => $retired, 'courseid' => $courseid, 'restoreid' => $restoreid]);
                \core\task\manager::queue_adhoc_task($task, true);
            }
            if ($sources) {
                synchronizer::ensure_course_entry($courseid);
            }
            \restore_dbops::set_backup_ids_record($restoreid, 'local_videoq_restore_done', $courseid, $courseid);
            $transaction->allow_commit();
            rebuild_course_cache($courseid, true);
        } finally {
            $lock->release();
        }
    }
}
