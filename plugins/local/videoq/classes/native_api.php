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

/**
 * Calls the learning API with Moodle's private, user-bound server assertions.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;

/**
 * Keeps all assertion and session state on the Moodle server.
 */
final class native_api {
    /** @var array Ordinary operations available through core/ajax. */
    public const OPERATIONS = ['course-history', 'playback', 'materials', 'transcript', 'history', 'messages', 'feedback',
        'conversations', 'qa', 'management', 'tags', 'reports', 'teacher/connect'];

    /**
     * Returns fresh Moodle authority to the PHP page renderer without credentials.
     *
     * @param int $courseid Current course.
     * @param string $lessonid Optional legacy anchor.
     * @return array Current Moodle resources and capabilities.
     */
    public static function scope(int $courseid, string $lessonid = ''): array {
        try {
            return native_context::resolve($courseid, $lessonid)['scope'];
        } catch (\Throwable $error) {
            throw new \moodle_exception('nativeunavailable', 'local_videoq');
        }
    }

    /**
     * Accepts a bounded JSON object, preserving nested object/array distinctions.
     *
     * @param string $operation Operation name.
     * @param string $json UTF-8 JSON payload.
     * @return array Top-level object properties.
     */
    public static function input(string $operation, string $json): array {
        try {
            if (
                (!in_array($operation, self::OPERATIONS, true) && $operation !== 'messages/stream') ||
                strlen($json) > ($operation === 'management' ? 5 * 1024 * 1024 : 128 * 1024)
            ) {
                throw new \RuntimeException('Invalid operation input');
            }
            $value = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
            if (!$value instanceof \stdClass) {
                throw new \RuntimeException('Operation must be an object');
            }
            return get_object_vars($value);
        } catch (\Throwable $error) {
            throw new \moodle_exception('nativeunavailable', 'local_videoq');
        }
    }

