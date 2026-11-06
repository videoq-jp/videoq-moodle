<?php
// Offline handoff contracts. Public identifiers only; no Moodle database or network.
declare(strict_types=1);
namespace local_videoq {
    final class synchronizer {
        public static bool $available = true;
        public static function check_connection(): \stdClass {
            if (!self::$available) {
                throw new \RuntimeException('Connection unavailable');
            }
            return (object)['clientid' => 'registered-client', 'id' => 23];
        }
    }
}
namespace {
    define('MOODLE_INTERNAL', true);
    final class moodle_url {
        public function __construct(public string $url, public array $params = []) {}
    }
    final class curl {
        public static array $metadata = [];
        public static array $requests = [];
        public static int $status = 200;
        public static int $errno = 0;
        public function setHeader(array $headers): void {}
        public function get(string $url, array $body, array $options): string {
            self::$requests[] = [$url, $body, $options];
            return json_encode(self::$metadata, JSON_THROW_ON_ERROR);
        }
        public function get_errno(): int { return self::$errno; }
        public function get_info(): array { return ['http_code' => self::$status]; }
    }
    function get_config(string $component, string $name): string {
        return $name === 'apiurl' ? 'https://api.example:8443/' : 'private-not-for-browser';
    }
    function check(bool $value, string $name): void { if (!$value) throw new RuntimeException($name); }
    function rejects(callable $action): void {
        try { $action(); } catch (Throwable) { return; }
        throw new RuntimeException('Expected rejection');
    }
    $temporary = sys_get_temp_dir() . '/videoq-owner-' . bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    file_put_contents($temporary . '/filelib.php', '<?php');
    $CFG = (object)['libdir' => $temporary, 'wwwroot' => 'https://moodle.example:9443/academy'];
    require dirname(__DIR__) . '/plugins/local/videoq/classes/client.php';
    require dirname(__DIR__) . '/plugins/local/videoq/classes/account_management.php';
    use local_videoq\account_management as Management;
    try {
        curl::$metadata = ['toolOrigin' => 'https://api.example:8443', 'managementUrl' => 'https://web.example:9443/moodle/manage'];
        $url = Management::url();
        check($url->url === 'https://web.example:9443/moodle/manage', 'Separate web origin lost');
        check($url->params === ['issuer' => $CFG->wwwroot, 'clientId' => 'registered-client', 'deploymentId' => '23'],
            'Only the exact public registration tuple may enter the browser');
        [$endpoint, $body, $options] = curl::$requests[0];
        check($endpoint === 'https://api.example:8443/api/lms/pairing/discovery' && $body === [], 'Unapproved discovery request');
        check($options['CURLOPT_FOLLOWLOCATION'] === 0 && $options['CURLOPT_SSL_VERIFYPEER'] === true &&
            $options['CURLOPT_SSL_VERIFYHOST'] === 2, 'Verified transport must remain enabled');
        foreach (['http://web.example/moodle/manage', 'https://user:password@web.example/moodle/manage',
            '//evil.example/moodle/manage', 'https://web.example/other', 'https://web.example/moodle/manage?next=https://evil.example',
            'https://web.example/moodle/manage#fragment', 'https://web.example/moodle/manage/'] as $target) {
            curl::$metadata['managementUrl'] = $target;
            rejects(fn() => Management::url());
        }
        curl::$metadata = ['toolOrigin' => 'https://other.example', 'managementUrl' => 'https://web.example/moodle/manage'];
        rejects(fn() => Management::url());
        curl::$metadata = ['toolOrigin' => 'https://api.example:8443'];
        rejects(fn() => Management::url());
        curl::$metadata['managementUrl'] = 'https://web.example/moodle/manage';
        curl::$status = 500; rejects(fn() => Management::url()); curl::$status = 200;
        curl::$errno = 7; rejects(fn() => Management::url()); curl::$errno = 0;
        curl::$metadata['padding'] = str_repeat('x', 8193); rejects(fn() => Management::url());
        unset(curl::$metadata['padding']);
        \local_videoq\synchronizer::$available = false;
        $count = count(curl::$requests); rejects(fn() => Management::url());
        check(count(curl::$requests) === $count, 'Invalid registration must not initiate discovery');
        echo "Account management handoff contracts passed\n";
    } finally {
        unlink($temporary . '/filelib.php');
        rmdir($temporary);
    }
}
