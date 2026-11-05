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

define('NO_MOODLE_COOKIES', true);
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
    error_log(json_encode(['event' => 'videoq_service_token_rejected', 'type' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()]));
    http_response_code(401);
    echo json_encode(['error' => 'invalid_client']);
}
