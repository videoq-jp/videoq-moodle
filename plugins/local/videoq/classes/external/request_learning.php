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

namespace local_videoq\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_videoq\native_api;

/**
 * Session-authenticated learning requests; upstream credentials remain private.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_learning extends external_api {
    /**
     * Describes the browser input. The actor and connection are server-derived.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Current Moodle course'),
            'operation' => new external_value(PARAM_ALPHANUMEXT, 'Learning operation'),
            'lesson' => new external_value(PARAM_ALPHANUMEXT, 'Optional legacy lesson', VALUE_DEFAULT, ''),
            'payload' => new external_value(PARAM_RAW, 'Operation JSON object', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Runs one request after Moodle's core AJAX login and sesskey checks.
     *
     * @param int $courseid Current Moodle course.
     * @param string $operation Allowlisted operation name.
     * @param string $lesson Optional legacy lesson anchor.
     * @param string $payload Operation JSON object.
     * @return array Public status, body and MIME type.
     */
    public static function execute(int $courseid, string $operation, string $lesson = '', string $payload = '{}'): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'operation' => $operation, 'lesson' => $lesson, 'payload' => $payload,
        ]);
        self::validate_context(\context_course::instance($params['courseid']));
        $input = native_api::input($params['operation'], $params['payload']);
        return native_api::request($params['courseid'], $params['lesson'], $params['operation'], $input);
    }

    /**
     * Preserves API conflict/refusal statuses for draft-safe clients.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, 'HTTP status of the learning operation'),
            'body' => new external_value(PARAM_RAW, 'JSON or CSV response'),
            'contenttype' => new external_value(PARAM_RAW, 'Allowlisted response MIME type'),
        ]);
    }
}
