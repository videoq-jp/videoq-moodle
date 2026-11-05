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
 * Sends authenticated requests to legacy synchronization endpoints.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videoq;
/**
 * Sends authenticated requests to legacy synchronization endpoints.
 */
final class client {
    /** @var string Canonical HTTPS origin of the configured legacy service. */
    private string $origin;
    /** @var string Site-owned legacy synchronization credential. */
    private string $token;
    /**
     * Loads and validates the configured legacy synchronization credentials.
     *
     */
    public function __construct() {
        $this->origin = rtrim((string)get_config('local_videoq', 'apiurl'), '/');
        $this->token = (string)get_config('local_videoq', 'synctoken');
        if (self::origin($this->origin) !== $this->origin || !preg_match('/^[a-f0-9]{64}$/D', $this->token)) {
            throw new \moodle_exception('invalidconfiguration', 'local_videoq');
        }
    }
    /**
     * Returns the canonical HTTPS origin after validating the service URL.
     *
     * @param string $url HTTPS service or upload URL to validate.
     * @return string
     */
    public static function origin(string $url): string {
        $parts = parse_url($url);
        if (
            !$parts
            || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('Expected an HTTPS URL');
        }
        return 'https://' . strtolower($parts['host']) . (isset($parts['port'])
            && $parts['port'] !== 443 ? ':' . $parts['port'] : '');
    }
    /**
     * Returns bounded Moodle HTTP options with TLS verification and no redirects.
     *
     * @param int $timeout Maximum HTTP request duration in seconds.
     * @return array
     */
    public static function http_options(int $timeout = 30): array {
        // Moodle's curl wrapper otherwise defaults SSL peer verification to off.
        return ['CURLOPT_FOLLOWLOCATION' => 0, 'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2];
    }
    /**
     * Sends an authenticated JSON request to the configured legacy service.
     *
     * @param string $method HTTP request method.
     * @param string $path Service-relative request path.
     * @param array|null $body Optional JSON request payload.
     * @return array
     */
    public function request(string $method, string $path, ?array $body = null): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Authorization: Bearer ' . $this->token, 'Content-Type: application/json', 'Accept: application/json']);
        $options = self::http_options();
        $url = $this->origin . '/api/lms/' . $path;
        $json = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($method === 'GET') {
            $response = $curl->get($url, [], $options);
        } else if ($method === 'PUT') {
            $response = $curl->put($url, $json, $options);
        } else {
            $response = $curl->post($url, $json, $options);
        }
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $code < 200 || $code >= 300) {
            throw new \RuntimeException('VideoQ request failed');
        }
        $data = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid VideoQ response');
        }
        return $data;
    }
    /**
     * Transfers the source file using the service-provided signed upload target.
     *
     * @param array $upload Service-provided upload target and metadata.
     * @param \stored_file $file Source video file.
     * @return void
     */
    public function upload(array $upload, \stored_file $file): void {
        if (!isset($upload['url'], $upload['direct']) || !is_bool($upload['direct'])) {
            throw new \RuntimeException('Invalid upload response');
        }
        $origin = self::origin($upload['url']);
        if (!$upload['direct'] && $origin !== $this->origin) {
            throw new \RuntimeException('Invalid API upload origin');
        }
        $curl = new \curl();
        $headers = ['Content-Type: ' . $file->get_mimetype(), 'Content-Length: ' . $file->get_filesize(), 'Expect:'];
        if (!$upload['direct']) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        $curl->setHeader($headers);
        $temp = $file->copy_content_to_temp('local_videoq');
        try {
            $curl->put($upload['url'], ['file' => $temp], self::http_options(1800) + ['CURLOPT_USERPWD' => false]);
            $code = (int)($curl->get_info()['http_code'] ?? 0);
            if ($curl->get_errno() || $code < 200 || $code >= 300) {
                throw new \RuntimeException('Video upload failed');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
