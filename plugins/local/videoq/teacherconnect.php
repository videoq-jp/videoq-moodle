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
 * Manages the course-owned VideoQ entry independently of video activities.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
$courseid = required_param('course', PARAM_INT);
$course = get_course($courseid);
require_login($course);
\local_videoq\course_entry::require_manager($courseid);
$PAGE->set_url(new moodle_url('/local/videoq/teacherconnect.php', ['course' => $courseid]));
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_title(get_string('teacherconnect', 'local_videoq'));
$PAGE->set_heading(format_string($course->fullname));
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
if (data_submitted()) {
    require_sesskey();
    try {
        $entry = \local_videoq\course_entry::find($courseid);
        if (!$entry || !$entry->enabled) {
            \local_videoq\course_entry::configure($courseid, true);
        }
        $result = \local_videoq\native_api::request(
            $courseid,
            '',
            'teacher/connect',
            ['title' => \core_text::substr(strip_tags($course->fullname), 0, 255)]
        );
        $body = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR);
        $url = $body['url'] ?? '';
        $origin = rtrim((string)get_config('local_videoq', 'apiurl'), '/');
        // Use only the frontend approved by installation discovery, never a caller redirect.
        require_once($CFG->libdir . '/filelib.php');
        $curl = new curl();
        $curl->setHeader(['Accept: application/json']);
        $raw = $curl->get($origin . '/api/lms/pairing/discovery', [], \local_videoq\client::http_options(15));
        if ($curl->get_errno() || (int)($curl->get_info()['http_code'] ?? 0) !== 200 || strlen($raw) > 8192) {
            throw new RuntimeException('Discovery unavailable');
        }
        $metadata = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (
            ($metadata['toolOrigin'] ?? '') !== $origin || !is_string($metadata['authorizationUrl'] ?? null) ||
                \local_videoq\client::origin($metadata['authorizationUrl']) . '/moodle/connect' !== $metadata['authorizationUrl']
        ) {
            throw new RuntimeException('Invalid discovery');
        }
        $expected = preg_replace('~/moodle/connect$~', '/teaching/connect', $metadata['authorizationUrl']);
        if (
            $result['status'] !== 200 || !str_starts_with($url, $expected . '?ticket=') ||
                !preg_match('/^[a-f0-9]{64}$/D', substr($url, strlen($expected . '?ticket=')))
        ) {
            throw new RuntimeException('Invalid teacher connection');
        }
        redirect($url);
    } catch (Throwable $error) {
        throw new moodle_exception('connectionfailed', 'local_videoq');
    }
}
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('teacherconnect', 'local_videoq'));
echo $OUTPUT->single_button(
    new moodle_url('/local/videoq/teacherconnect.php', ['course' => $courseid]),
    get_string('teacherconnect', 'local_videoq'),
    'post'
);
echo $OUTPUT->footer();
