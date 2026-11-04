<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
define('NO_MOODLE_COOKIES', true);
require(__DIR__ . '/../../config.php');
header('Cache-Control: no-store');
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    http_response_code(400); echo json_encode(['error' => 'invalid_request']); exit;
}
try { echo json_encode(\local_videoq\service_auth::issue($_POST), JSON_THROW_ON_ERROR); }
catch (\Throwable $error) { http_response_code(401); echo json_encode(['error' => 'invalid_client']); }
