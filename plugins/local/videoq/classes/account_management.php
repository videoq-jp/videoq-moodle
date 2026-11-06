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
 * Builds the independently authenticated account-management handoff.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Public registration identifiers select the connected account; no credential is transferred.
 */
final class account_management {
    /**
     * Resolves the configured API's advertised management page.
     *
     * @return \moodle_url Fixed management page with public registration identifiers.
     */
    public static function url(): \moodle_url {
        global $CFG;
        $type = synchronizer::check_connection();
        require_once($CFG->libdir . '/filelib.php');
        $origin = rtrim((string)get_config('local_videoq', 'apiurl'), '/');
        if (client::origin($origin) !== $origin) {
            throw new \RuntimeException('Invalid configured origin');
        }
        $curl = new \curl();
        $curl->setHeader(['Accept: application/json']);
        $raw = $curl->get($origin . '/api/lms/pairing/discovery', [], client::http_options(15));
        if ($curl->get_errno() || (int)($curl->get_info()['http_code'] ?? 0) !== 200 || strlen($raw) > 8192) {
            throw new \RuntimeException('Management unavailable');
        }
        $metadata = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        $management = $metadata['managementUrl'] ?? '';
        if (
            ($metadata['toolOrigin'] ?? '') !== $origin || !is_string($management) ||
                client::origin($management) . '/moodle/manage' !== $management
        ) {
            throw new \RuntimeException('Invalid management metadata');
        }
        return new \moodle_url($management, [
            'issuer' => rtrim($CFG->wwwroot, '/'),
            'clientId' => $type->clientid,
            'deploymentId' => (string)$type->id,
        ]);
    }
}
