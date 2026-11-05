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

namespace local_videoq;
defined('MOODLE_INTERNAL') || die();

/** Administrator-selected VideoQ host, session state and S256 proof binding. */
final class browser_connection {
    public static function start(string $url): string {
        global $CFG, $SESSION, $USER;
        require_capability('moodle/site:config', \context_system::instance());
        require_once($CFG->libdir . '/filelib.php');
        if (strlen($url) > 2048) {
            throw new \InvalidArgumentException('Invalid VideoQ URL');
        }
        $url = trim($url);
        $origin = client::origin($url);
        $parts = parse_url($url);
        if (!in_array($parts['path'] ?? '', ['', '/'], true) || isset($parts['query'])) {
            throw new \InvalidArgumentException('Expected a VideoQ origin');
        }
        $curl = new \curl();
        $curl->setHeader(['Accept: application/json']);
        $raw = $curl->get($origin . '/api/lms/pairing/discovery', [], client::http_options(15));
        if ($curl->get_errno() || (int)($curl->get_info()['http_code'] ?? 0) !== 200 || strlen($raw) > 8192) {
            throw new \RuntimeException('Connection unavailable');
        }
        $metadata = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        $api = $metadata['toolOrigin'] ?? '';
        $authorization = $metadata['authorizationUrl'] ?? '';
        if (
            !is_string($api) || client::origin($api) !== $api || !is_string($authorization) ||
                client::origin($authorization) . '/moodle/connect' !== $authorization
        ) {
            throw new \RuntimeException('Invalid connection metadata');
        }
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $SESSION->local_videoq_browser = ['state' => $state, 'verifier' => $verifier, 'origin' => $api,
            'userid' => (int)$USER->id, 'expires' => time() + 600];
        return $authorization . '?' . http_build_query(['moodle_url' => rtrim($CFG->wwwroot, '/'), 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')], '', '&', PHP_QUERY_RFC3986);
    }

    /** No credentials from the browser are used until its admin session matches. */
    public static function finish(string $state, string $code, string $error = ''): bool {
        global $SESSION, $USER;
        require_capability('moodle/site:config', \context_system::instance());
        $pending = $SESSION->local_videoq_browser ?? null;
        if (
            !is_array($pending) || !preg_match('/^[a-f0-9]{64}$/D', $state) ||
                !hash_equals($pending['state'], $state) || $pending['userid'] !== (int)$USER->id || $pending['expires'] <= time()
        ) {
            throw new \RuntimeException('Invalid or expired connection state');
        }
        if ($error !== '') {
            unset($SESSION->local_videoq_browser);
            return false;
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $code)) {
            throw new \RuntimeException('Invalid authorization code');
        }
        // Retain an interrupted callback only in this administrator's expiring
        // server session, so retry needs no code in the URL or copy/paste.
        $SESSION->local_videoq_browser['code'] = $code;
        $connectioncode = 'VIDEOQ2.' . base64_encode(json_encode(['origin' => $pending['origin'], 'secret' => $code], JSON_THROW_ON_ERROR));
        lti_connection_setup::connect($connectioncode, $pending['verifier']);
        unset($SESSION->local_videoq_browser);
        return true;
    }

    public static function retry(): bool {
        global $SESSION;
        $pending = $SESSION->local_videoq_browser ?? [];
        return self::finish($pending['state'] ?? '', $pending['code'] ?? '');
    }
}
