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
// A server-to-server permission check; never opens media or creates a Moodle session.

define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid access');
    }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($raw) > 8192) {
        throw new RuntimeException('Invalid access');
    }
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    echo json_encode(\local_videoq\course_access::resolve($input['token'], $input['subject'], $input['contextId'], $input['resourceLinkId']), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(403);
    echo '{"error":"ACCESS_DENIED"}';
}
