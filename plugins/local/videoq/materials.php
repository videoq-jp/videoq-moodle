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
 * Handles authenticated materials service requests.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
require(__DIR__ . '/../../config.php');
header('Cache-Control: no-store');
header('Content-Type: application/json');
try {
    $type = \local_videoq\service_auth::authenticate();
} catch (\Throwable $error) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new \RuntimeException('Invalid request');
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) {
        throw new \RuntimeException('Request too large');
    }
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $includeentries = ($input['includeCourseEntries'] ?? false) === true;
    if (($input['action'] ?? '') === 'courses') {
        $ids = $DB->get_fieldset_sql(
            'SELECT DISTINCT courseid FROM {local_videoq_sync} WHERE typeid = ? AND (enabled = 1 OR status <> ?) ORDER BY courseid',
            [(int)$type->id, 'disabled']
        );
        if ($includeentries && \local_videoq\course_entry::available()) {
            $entries = $DB->get_fieldset_sql('SELECT courseid FROM {local_videoq_courses}
                WHERE typeid = ? AND (enabled = 1 OR status <> ?)', [(int)$type->id, 'disabled']);
            $ids = array_values(array_unique(array_merge($ids, $entries)));
            sort($ids, SORT_NUMERIC);
        }
        if (count($ids) > 1000) {
            throw new \RuntimeException('Too many contexts');
        }
        echo json_encode(['contextIds' => array_map('strval', $ids)], JSON_THROW_ON_ERROR);
        exit;
    }
    if (!is_string($input['contextId'] ?? null) || !preg_match('/^[1-9][0-9]{0,17}$/D', $input['contextId'])) {
        throw new \RuntimeException('Invalid context');
    }
    $courseid = (int)$input['contextId'];
    $result = match ($input['action'] ?? '') {
        'manifest' => \local_videoq\materials_service::manifest(
            $courseid,
            (int)$type->id,
            $includeentries,
            ($input['includeUserVisibility'] ?? false) === true
        ),
        'access' => \local_videoq\materials_service::access(
            $courseid,
            (int)$type->id,
             (string)($input['resourceLinkId'] ?? ''),
            (string)($input['subject'] ?? ''),
            $includeentries
        ),

        'export' => \local_videoq\materials_service::export($courseid, (int)$type->id, $input),
        'ack' => \local_videoq\materials_service::acknowledge($courseid, (int)$type->id, $input),
        'status' => \local_videoq\materials_service::status($courseid, (int)$type->id, (string)($input['jobId'] ?? '')),
        default => throw new \RuntimeException('Unknown operation'),
    };
    if (($input['action'] ?? '') === 'export' && $result['status'] === 'queued') {
        http_response_code(202);
    }
    if (($input['action'] ?? '') === 'access' && ($input['includePermissions'] ?? false) !== true) {
        unset($result['permissions']);
    }
    if (!$includeentries) {
        unset($result['courseEntry']);
    }
    if (($input['action'] ?? '') === 'access' && ($input['includeContentManagement'] ?? false) !== true) {
        $result['resources'] = array_values(array_filter($result['resources'], fn($item) => ($item['learning'] ?? true) === true));
        foreach ($result['resources'] as &$item) {
            unset($item['management'], $item['learning'], $item['generation']);
        }
        unset($item);
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $error) {
    http_response_code(403);
    echo json_encode(['error' => 'request_rejected']);
}