    /**
     * Performs one bounded server request without retrying uncertain mutations.
     *
     * @param int $courseid Moodle course identifier.
     * @param string $lessonid Optional legacy anchor.
     * @param string $operation Operation name, not an upstream URL.
     * @param array $input Validated JSON object.
     * @return array Public status, response body and MIME type only.
     */
    public static function request(int $courseid, string $lessonid, string $operation, array $input): array {
        try {
            if (!in_array($operation, self::OPERATIONS, true)) {
                throw new \RuntimeException('Unsupported operation');
            }
            $request = self::prepare($courseid, $lessonid, $operation, $input);
            $curl = new \curl();
            $curl->setHeader($request['headers']);
            $body = '';
            $options = client::http_options(30);
            $options['CURLOPT_WRITEFUNCTION'] = static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 8388608) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            };
            $curl->post($request['endpoint'], $request['body'], $options);
            $info = $curl->get_info();
            $status = (int)($info['http_code'] ?? 0);
            $mime = strtolower(trim(explode(';', $info['content_type'] ?? '')[0]));
            if (
                $curl->get_errno() || $status < 200 || $status > 599 ||
                !in_array($mime, ['application/json', 'text/csv'], true)
            ) {
                throw new \RuntimeException('Native service unavailable');
            }
            if ($mime === 'application/json') {
                json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            }
            return ['status' => $status, 'body' => $body, 'contenttype' => $mime];
        } catch (\Throwable $error) {
            if (
                $error instanceof \moodle_exception &&
                in_array($error->errorcode ?? null, ['nativeaccessdenied', 'nopermissions', 'requireloginerror'], true)
            ) {
                return ['status' => 403, 'body' => '{"error":{"code":"ACCESS_DENIED"}}', 'contenttype' => 'application/json'];
            }
            // Construct a new exception here: private assertion/curl arguments
            // from nested calls must not appear in Moodle's developer traceback.
            throw new \moodle_exception('nativeunavailable', 'local_videoq');
        }
    }

    /**
     * Relays an answer incrementally without holding the Moodle session lock.
     *
     * @param int $courseid Current course.
     * @param string $lessonid Optional legacy anchor.
     * @param array $input Question and playback context.
     * @param callable $write Writes an SSE chunk; false aborts the upstream request.
     * @return void
     */
    public static function stream(int $courseid, string $lessonid, array $input, callable $write): void {
        try {
            $request = self::prepare($courseid, $lessonid, 'messages/stream', $input);
            \core\session\manager::write_close();
            $curl = new \curl();
            $curl->setHeader($request['headers']);
            $received = 0;
            $accepted = false;
            $options = client::http_options(300);
            $options['CURLOPT_WRITEFUNCTION'] = static function ($handle, string $chunk) use (&$received, &$accepted, $write): int {
                $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $mime = strtolower(trim(explode(';', (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0]));
                if (
                    $status !== 200 || $mime !== 'text/event-stream' || connection_aborted() ||
                    $received + strlen($chunk) > 8388608
                ) {
                    return 0;
                }
                $received += strlen($chunk);
                $accepted = true;
                return $write($chunk) === false ? 0 : strlen($chunk);
            };
            $curl->post($request['endpoint'], $request['body'], $options);
            if ($curl->get_errno() || !$accepted) {
                throw new \RuntimeException('Native stream unavailable');
            }
        } catch (\Throwable $error) {
            throw new \moodle_exception('nativeunavailable', 'local_videoq');
        }
    }

    /**
     * Prepares a signed user/course request while the Moodle session is locked.
     *
     * @param int $courseid Moodle course identifier.
     * @param string $lessonid Optional legacy anchor.
     * @param string $operation Allowlisted operation.
     * @param array $input Operation data.
     * @return array Private HTTP request; never expose to the browser.
     */
    private static function prepare(int $courseid, string $lessonid, string $operation, array $input): array {
        global $CFG, $USER;
        $context = native_context::resolve($courseid, $lessonid);
        native_context::require_operation($context, $operation, $input);
        $body = json_encode((object)$input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($body) > ($operation === 'management' ? 5 * 1024 * 1024 : 128 * 1024)) {
            throw new \RuntimeException('Oversized operation');
        }
        $window = self::window($context);
        $now = time();
        $endpoint = $context['origin'] . '/api/lms/native/' . $operation;
        $key = \mod_lti\local\ltiopenid\jwks_helper::get_private_key();
        $nonce = bin2hex(random_bytes(16));
        $nonce[12] = '4';
        $nonce[16] = dechex((hexdec($nonce[16]) & 3) | 8);
        $nonce = substr($nonce, 0, 8) . '-' . substr($nonce, 8, 4) . '-' . substr($nonce, 12, 4)
            . '-' . substr($nonce, 16, 4) . '-' . substr($nonce, 20);
        $claims = ['iss' => $context['issuer'], 'sub' => $context['clientId'], 'aud' => $endpoint,
            'deploymentId' => $context['deploymentId'], 'subject' => $context['subject'],
            'author' => ['name' => \core_text::substr(fullname($USER), 0, 255),
                'email' => \core_text::substr((string)$USER->email, 0, 320)],
            'contextId' => $context['contextId'], 'resourceLinkId' => $context['resourceLinkId'],
            'bodySha256' => hash('sha256', $body), 'iat' => $now, 'exp' => min($now + 60, $window['expires']),
            'jti' => $nonce, 'version' => 1, 'locale' => str_starts_with(current_language(), 'ja') ? 'ja' : 'en',
            'sessionId' => $window['id'], 'sessionStartedAt' => $window['started'], 'sessionExpiresAt' => $window['expires'],
            'accessProof' => $context['accessProof']];
        $assertion = \Firebase\JWT\JWT::encode($claims, $key['key'], 'RS256', $key['kid'], ['typ' => 'videoq-native+jwt']);
        require_once($CFG->libdir . '/filelib.php');
        return ['endpoint' => $endpoint, 'body' => $body,
            'headers' => ['Content-Type: application/json',
                'Accept: ' . ($operation === 'messages/stream' ? 'text/event-stream' : 'application/json, text/csv'),
                'Authorization: Bearer ' . $assertion]];
    }

    /**
     * Keeps one immutable native identifier/window per local actor and anchor.
     *
     * @param array $context Current server-resolved identity.
     * @return array Private random identifier and fixed lifetime.
     */
    private static function window(array $context): array {
        global $SESSION;
        $key = hash('sha256', json_encode([$context['origin'], $context['issuer'], $context['clientId'],
            $context['deploymentId'], $context['subject'], $context['contextId'], $context['resourceLinkId'],
            $context['generation']], JSON_THROW_ON_ERROR));
        $cache = $SESSION->local_videoq_native ?? [];
        if (!is_array($cache)) {
            throw new \RuntimeException('Invalid native session cache');
        }
        $now = time();
        foreach ($cache as $id => $value) {
            if (
                !is_array($value) || !isset($value['id'], $value['started'], $value['expires']) ||
                !is_int($value['started']) || !is_int($value['expires']) ||
                !is_string($value['id']) || !preg_match('/^[a-f0-9]{64}$/D', $value['id']) ||
                $value['expires'] - $value['started'] !== 3600 || $value['started'] > $now
            ) {
                throw new \RuntimeException('Invalid native session cache');
            }
            if ($value['expires'] <= $now) {
                unset($cache[$id]);
            }
        }
        if (!isset($cache[$key])) {
            if (count($cache) >= 128) {
                throw new \RuntimeException('Native session cache is full');
            }
            $cache[$key] = ['id' => bin2hex(random_bytes(32)), 'started' => $now, 'expires' => $now + 3600];
        }
        $SESSION->local_videoq_native = $cache;
        return $cache[$key];
    }
}
