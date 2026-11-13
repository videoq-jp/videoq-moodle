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
 * Routes owned VideoQ activity links to the native course viewer.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Retired LTI launch links use the Moodle-native viewer without an external iframe.
 */
final class native_navigation {
    /**
     * Resolves only generated, valid course transports after Moodle login checks.
     *
     * @param object $cm Current Moodle course module.
     * @param string $script Current Moodle script path.
     * @param bool $preventredirect Whether the caller forbids redirects.
     * @return \moodle_url|null
     */
    public static function target(object $cm, string $script, bool $preventredirect = false): ?\moodle_url {
        if (
            $preventredirect || !in_array($script, ['/mod/lti/view.php', '/mod/lti/launch.php'], true)
                || ($cm->modname ?? '') !== 'lti'
        ) {
            return null;
        }
        $entry = course_entry::for_launch((int)$cm->instance);
        if (
            !$entry || !$entry->enabled || (int)$entry->lticmid !== (int)$cm->id
                || (int)$entry->courseid !== (int)$cm->course || !course_entry::valid($entry)
        ) {
            return null;
        }
        require_capability('mod/lti:view', \context_module::instance((int)$cm->id));
        return new \moodle_url('/local/videoq/view.php', ['course' => (int)$cm->course]);
    }
}
