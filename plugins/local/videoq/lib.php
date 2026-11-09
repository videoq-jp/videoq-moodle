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
 * Provides Moodle callbacks for the VideoQ integration.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds synchronization controls for teachers managing a File activity.
 *
 * @param settings_navigation $nav Settings navigation for the current page.
 * @param context $context Context of the currently managed File activity.
 * @return void
 */
function local_videoq_extend_settings_navigation(settings_navigation $nav, context $context) {
    global $PAGE;
    if (
        $context->contextlevel !== CONTEXT_MODULE || !$PAGE->cm
            || !\local_videoq\source_management::can_manage((int)$PAGE->cm->id)
    ) {
        return;
    }
    $node = $nav->find('modulesettings', navigation_node::TYPE_SETTING) ?: $nav;
    $node->add(
        get_string(
            'managesync',
            'local_videoq'
        ),
        new moodle_url(
            '/local/videoq/manage.php',
            ['cmid' => $PAGE->cm->id]
        ),
        navigation_node::TYPE_SETTING
    );
}

/**
 * Adds course-wide setup without granting module-only managers course authority.
 *
 * @param navigation_node $navigation Course settings node.
 * @param stdClass $course Current course.
 * @param context_course $context Current course context.
 * @return void
 */
function local_videoq_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    if ((int)$course->id === SITEID || !\local_videoq\course_entry::available()) {
        return;
    }
    if (has_capability('local/videoq:managecontent', $context) && has_capability('moodle/course:manageactivities', $context)) {
        $navigation->add(
            get_string('coursecatalog', 'local_videoq'),
            new moodle_url('/local/videoq/catalog.php', ['course' => $course->id]),
            navigation_node::TYPE_SETTING
        );
        $navigation->add(
            get_string('managecourseentry', 'local_videoq'),
            new moodle_url('/local/videoq/course.php', ['course' => $course->id]),
            navigation_node::TYPE_SETTING
        );
    }
}

/**
 * Adds VideoQ language selection to the standard File upload form.
 *
 * @param moodleform_mod $form Activity form.
 * @param MoodleQuickForm $mform Form fields.
 * @return void
 */
function local_videoq_coursemodule_standard_elements(moodleform_mod $form, MoodleQuickForm $mform): void {
    \local_videoq\transcription_language::form($form, $mform);
}

/**
 * Validates upload-time language selection.
 *
 * @param moodleform_mod $form Activity form.
 * @param array $data Submitted fields.
 * @return array
 */
function local_videoq_coursemodule_validation(moodleform_mod $form, array $data): array {
    return \local_videoq\transcription_language::validate($form, $data);
}

/**
 * Persists the selected language after Moodle saves the File activity.
 *
 * @param stdClass $moduleinfo Module fields.
 * @param stdClass $course Course fields.
 * @return stdClass
 */
function local_videoq_coursemodule_edit_post_actions(stdClass $moduleinfo, stdClass $course): stdClass {
    \local_videoq\transcription_language::saved($moduleinfo);
    return $moduleinfo;
}
