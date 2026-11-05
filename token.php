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
 * Issues scoped OAuth tokens to the registered VideoQ service.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
// Keep developer diagnostics out of OAuth JSON responses.
define('NO_DEBUG_DISPLAY', true);
require(__DIR__ . '/../../config.php');
header('Cache-Control: no-store');
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request']);
    exit;
}
try {
    echo json_encode(\local_videoq\service_auth::issue($_POST), JSON_THROW_ON_ERROR);
} catch (\Throwable $error) {
    debugging('VideoQ service token request rejected.', DEBUG_DEVELOPER);
    http_response_code(401);
    echo json_encode(['error' => 'invalid_client']);
}
