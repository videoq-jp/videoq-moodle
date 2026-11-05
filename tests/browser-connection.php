<?php
// Offline protocol contracts. No Moodle database, network or third-party runtime.
declare(strict_types=1);
namespace local_videoq {
    final class lti_connection_setup {
        public static array $calls = [];
        public static bool $fail = false;
        public static function connect(string $code, ?string $verifier = null): void {
            self::$calls[] = [connection_setup::decode($code), $verifier];
            if (self::$fail) throw new \RuntimeException('Simulated lost response');
        }
    }
}
namespace {
    define('MOODLE_INTERNAL', true);
    final class context_system { public static function instance(): self { return new self(); } }
    $capable = true;
    function require_capability(string $name, context_system $context): void {
        global $capable;
        if (!$capable) throw new RuntimeException('Permission denied');
    }
    final class curl {
        public static array $metadata = [];
        public static array $requests = [];
        public static int $status = 200;
        public function setHeader(array $headers): void {}
        public function get(string $url, array $body, array $options): string {
            self::$requests[] = [$url, $options];
            return json_encode(self::$metadata, JSON_THROW_ON_ERROR);
        }
        public function get_errno(): int { return 0; }
        public function get_info(): array { return ['http_code' => self::$status]; }
    }
    function check(bool $value, string $name): void { if (!$value) throw new RuntimeException($name); }
    function rejects(callable $action, string $name): void {
        try { $action(); } catch (Throwable) { return; }
        throw new RuntimeException('Expected rejection: ' . $name);
    }
    $temporary = sys_get_temp_dir() . '/videoq-browser-' . bin2hex(random_bytes(8));
    mkdir($temporary, 0700); file_put_contents($temporary . '/filelib.php', '<?php');
    $CFG = (object)['libdir' => $temporary, 'wwwroot' => 'https://moodle.example/academy'];
    $SESSION = new stdClass(); $USER = (object)['id' => 17];
    require dirname(__DIR__) . '/plugins/local/videoq/classes/client.php';
    require dirname(__DIR__) . '/plugins/local/videoq/classes/connection_setup.php';
    require dirname(__DIR__) . '/plugins/local/videoq/classes/browser_connection.php';
    use local_videoq\browser_connection as Connection;
    try {
        foreach (['https://videoq.jp', 'https://private.example:8443'] as $origin) {
            curl::$metadata = ['toolOrigin' => $origin, 'authorizationUrl' => $origin . '/moodle/connect'];
            $location = Connection::start($origin . '/');
            parse_str(parse_url($location, PHP_URL_QUERY), $params);
            $pending = $SESSION->local_videoq_browser;
            check($params['moodle_url'] === $CFG->wwwroot && $params['state'] === $pending['state'], 'Moodle/state binding');
            check($params['code_challenge'] === rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='), 'S256 binding');
            check(!str_contains($location, $pending['verifier']) && strlen($pending['verifier']) === 43, 'Private verifier exposed');
            check(str_starts_with($location, $origin . '/moodle/connect?'), 'Selected host lost');
            [$request, $options] = end(curl::$requests);
            check($request === $origin . '/api/lms/pairing/discovery' && $options['CURLOPT_FOLLOWLOCATION'] === 0 && $options['CURLOPT_SSL_VERIFYPEER'] === true && $options['CURLOPT_SSL_VERIFYHOST'] === 2, 'Transport policy');
            rejects(fn() => Connection::finish(str_repeat('0', 64), str_repeat('a', 64)), 'Wrong state');
            $USER->id = 18;
            rejects(fn() => Connection::finish($pending['state'], str_repeat('a', 64)), 'Different administrator');
            $USER->id = 17;
            $SESSION->local_videoq_browser['expires'] = time() - 1;
            rejects(fn() => Connection::finish($pending['state'], str_repeat('a', 64)), 'Expired request');
            $SESSION->local_videoq_browser = $pending;
            \local_videoq\lti_connection_setup::$fail = true;
            rejects(fn() => Connection::finish($pending['state'], str_repeat('a', 64)), 'Interrupted exchange');
            check(isset($SESSION->local_videoq_browser['code']), 'Cannot recover interrupted exchange without a browser code');
            \local_videoq\lti_connection_setup::$fail = false;
            check(Connection::retry(), 'Exchange retry failed');
            [$code, $proof] = end(\local_videoq\lti_connection_setup::$calls);
            check($code['origin'] === $origin && $code['secret'] === str_repeat('a', 64) && $proof === $pending['verifier'], 'Exchange binding lost');
            rejects(fn() => Connection::finish($pending['state'], str_repeat('a', 64)), 'Replayed callback');
            Connection::start($origin);
            check(!Connection::finish($SESSION->local_videoq_browser['state'], '', 'access_denied') && !isset($SESSION->local_videoq_browser), 'Cancel retained credentials');
        }
        foreach (['http://private.example', 'https://user:password@example.invalid', 'https://example.invalid/path', 'https://example.invalid?query=1'] as $invalid) rejects(fn() => Connection::start($invalid), 'Invalid selected URL');
        curl::$metadata = ['toolOrigin' => 'https://videoq.jp', 'authorizationUrl' => 'https://videoq.jp/moodle/connect'];
        check(str_starts_with(Connection::start('https://VideoQ.jp:443/'), 'https://videoq.jp/moodle/connect?'), 'Default HTTPS port and hostname case');
        curl::$metadata = ['toolOrigin' => 'https://api.private.example:8443', 'authorizationUrl' => 'https://web.private.example:9443/moodle/connect'];
        check(str_starts_with(Connection::start('https://api.private.example:8443'), curl::$metadata['authorizationUrl']), 'Separate frontend/API discovery');
        foreach (['http://example.invalid/moodle/connect', 'https://example.invalid/moodle/connect?redirect=evil', 'https://example.invalid/elsewhere'] as $invalid) {
            curl::$metadata['authorizationUrl'] = $invalid;
            rejects(fn() => Connection::start('https://private.example'), 'Invalid discovered destination');
        }
        curl::$status = 404;
        rejects(fn() => Connection::start('https://old.example'), 'Unsupported server');
        $capable = false;
        rejects(fn() => Connection::start('https://private.example'), 'Administrator required');
        rejects(fn() => Connection::finish(str_repeat('a', 64), str_repeat('b', 64)), 'Callback administrator required');
        echo "Moodle-initiated cloud/on-prem connection, S256, session binding, cancel and recovery contracts passed.\n";
    } finally { unlink($temporary . '/filelib.php'); rmdir($temporary); }
}
