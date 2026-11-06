<?php
// This file is part of Moodle - http://moodle.org/
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
 * Platform-signed external requests used only by Moodle's Privacy API provider.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * No learner bearer, owner key, browser cookie or launch role authorizes these calls.
 */
final class privacy_api {
    /** @var string Residual external records without a surviving course mapping. */
    public const ORPHAN = '@orphan';

    /**
     * Retains public connection identities for later requests after reconnecting.
     *
     * @param string $origin Successfully approved API origin.
     * @param \stdClass $type Registered LTI type.
     */
    public static function remember(string $origin, \stdClass $type): void {
        $server = self::identity($origin, $type);
        $history = self::history();
        $id = hash('sha256', json_encode($server, JSON_THROW_ON_ERROR));
        if (($history[$id] ?? null) !== $server) {
            $history[$id] = $server;
            set_config('privacyservers', json_encode($history, JSON_THROW_ON_ERROR), 'local_videoq');
        }
    }

    /**
     * Constructs a public connection identity without credentials.
     *
     * @param string $origin Approved API origin.
     * @param \stdClass $type Registered tool type.
     * @return array Public registration tuple.
     */
    private static function identity(string $origin, \stdClass $type): array {
        global $CFG;
        $origin = rtrim($origin, '/');
        if (client::origin($origin) !== $origin || empty($type->clientid) || empty($type->id)) {
            throw new \moodle_exception('privacyunavailable', 'local_videoq');
        }
        return ['origin' => $origin, 'issuer' => rtrim($CFG->wwwroot, '/'),
            'clientId' => (string)$type->clientid, 'deploymentId' => (string)$type->id];
    }

