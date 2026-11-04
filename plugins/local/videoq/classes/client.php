<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
namespace local_videoq;
defined('MOODLE_INTERNAL') || die();
final class client {
    private string $origin;
    private string $token;
    public function __construct() {
        $this->origin = rtrim((string)get_config('local_videoq', 'apiurl'), '/');
        $this->token = (string)get_config('local_videoq', 'synctoken');
        if (self::origin($this->origin) !== $this->origin || !preg_match('/^[a-f0-9]{64}$/D', $this->token)) {
            throw new \moodle_exception('invalidconfiguration', 'local_videoq');
        }
    }
    public static function origin(string $url): string {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Expected an HTTPS URL');
        }
        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '');
    }
    public static function http_options(int $timeout = 30): array {
        // Moodle's curl wrapper otherwise defaults SSL peer verification to off.
        return ['CURLOPT_FOLLOWLOCATION' => 0, 'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2];
    }
    public function request(string $method, string $path, ?array $body = null): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Authorization: Bearer ' . $this->token, 'Content-Type: application/json', 'Accept: application/json']);
        $options = self::http_options();
        $url = $this->origin . '/api/lms/' . $path;
        $json = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($method === 'GET') $response = $curl->get($url, [], $options);
        else if ($method === 'PUT') $response = $curl->put($url, $json, $options);
        else $response = $curl->post($url, $json, $options);
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $code < 200 || $code >= 300) throw new \RuntimeException('VideoQ request failed');
        $data = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid VideoQ response');
        return $data;
    }
    public function upload(array $upload, \stored_file $file): void {
        if (!isset($upload['url'], $upload['direct']) || !is_bool($upload['direct'])) throw new \RuntimeException('Invalid upload response');
        $origin = self::origin($upload['url']);
        if (!$upload['direct'] && $origin !== $this->origin) throw new \RuntimeException('Invalid API upload origin');
        $curl = new \curl();
        $headers = ['Content-Type: ' . $file->get_mimetype(), 'Content-Length: ' . $file->get_filesize(), 'Expect:'];
        if (!$upload['direct']) $headers[] = 'Authorization: Bearer ' . $this->token;
        $curl->setHeader($headers);
        $temp = $file->copy_content_to_temp('local_videoq');
        try {
            $curl->put($upload['url'], ['file' => $temp], self::http_options(1800) + ['CURLOPT_USERPWD' => false]);
            $code = (int)($curl->get_info()['http_code'] ?? 0);
            if ($curl->get_errno() || $code < 200 || $code >= 300) throw new \RuntimeException('Video upload failed');
        } finally { if (is_file($temp)) unlink($temp); }
    }
}
