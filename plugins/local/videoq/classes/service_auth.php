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
 * Authenticates VideoQ service assertions and bearer tokens.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;
defined('MOODLE_INTERNAL') || die();

/**
 * LTI tool-key service authentication. No separately configured bearer credential.
 */
final class service_auth {
    public const SCOPE = 'https://videoq.org/spec/lti-materials/v1';
    public static function type(): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        if (get_config('local_videoq', 'servicemode') !== 'lti') {
            throw new \RuntimeException('Service disabled');
        }
        $type = $DB->get_record('lti_types', ['id' => (int)get_config('local_videoq', 'typeid')], '*', MUST_EXIST);
        if ($type->ltiversion !== LTI_VERSION_1P3 || (int)$type->state !== LTI_TOOL_STATE_CONFIGURED) {
            throw new \RuntimeException('Tool unavailable');
        }
        return $type;
    }
    public static function issue(array $input): array {
        global $CFG, $DB;
        $type = self::type();
        if (
            ($input['grant_type'] ?? '') !== 'client_credentials' ||
                ($input['client_assertion_type'] ?? '') !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer' ||
                ($input['scope'] ?? '') !== self::SCOPE
        ) {
            throw new \RuntimeException('Invalid token request');
        }
        $jwt = $input['client_assertion'] ?? '';
        if (!is_string($jwt) || strlen($jwt) > 8192) {
            throw new \RuntimeException('Invalid assertion');
        }
        lti_verify_jwt_signature($type->id, $type->clientid, $jwt);
        $parts = explode('.', $jwt);
        $claims = json_decode(\Firebase\JWT\JWT::urlsafeB64Decode($parts[1]), true, 16, JSON_THROW_ON_ERROR);
        $now = time();
        if (
            ($claims['iss'] ?? '') !== $type->clientid || ($claims['sub'] ?? '') !== $type->clientid ||
                ($claims['aud'] ?? '') !== $CFG->wwwroot . '/local/videoq/token.php' ||
                !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null) ||
                $claims['iat'] > $now + 5 || $claims['iat'] < $now - 65 || $claims['exp'] <= $now ||
                $claims['exp'] - $claims['iat'] > 60 ||
                ($claims['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] ?? '') !== (string)$type->id ||
                !is_string($claims['jti'] ?? null) || !preg_match('/^[a-f0-9-]{32,64}$/D', $claims['jti'])
        ) {
            throw new \RuntimeException('Invalid assertion');
        }
        // Unique index rejects replay atomically. Retain beyond accepted assertion clock skew.
        $DB->delete_records_select('local_videoq_assertions', 'expires < ?', [$now - 120]);
        $DB->insert_record('local_videoq_assertions', (object)['assertionhash' => hash('sha256', $type->id . ':' . $claims['jti']), 'expires' => $claims['exp']]);
        $token = lti_new_access_token($type->id, [self::SCOPE]);
        return ['access_token' => $token->token, 'token_type' => 'Bearer', 'expires_in' => LTI_ACCESS_TOKEN_LIFE, 'scope' => self::SCOPE];
    }
    public static function authenticate(): \stdClass {
        global $DB;
        $type = self::type();
        $headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
        $authorization = $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer ([a-f0-9]+)$/D', $authorization, $match)) {
            throw new \RuntimeException('Invalid authorization');
        }
        $token = $DB->get_record('lti_access_tokens', ['token' => $match[1], 'typeid' => $type->id]);
        if (!$token || $token->validuntil <= time() || json_decode($token->scope, true) !== [self::SCOPE]) {
            throw new \RuntimeException('Invalid token');
        }
        return $type;
    }
}