    /**
     * Loads connection history without credentials.
     *
     * @return array Public server identities keyed by a stable local hash.
     */
    private static function history(): array {
        $raw = (string)get_config('local_videoq', 'privacyservers');
        if ($raw === '') {
            return [];
        }
        try {
            $history = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($history)) {
                throw new \RuntimeException('Invalid history');
            }
            return $history;
        } catch (\Throwable $error) {
            throw new \moodle_exception('privacyunavailable', 'local_videoq');
        }
    }

    /**
     * Includes the existing connection when upgrading an older installation.
     *
     * @param bool $remembercurrent Persist before reconnecting; caller holds the connection-setup lock.
     * @return array Known approved servers, including inactive historical connections.
     */
    public static function servers(bool $remembercurrent = false): array {
        global $CFG, $DB;
        // Metadata-only tooling has no installed Moodle site or connection.
        if (empty($CFG->wwwroot)) {
            return [];
        }
        $history = self::history();
        $origin = (string)get_config('local_videoq', 'apiurl');
        $typeid = (int)get_config('local_videoq', 'typeid');
        if ($origin !== '' && $typeid > 0) {
            $type = $DB->get_record('lti_types', ['id' => $typeid]);
            if ($type) {
                $server = self::identity($origin, $type);
                $history[hash('sha256', json_encode($server, JSON_THROW_ON_ERROR))] = $server;
                if ($remembercurrent) {
                    self::remember($origin, $type);
                }
            } else if (!$history) {
                throw new \moodle_exception('privacyunavailable', 'local_videoq');
            }
        }
        return $history;
    }

    /**
     * Sends a short-lived purpose-bound, single-use assertion with verified TLS.
     *
     * @param array $server Approved connection identity.
     * @param array $request Strict privacy operation, selected by the provider.
     * @return array Bounded decoded response.
     */
    public static function request(array $server, array $request): array {
        global $CFG;
        try {
            if (
                client::origin($server['origin']) !== $server['origin'] ||
                $server['issuer'] !== rtrim($CFG->wwwroot, '/')
            ) {
                throw new \RuntimeException('The platform identity changed');
            }
            require_once($CFG->libdir . '/filelib.php');
            $key = \mod_lti\local\ltiopenid\jwks_helper::get_private_key();
            $now = time();
            $random = bin2hex(random_bytes(16));
            $jti = substr($random, 0, 8) . '-' . substr($random, 8, 4) . '-4' . substr($random, 13, 3)
                . '-8' . substr($random, 17, 3) . '-' . substr($random, 20);
            $endpoint = $server['origin'] . '/api/lms/privacy';
            $assertion = \Firebase\JWT\JWT::encode([
                'iss' => $server['issuer'], 'sub' => $server['clientId'], 'aud' => $endpoint,
                'deploymentId' => $server['deploymentId'], 'iat' => $now, 'exp' => $now + 60,
                'jti' => $jti, 'version' => 1, 'request' => $request,
            ], $key['key'], 'RS256', $key['kid'], ['typ' => 'videoq-privacy+jwt']);
            $curl = new \curl();
            $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
            $body = '';
            $options = client::http_options(30);
            $options['CURLOPT_WRITEFUNCTION'] = static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 524288) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            };
            $curl->post($endpoint, json_encode(['assertion' => $assertion], JSON_THROW_ON_ERROR), $options);
            if ($curl->get_errno() || (int)($curl->get_info()['http_code'] ?? 0) !== 200) {
                throw new \RuntimeException('Privacy request failed');
            }
            $value = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($value)) {
                throw new \RuntimeException('Invalid response');
            }
            return $value;
        } catch (\Throwable $error) {
            // Errors may contain signed assertions, keys or personal response data.
            throw new \moodle_exception('privacyunavailable', 'local_videoq');
        }
    }

    /**
     * Streams all context identifiers without truncating to the first page.
     *
     * @param array $server Approved connection.
     * @param int|null $userid Subject, or null for the public context catalogue.
     * @return \Generator Context identifiers.
     */
    public static function contexts(array $server, ?int $userid = null): \Generator {
        $after = '';
        do {
            $request = ['action' => $userid === null ? 'catalog' : 'contexts', 'after' => $after];
            if ($userid !== null) {
                $request['subject'] = (string)$userid;
            }
            $response = self::request($server, $request);
            if (!isset($response['contexts']) || !is_array($response['contexts']) || count($response['contexts']) > 100) {
                throw new \moodle_exception('privacyunavailable', 'local_videoq');
            }
            foreach ($response['contexts'] as $id) {
                if (!is_string($id) || strlen($id) > 255 || strcmp($id, $after) <= 0) {
                    throw new \moodle_exception('privacyunavailable', 'local_videoq');
                }
                yield $id;
                $after = $id;
            }
            $next = $response['nextAfter'] ?? null;
            if ($next !== null && (!is_string($next) || $next !== $after || !$response['contexts'])) {
                throw new \moodle_exception('privacyunavailable', 'local_videoq');
            }
        } while ($next !== null);
    }

    /**
     * Resolves a surviving course context; residual data uses the system context.
     *
     * @param string $id External Moodle course identifier.
     * @return \context Course or residual system context.
     */
    public static function context(string $id): \context {
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            $context = \context_course::instance((int)$id, IGNORE_MISSING);
            if ($context) {
                return $context;
            }
        }
        return \context_system::instance();
    }

    /**
     * Intersects current external locations with the core-approved contexts.
     *
     * @param array $server Approved connection.
     * @param int|null $userid Subject, or null for context-wide operations.
     * @param array $approved Core-approved context IDs.
     * @return array External course/residual IDs selected for this request.
     */
    public static function targets(array $server, ?int $userid, array $approved): array {
        $targets = [];
        foreach (self::contexts($server, $userid) as $id) {
            if (in_array(self::context($id)->id, $approved, true)) {
                $targets[] = $id;
            }
        }
        return $targets;
    }

    /**
     * Returns all local identities, including former/deleted users, in bounded batches.
     *
     * @return \Generator Batches of at most 100 Moodle IDs.
     */
    public static function user_batches(): \Generator {
        global $DB;
        $records = $DB->get_recordset_select('user', 'id > 0', [], 'id', 'id');
        try {
            $batch = [];
            foreach ($records as $user) {
                $batch[] = (string)$user->id;
                if (count($batch) === 100) {
                    yield $batch;
                    $batch = [];
                }
            }
            if ($batch) {
                yield $batch;
            }
        } finally {
            $records->close();
        }
    }
}
