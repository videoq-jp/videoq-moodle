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

/**
 * Keeps VideoQ transport activities out of the visible course navigation.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Presentation hooks for generated transport activities.
 */
final class course_presentation {
    /**
     * Hides mapped stealth companions without changing launch permissions.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook Output hook.
     * @return void
     */
    public static function before_head(\core\hook\output\before_standard_head_html_generation $hook): void {
        global $DB, $PAGE;
        $courseid = (int)$PAGE->course->id;
        if ($courseid <= SITEID) {
            return;
        }
        // Only VideoQ-owned companions already configured as stealth are eligible.
        // A teacher-made activity or a deliberately visible companion is untouched.
        $rows = $DB->get_records('local_videoq_sync', ['courseid' => $courseid], '', 'id, lticmid, linkcmid');
        if (class_exists(course_entry::class)) {
            $entry = course_entry::find($courseid);
            if ($entry) {
                $rows[] = (object)['lticmid' => $entry->lticmid, 'linkcmid' => 0];
            }
        }
        $ids = [];
        foreach ($rows as $row) {
            foreach ([(int)$row->lticmid, (int)$row->linkcmid] as $id) {
                if ($id <= 0) {
                    continue;
                }
                $cm = get_coursemodule_from_id('', $id, $courseid, false, IGNORE_MISSING);
                if ($cm && in_array($cm->modname, ['lti', 'url'], true) && !$cm->visibleoncoursepage) {
                    $ids[$id] = $id;
                }
            }
        }
        if (!$ids) {
            return;
        }
        $selectors = [];
        foreach ($ids as $id) {
            $selectors[] = '#module-' . $id;
            $selectors[] = '#course-index-cm-' . $id;
        }
        // Moodle renders stealth activities for managers, including cached course indexes.
        // Scope display-only rules to mapped IDs; direct launch and authorization stay intact.
        $hook->add_html('<style>' . implode(',', $selectors) . '{display:none!important}</style>');
    }
}
