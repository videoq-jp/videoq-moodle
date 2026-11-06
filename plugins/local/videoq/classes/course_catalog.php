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
 * Resolves current Moodle-owned source management and course navigation.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Core Moodle owns uploads, activity order and enrolments.
 */
final class course_catalog {
    /**
     * Collects accessible video File activities in Moodle's current course order.
     *
     * @param int $courseid Moodle course identifier.
     * @return array
     */
    public static function sources(int $courseid): array {
        global $DB;
        course_entry::require_manager($courseid);
        $info = get_fast_modinfo($courseid);
        $sections = $info->get_sections();
        ksort($sections, SORT_NUMERIC);
        $bindings = [];
        foreach ($DB->get_records('local_videoq_sync', ['courseid' => $courseid]) as $row) {
            $bindings[(int)$row->cmid] = $row;
        }
        $result = [];
        foreach ($sections as $cms) {
            foreach ($cms as $cmid) {
                $cm = $info->get_cm($cmid);
                $row = $bindings[$cmid] ?? null;
                if (
                    !source::supports($cm) || !$cm->uservisible || $cm->deletioninprogress
                    || !has_capability(source::view_capability($cm), \context_module::instance($cmid))
                    || !has_capability('moodle/course:manageactivities', \context_module::instance($cmid))
                    || (!source::read((int)$cmid) && !$row)
                ) {
                    continue;
                }
                $result[] = ['cmid' => (int)$cmid, 'title' => $cm->name,
                    'status' => $row && $row->enabled ? $row->status : 'off'];
            }
        }
        return $result;
    }
    /**
     * Returns core workflows permitted to the current Moodle user.
     *
     * Each destination independently checks permissions and confirmation.
     *
     * @param int $courseid Moodle course identifier.
     * @return array
     */
    public static function actions(int $courseid): array {
        global $CFG;
        course_entry::require_manager($courseid);
        require_once($CFG->dirroot . '/course/lib.php');
        $course = get_course($courseid);
        $context = \context_course::instance($courseid);
        $actions = [
            'catalogorganize' => new \moodle_url('/course/view.php', ['id' => $courseid]),
            'managecourseentry' => new \moodle_url('/local/videoq/course.php', ['course' => $courseid]),
        ];
        if (has_capability('mod/resource:addinstance', $context)) {
            $actions['catalogupload'] = new \moodle_url(
                '/course/modedit.php',
                ['add' => 'resource', 'course' => $courseid, 'section' => 0]
            );
        }
        if (source::youtube_available() && has_capability('mod/url:addinstance', $context)) {
            $actions['catalogyoutube'] = new \moodle_url(
                '/course/modedit.php',
                ['add' => 'url', 'course' => $courseid, 'section' => 0]
            );
        }
        if (has_capability('moodle/course:update', $context)) {
            $actions['catalogcourseedit'] = new \moodle_url('/course/edit.php', ['id' => $courseid]);
        }
        if (has_capability('moodle/course:viewparticipants', $context)) {
            $actions['catalogparticipants'] = new \moodle_url('/user/index.php', ['id' => $courseid]);
        }
        if (has_capability('moodle/course:enrolconfig', $context)) {
            $actions['catalogenrolments'] = new \moodle_url('/enrol/instances.php', ['id' => $courseid]);
        }
        if (has_capability('moodle/course:create', \context_coursecat::instance($course->category))) {
            $actions['catalogcoursecreate'] = new \moodle_url('/course/edit.php', ['category' => $course->category]);
        }
        if (can_delete_course($courseid)) {
            $actions['catalogcoursedelete'] = new \moodle_url('/course/delete.php', ['id' => $courseid]);
        }
        return $actions;
    }
}
