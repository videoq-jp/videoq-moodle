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
 * Relays the current Moodle user's answer stream on the Moodle origin.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);
require(__DIR__ . '/../../config.php');

header('Cache-Control: private, no-store');
header('Content-Type: text/event-stream; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Accel-Buffering: no');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2097152) {
        throw new RuntimeException('Invalid stream request');
    }
    require_login();
    require_sesskey();
    $courseid = required_param('courseid', PARAM_INT);
    $lesson = optional_param('lesson', '', PARAM_ALPHANUMEXT);
    $input = \local_videoq\native_api::input('messages/stream', required_param('payload', PARAM_RAW));
    \core_php_time_limit::raise(360);
    \local_videoq\native_api::stream($courseid, $lesson, $input, static function (string $chunk): bool {
        echo $chunk;
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        return !connection_aborted();
    });
} catch (Throwable $error) {
    // Never render Moodle's HTML exception page or a private upstream traceback.
    debugging('VideoQ native answer stream could not complete.', DEBUG_DEVELOPER);
    echo "\n\ndata: " . json_encode(['type' => 'error', 'code' => 'STREAM_FAILED',
        'message' => get_string('nativeunavailable', 'local_videoq')]) . "\n\n";
}
