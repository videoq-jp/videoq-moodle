<?php
/** Exercise private assertions, immutable sessions and bounded public responses. */
declare(strict_types=1);

namespace Firebase\JWT {
    final class JWT {
        public static array $signed = [];
        public static function encode(array $claims, string $key, string $alg, string $kid, array $header): string {
            \check($key === 'private-test-key' && $alg === 'RS256' && $kid === 'test-kid', 'Registered signing key');
            self::$signed[] = ['claims' => $claims, 'header' => $header];
            return 'private-test-assertion';
        }
    }
}
namespace mod_lti\local\ltiopenid {
    final class jwks_helper {
        public static function get_private_key(): array { return ['key' => 'private-test-key', 'kid' => 'test-kid']; }
    }
}
namespace core\session {
    final class manager {
        public static bool $closed = false;
        public static function write_close(): void { self::$closed = true; }
    }
}
namespace local_videoq {
    final class native_context {
        public static bool $allowed = true;
        public static string $subject = '7';
        public static function resolve(int $courseid, string $lesson): array {
            if (!self::$allowed) throw new \RuntimeException('private-test-context');
            return ['origin' => 'https://videoq.example', 'issuer' => 'https://moodle.example',
                'clientId' => 'client-1', 'deploymentId' => '3', 'subject' => self::$subject,
                'contextId' => (string)$courseid, 'resourceLinkId' => '501',
                'generation' => 2, 'accessProof' => 'private-test-proof'];
        }
        public static function require_operation(array $context, string $operation, array $input): void {}
    }
}
namespace {
    final class moodle_exception extends RuntimeException {
        public function __construct(string $errorcode, string $component) { parent::__construct($errorcode); }
    }
    function current_language(): string { return 'ja'; }
    function check(bool $valid, string $label): void { if (!$valid) throw new RuntimeException($label); }
    function rejects(callable $action, string $label): void {
        try { $action(); } catch (moodle_exception $error) {
            check(!str_contains((string)$error, 'private-test-'), 'Exception leaked private call arguments');
            return;
        }
        throw new RuntimeException($label);
    }
    final class curl {
        public static int $status = 200;
        public static string $mime = 'application/json';
        public static string $body = '{"ok":true}';
        public static array $requests = [];
        private int $errno = 0;
        private array $headers = [];
        public function setHeader(array $headers): void { $this->headers = $headers; }
        public function post(string $url, string $body, array $options): void {
            check($options['CURLOPT_FOLLOWLOCATION'] === 0 && $options['CURLOPT_SSL_VERIFYPEER'] === true &&
                $options['CURLOPT_SSL_VERIFYHOST'] === 2, 'Verified TLS without redirects');
            check(in_array('Authorization: Bearer private-test-assertion', $this->headers, true), 'Private authorization');
            self::$requests[] = ['url' => $url, 'body' => $body];
            if (str_ends_with($url, '/messages/stream')) {
                check(\core\session\manager::$closed, 'Stream held the Moodle session lock');
                throw new RuntimeException('private-test-upstream-error');
            }
            if ($options['CURLOPT_WRITEFUNCTION'](null, self::$body) !== strlen(self::$body)) $this->errno = 23;
        }
        public function get_info(): array { return ['http_code' => self::$status, 'content_type' => self::$mime]; }
        public function get_errno(): int { return $this->errno; }
    }
    $directory = sys_get_temp_dir() . '/videoq-native-api-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/filelib.php', '<?php');
    $CFG = (object)['libdir' => $directory];
    $SESSION = new stdClass();
    require dirname(__DIR__) . '/plugins/local/videoq/classes/client.php';
    require dirname(__DIR__) . '/plugins/local/videoq/classes/native_api.php';
    use local_videoq\native_api as Api;
    use Firebase\JWT\JWT;
    try {
        foreach (['[]', 'null', '"text"', '{broken', str_repeat(' ', 131073)] as $input) {
            rejects(fn() => Api::input('materials', $input), 'Invalid JSON accepted');
        }
        rejects(fn() => Api::input('https://attacker.example', '{}'), 'Caller-selected URL accepted');
        $input = Api::input('messages', '{"message":"日本語/動画","nested":{},"array":[]}');
        $response = Api::request(42, '', 'messages', $input);
        check($response === ['status' => 200, 'body' => '{"ok":true}', 'contenttype' => 'application/json'], 'Public envelope');
        $first = JWT::$signed[0]; $claims = $first['claims']; $request = curl::$requests[0];
        check($first['header'] === ['typ' => 'videoq-native+jwt'] && $claims['version'] === 1, 'Native protocol isolation');
        check($request['url'] === $claims['aud'] && $request['url'] === 'https://videoq.example/api/lms/native/messages', 'Exact operation audience');
        check($claims['bodySha256'] === hash('sha256', $request['body']), 'Exact UTF-8 body binding');
        check(str_contains($request['body'], '日本語/動画') && str_contains($request['body'], '"nested":{}') &&
            str_contains($request['body'], '"array":[]'), 'JSON semantics changed');
        check($claims['subject'] === '7' && $claims['contextId'] === '42' && $claims['locale'] === 'ja', 'Server identity');
        check($claims['exp'] - $claims['iat'] <= 60 && $claims['sessionExpiresAt'] - $claims['sessionStartedAt'] === 3600, 'Bounded lifetimes');
        check((bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $claims['jti']), 'UUID nonce');
        $cache = $SESSION->local_videoq_native;
        curl::$status = 403; curl::$body = '{"error":"revoked"}';
        check(Api::request(42, '', 'materials', [])['status'] === 403, 'Refusal status lost');
        check($cache === $SESSION->local_videoq_native, 'Revocation silently rotated the unexpired session');
        check(JWT::$signed[1]['claims']['sessionId'] === $claims['sessionId'] &&
            JWT::$signed[1]['claims']['sessionExpiresAt'] === $claims['sessionExpiresAt'] &&
            JWT::$signed[1]['claims']['jti'] !== $claims['jti'], 'Stable window and fresh request nonce');
        curl::$status = 200; curl::$body = '{}';
        Api::request(43, '', 'materials', []);
        check(JWT::$signed[2]['claims']['sessionId'] !== $claims['sessionId'], 'Cross-course session reuse');
        local_videoq\native_context::$subject = '8';
        Api::request(42, '', 'materials', []);
        check(JWT::$signed[3]['claims']['sessionId'] !== $claims['sessionId'], 'Cross-user session reuse');
        local_videoq\native_context::$allowed = false;
        $count = count(curl::$requests);
        rejects(fn() => Api::request(42, '', 'materials', []), 'Denied authority signed a request');
        check(count(curl::$requests) === $count, 'Denied authority reached upstream');
        local_videoq\native_context::$allowed = true;
        curl::$mime = 'text/html';
        rejects(fn() => Api::request(42, '', 'materials', []), 'Upstream HTML exposed');
        curl::$mime = 'application/json'; curl::$body = '{broken';
        rejects(fn() => Api::request(42, '', 'materials', []), 'Invalid JSON exposed');
        curl::$body = str_repeat('x', 8388609);
        rejects(fn() => Api::request(42, '', 'materials', []), 'Response size unbounded');
        curl::$mime = 'text/csv'; curl::$body = "count\n1\n";
        check(Api::request(42, '', 'reports', [])['body'] === curl::$body, 'CSV export lost');
        rejects(fn() => Api::request(42, '', 'messages/stream', []), 'Stream accepted through ordinary AJAX');
        rejects(fn() => Api::stream(42, '', [], fn($chunk) => true), 'Expected interrupted stream');
        check(\core\session\manager::$closed, 'Stream lock was not released');
        $SESSION->local_videoq_native = ['bad' => ['id' => 'broken']];
        rejects(fn() => Api::request(42, '', 'materials', []), 'Corrupt live cache silently replaced');
        echo "Native PHP request authority, body binding, immutable sessions, response bounds and stream lock passed.\n";
    } finally {
        unlink($directory . '/filelib.php'); rmdir($directory);
    }
}
