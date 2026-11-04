<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
namespace local_videoq;
defined('MOODLE_INTERNAL') || die();
final class observer {
    public static function changed(\core\event\base $event): void {
        global $DB;
        $data = $event->get_data();
        if (str_starts_with($data['eventname'], '\core\event\course_module_')) {
            $cmid = (int)$data['objectid'];
            $rows = $DB->get_records_select('local_videoq_sync', 'cmid = ? OR lticmid = ? OR linkcmid = ?', [$cmid, $cmid, $cmid]);
        } else {
            $rows = $DB->get_records('local_videoq_sync', ['courseid' => (int)$data['objectid']]);
        }
        foreach ($rows as $row) {
            // Bump even when content is unchanged: permissions and availability
            // changes must revoke already-open question sessions as well.
            $DB->execute('UPDATE {local_videoq_sync} SET generation = generation + 1, status = ?, timemodified = ? WHERE id = ?',
                ['queued', time(), $row->id]);
        }
    }
}
